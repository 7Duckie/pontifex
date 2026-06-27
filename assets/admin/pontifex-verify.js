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
			return response.json();
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
	 * @param {Element} chosen The row button to select.
	 */
	function selectRow( chosen ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-restore-row' ),
			function ( row ) {
				var on = row === chosen;
				row.classList.toggle( 'is-selected', on );
				row.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			}
		);
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
			finishVerify( ( res && res.data && res.data.message ) ? res.data.message : cfg.strings.failed );
		} ).catch( function () {
			window.clearInterval( poll );
			finishVerify( cfg.strings.failed );
		} );
	}

	/**
	 * Reset the controls after a verification and show its verdict.
	 *
	 * The verdict — sound, broken, or a refusal such as an encrypted backup — all
	 * arrive as a message the server already phrased, so it is shown verbatim.
	 *
	 * @param {string} message The verdict or error message to show.
	 */
	function finishVerify( message ) {
		showBar( false );
		setText( 'pontifex-verify-progress', '' );
		setText( 'pontifex-verify-timing', '' );
		setControlsEnabled( true );
		setText( 'pontifex-verify-result', message );
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
