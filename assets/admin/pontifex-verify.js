/*
 * Pontifex admin Verify screen behaviour.
 *
 * The operator selects a backup — clicking a row, which is then outlined; there
 * are no radios — and clicks Verify. One background request reads and hash-checks
 * the chosen backup while this script polls a second endpoint for progress, then
 * shows the sound-or-broken verdict. This is the same select-then-act pattern as
 * the Restore screen. No build step — plain browser JavaScript, shipped as-is.
 * Configuration (the ajax URL, the nonce, and the translated strings) arrives on
 * window.pontifexVerify via wp_localize_script; every request carries the nonce,
 * and the server re-checks the capability and nonce on each action.
 */
( function () {
	'use strict';

	var cfg = window.pontifexVerify;
	if ( ! cfg ) {
		return;
	}

	/**
	 * POST an admin-ajax action with the nonce and optional extra fields.
	 *
	 * @param {string} action The wp_ajax action name.
	 * @param {Object} extra  Optional extra form fields.
	 * @return {Promise<Object>} The decoded JSON response.
	 */
	function request( action, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_wpnonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				body.append( key, extra[ key ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			// Parse explicitly rather than response.json(): a PHP fatal answers
			// with an HTML page, which must reject cleanly instead of surfacing
			// as an opaque JSON syntax error.
			return response.text().then( function ( text ) {
				return JSON.parse( text );
			} );
		} );
	}

	/**
	 * Set the text content of an element by id, if it is present.
	 *
	 * @param {string} id   The element id.
	 * @param {string} text The text to set.
	 */
	function setText( id, text ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.textContent = text;
		}
	}

	/**
	 * Show or hide the determinate progress bar.
	 *
	 * @param {boolean} visible Whether the bar should be shown.
	 */
	function showBar( visible ) {
		var track = document.getElementById( 'pontifex-verify-track' );
		if ( track ) {
			track.hidden = ! visible;
		}
	}

	/**
	 * Fill the progress bar to the fraction of bytes verified so far.
	 *
	 * @param {number} done  Bytes verified so far.
	 * @param {number} total Total bytes to verify.
	 */
	function setBar( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var fill = document.getElementById( 'pontifex-verify-bar' );
		var track = document.getElementById( 'pontifex-verify-track' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( track ) {
			track.setAttribute( 'aria-valuenow', String( pct ) );
		}
	}

	/**
	 * Format a duration in seconds as M:SS, or H:MM:SS past an hour.
	 *
	 * @param {number} seconds Elapsed or remaining seconds.
	 * @return {string} The formatted duration.
	 */
	function fmtDuration( seconds ) {
		var s = Math.max( 0, Math.round( seconds ) );
		var h = Math.floor( s / 3600 );
		var m = Math.floor( ( s % 3600 ) / 60 );
		var sec = s % 60;
		var pad = function ( n ) {
			return n < 10 ? '0' + n : String( n );
		};
		if ( h > 0 ) {
			return h + ':' + pad( m ) + ':' + pad( sec );
		}
		return m + ':' + pad( sec );
	}

	/**
	 * Format a byte count as a human-readable size (e.g. 236 MB).
	 *
	 * @param {number} bytes A non-negative byte count.
	 * @return {string} The formatted size.
	 */
	function formatBytes( bytes ) {
		var n = Math.max( 0, bytes );
		if ( n < 1024 ) {
			return n + ' B';
		}
		var units = [ 'KB', 'MB', 'GB', 'TB' ];
		var u = -1;
		do {
			n /= 1024;
			u++;
		} while ( n >= 1024 && u < units.length - 1 );
		return ( n < 10 ? n.toFixed( 1 ) : Math.round( n ) ) + ' ' + units[ u ];
	}

	/**
	 * The proof panel element, if the page has one.
	 *
	 * @return {?Element} The proof panel, or null.
	 */
	function proofEl() {
		return document.getElementById( 'pontifex-verify-proof' );
	}

	/**
	 * Empty and hide the proof panel.
	 */
	function clearProof() {
		var panel = proofEl();
		if ( ! panel ) {
			return;
		}
		while ( panel.firstChild ) {
			panel.removeChild( panel.firstChild );
		}
		panel.hidden = true;
	}

	/**
	 * Build the panel's verdict line.
	 *
	 * Styled identically whether the verdict is sound or broken — the words carry
	 * the result, never a status colour (design language: restraint over alarm).
	 *
	 * @param {string} text The verdict sentence.
	 * @return {Element} The verdict paragraph.
	 */
	function verdictLine( text ) {
		var p = document.createElement( 'p' );
		p.className = 'pontifex-proof-verdict';
		p.textContent = text;
		return p;
	}

	/**
	 * Append one label/value pair to a facts definition list.
	 *
	 * @param {Element} dl    The <dl> to append to.
	 * @param {string}  label The fact's label.
	 * @param {string}  value The fact's value.
	 */
	function fact( dl, label, value ) {
		var dt = document.createElement( 'dt' );
		dt.className = 'pontifex-proof-label';
		dt.textContent = label;
		var dd = document.createElement( 'dd' );
		dd.className = 'pontifex-proof-value';
		dd.textContent = value;
		dl.appendChild( dt );
		dl.appendChild( dd );
	}

	/**
	 * Render the persistent proof panel for a sound verify.
	 *
	 * Built with createElement/textContent throughout, never innerHTML, since the
	 * facts (in particular the scope and formatted date) are server-supplied text.
	 *
	 * @param {Object} proof The `proof` payload from a sound verify response.
	 */
	function renderProof( proof ) {
		var panel = proofEl();
		if ( ! panel || ! proof ) {
			return;
		}
		clearProof();

		panel.appendChild( verdictLine( cfg.strings.verdictIntact ) );

		var facts = document.createElement( 'dl' );
		facts.className = 'pontifex-proof-facts';
		fact( facts, cfg.strings.factEntries, String( proof.entries ) );
		fact( facts, cfg.strings.factSize, proof.size );
		fact( facts, cfg.strings.factContains, proof.scope );
		fact( facts, cfg.strings.factCreated, proof.created );
		fact( facts, cfg.strings.factFormat, proof.format );
		panel.appendChild( facts );

		var hashes = document.createElement( 'p' );
		hashes.className = 'pontifex-proof-assurance';
		hashes.textContent = cfg.strings.assuranceHashes;
		panel.appendChild( hashes );

		var documented = document.createElement( 'p' );
		documented.className = 'pontifex-proof-assurance';
		documented.appendChild( document.createTextNode( cfg.strings.assuranceDocumented + ' ' ) );
		var link = document.createElement( 'a' );
		link.className = 'pontifex-link pontifex-proof-link';
		link.href = cfg.specUrl;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.textContent = cfg.strings.specLinkText;
		documented.appendChild( link );
		panel.appendChild( documented );

		panel.hidden = false;
	}

	/**
	 * Render the panel for a broken verify — the verdict plus the server's
	 * message, with no facts grid (there is nothing sound to report).
	 *
	 * @param {string} message The broken verdict's message from the server.
	 */
	function renderBroken( message ) {
		var panel = proofEl();
		if ( ! panel ) {
			return;
		}
		clearProof();

		panel.appendChild( verdictLine( cfg.strings.verdictBroken ) );

		if ( message ) {
			var detail = document.createElement( 'p' );
			detail.className = 'pontifex-proof-assurance';
			detail.textContent = message;
			panel.appendChild( detail );
		}

		panel.hidden = false;
	}

	/**
	 * The filename of the selected backup, or null when none is selected.
	 *
	 * @return {?string} The selected backup filename, or null.
	 */
	function selectedFile() {
		var selected = document.querySelector( '.pontifex-restore-row.is-selected' );
		return selected ? selected.getAttribute( 'data-file' ) : null;
	}

	/**
	 * Select one backup row, outline it, and clear the others.
	 *
	 * Also maintains the radiogroup's roving tabindex: the selected row is the
	 * group's single Tab stop, so Tab lands on the choice and the arrow keys
	 * (below) move within the group — the ARIA radio-group keyboard contract
	 * the role attributes promise.
	 *
	 * @param {Element} chosen The row button to select.
	 */
	function selectRow( chosen ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-restore-row' ),
			function ( row ) {
				var on = row === chosen;
				row.classList.toggle( 'is-selected', on );
				row.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				row.setAttribute( 'tabindex', on ? '0' : '-1' );
			}
		);
		clearProof();
	}

	/**
	 * Move the radio selection with the arrow keys, wrapping at the ends.
	 *
	 * Per the ARIA radio-group pattern, selection follows focus: pressing an
	 * arrow both focuses and selects the next row.
	 *
	 * @param {KeyboardEvent} event The keydown event on a row.
	 * @param {Element}       row   The row the event fired on.
	 */
	function handleRowKey( event, row ) {
		var forward = 'ArrowDown' === event.key || 'ArrowRight' === event.key;
		var backward = 'ArrowUp' === event.key || 'ArrowLeft' === event.key;
		if ( ( ! forward && ! backward ) || row.disabled ) {
			return;
		}
		event.preventDefault();
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.pontifex-restore-row' ) );
		var index = rows.indexOf( row );
		var next = rows[ ( index + ( forward ? 1 : rows.length - 1 ) ) % rows.length ];
		selectRow( next );
		next.focus();
		updateRunButton();
	}

	/**
	 * Enable the Verify button only when a backup is selected.
	 */
	function updateRunButton() {
		var run = document.getElementById( 'pontifex-verify-run' );
		if ( run ) {
			run.disabled = null === selectedFile();
		}
	}

	/**
	 * Enable or disable the row buttons and the Verify button while one runs.
	 *
	 * @param {boolean} enabled Whether the controls should be usable.
	 */
	function setControlsEnabled( enabled ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-restore-row' ),
			function ( row ) {
				row.disabled = ! enabled;
			}
		);
		if ( enabled ) {
			updateRunButton();
		} else {
			var run = document.getElementById( 'pontifex-verify-run' );
			if ( run ) {
				run.disabled = true;
			}
		}
	}

	/**
	 * Verify the selected backup: poll for progress while the request completes.
	 */
	function startVerify() {
		var file = selectedFile();
		if ( null === file ) {
			return;
		}

		setControlsEnabled( false );
		setText( 'pontifex-verify-result', '' );
		clearProof();
		setText( 'pontifex-verify-progress', cfg.strings.starting );
		setText( 'pontifex-verify-timing', '' );
		showBar( true );
		setBar( 0, 1 );

		var startedAt = Date.now();
		var poll = window.setInterval( function () {
			request( 'pontifex_verify_progress' ).then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || 'idle' === res.data.phase ) {
					return;
				}
				var data = res.data;
				var elapsed = ( Date.now() - startedAt ) / 1000;
				setText( 'pontifex-verify-timing', cfg.strings.elapsed.replace( '%s', fmtDuration( elapsed ) ) );
				if ( data.bytes_total > 0 ) {
					setBar( data.bytes_done, data.bytes_total );
					setText(
						'pontifex-verify-progress',
						cfg.strings.progress.replace( '%1$s', formatBytes( data.bytes_done ) ).replace( '%2$s', formatBytes( data.bytes_total ) )
					);
				}
			} ).catch( function () {} );
		}, 1000 );

		request( 'pontifex_verify', { file: file } ).then( function ( res ) {
			window.clearInterval( poll );
			finishVerify( res );
		} ).catch( function () {
			window.clearInterval( poll );
			finishVerify( null );
		} );
	}

	/**
	 * Reset the controls that only matter while a verification is running.
	 *
	 * Shared by every outcome — sound, broken, or a refusal — so a verification
	 * never leaves the bar, timing, or disabled controls behind.
	 */
	function resetVerifyControls() {
		showBar( false );
		setText( 'pontifex-verify-progress', '' );
		setText( 'pontifex-verify-timing', '' );
		setControlsEnabled( true );
	}

	/**
	 * Reset the controls after a verification and show its verdict.
	 *
	 * A sound or broken verdict renders the persistent proof panel; a refusal
	 * (an encrypted backup, an unresolved file, a concurrent run, a lost
	 * connection) has no proof to show, so it falls back to the plain result
	 * line the server already phrased, unchanged from before the proof panel.
	 *
	 * @param {?Object} res The decoded JSON response, or null on a network failure.
	 */
	function finishVerify( res ) {
		resetVerifyControls();

		var data = res && res.data ? res.data : null;

		if ( res && res.success && data && true === data.sound ) {
			setText( 'pontifex-verify-result', '' );
			renderProof( data.proof );
			return;
		}

		if ( res && res.success && data && false === data.sound ) {
			setText( 'pontifex-verify-result', '' );
			renderBroken( data.message );
			return;
		}

		setText( 'pontifex-verify-result', ( data && data.message ) ? data.message : cfg.strings.failed );
	}

	/**
	 * Wire the backup row buttons and the Verify button.
	 */
	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-restore-row' ),
			function ( row ) {
				row.addEventListener( 'click', function () {
					if ( ! row.disabled ) {
						selectRow( row );
						updateRunButton();
					}
				} );
				row.addEventListener( 'keydown', function ( event ) {
					handleRowKey( event, row );
				} );
			}
		);

		var run = document.getElementById( 'pontifex-verify-run' );
		if ( run ) {
			run.addEventListener( 'click', startVerify );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
