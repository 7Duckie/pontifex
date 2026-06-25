/*
 * Pontifex admin Backup screen behaviour.
 *
 * Drives the "Create backup" button and the per-backup delete actions over
 * admin-ajax: one background request runs the export while this script polls a
 * second endpoint for progress, then reloads to reveal the finished backup. No
 * build step — plain browser JavaScript, shipped as-is. Configuration (the ajax
 * URL, the nonce, and the translated strings) arrives on window.pontifexBackup
 * via wp_localize_script; every request carries the nonce, and the server
 * re-checks the capability and nonce on each action.
 */
( function () {
	'use strict';

	var cfg = window.pontifexBackup;
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
		var track = document.getElementById( 'pontifex-backup-track' );
		if ( track ) {
			track.hidden = ! visible;
		}
	}

	/**
	 * Fill the progress bar to the fraction of entries written so far.
	 *
	 * @param {number} done  Entries written so far.
	 * @param {number} total Total entries to write.
	 */
	function setBar( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var fill = document.getElementById( 'pontifex-backup-bar' );
		var track = document.getElementById( 'pontifex-backup-track' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( track ) {
			track.setAttribute( 'aria-valuenow', String( pct ) );
		}
	}

	/**
	 * Toggle the indeterminate (scanning) animation on the bar.
	 *
	 * @param {boolean} on Whether the scanning animation should run.
	 */
	function setIndeterminate( on ) {
		var track = document.getElementById( 'pontifex-backup-track' );
		if ( track ) {
			track.classList.toggle( 'is-indeterminate', on );
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
	 * Enable or disable every delete button, so none can be pressed mid-backup.
	 *
	 * @param {boolean} enabled Whether the delete buttons should be clickable.
	 */
	function setDeleteEnabled( enabled ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-delete-backup' ),
			function ( btn ) {
				btn.disabled = ! enabled;
			}
		);
	}

	/**
	 * Swap the Create and Cancel buttons for the running or idle state.
	 *
	 * While a backup runs, Cancel takes Create's place (the one filled element on
	 * the page); when idle, Create is shown and Cancel hidden and reset.
	 *
	 * @param {boolean} running Whether a backup is running.
	 */
	function swapRunning( running ) {
		var create = document.getElementById( 'pontifex-create-backup' );
		var cancel = document.getElementById( 'pontifex-cancel-backup' );
		if ( create ) {
			create.hidden = running;
		}
		if ( cancel ) {
			cancel.hidden = ! running;
			cancel.disabled = false;
			cancel.textContent = cfg.strings.cancel;
		}
	}

	/**
	 * Return the create UI to its idle state, showing a message.
	 *
	 * Used after a failed, refused, or cancelled backup.
	 *
	 * @param {string} message The notice to show.
	 */
	function resetIdle( message ) {
		swapRunning( false );
		setDeleteEnabled( true );
		showBar( false );
		setIndeterminate( false );
		setText( 'pontifex-backup-progress', '' );
		setText( 'pontifex-backup-timing', '' );
		setText( 'pontifex-backup-result', message );
	}

	/**
	 * Run a backup: poll for progress while the create request completes.
	 */
	function startCreate() {
		swapRunning( true );
		setDeleteEnabled( false );
		setText( 'pontifex-backup-result', '' );
		setText( 'pontifex-backup-progress', cfg.strings.starting );
		setText( 'pontifex-backup-timing', '' );
		showBar( true );
		setIndeterminate( true );

		var startedAt = Date.now();
		var seenCopying = false;
		var lastBytes = 0;
		var samples = [];

		var poll = window.setInterval( function () {
			request( 'pontifex_backup_progress' ).then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || 'idle' === res.data.phase ) {
					return;
				}
				var data = res.data;
				var elapsed = ( Date.now() - startedAt ) / 1000;

				if ( 'copying' !== data.phase && ! seenCopying ) {
					// Scanning phase: total unknown, so a sliding bar plus the climbing count.
					setIndeterminate( true );
					if ( data.done > 0 ) {
						setText( 'pontifex-backup-progress', cfg.strings.scanning.replace( '%s', data.done ) );
					}
					setText( 'pontifex-backup-timing', cfg.strings.elapsed.replace( '%s', fmtDuration( elapsed ) ) );
					return;
				}

				// Copying phase: determinate bar driven by bytes, so it advances through a
				// single large file and not only at file boundaries; it never runs backwards,
				// with elapsed and an estimate of time left. Once seen, never revert to scanning.
				seenCopying = true;
				var bytesDone = Math.max( data.bytes_done, lastBytes );
				lastBytes = bytesDone;
				var bytesTotal = data.bytes_total;
				setIndeterminate( false );
				setBar( bytesDone, bytesTotal );
				setText(
					'pontifex-backup-progress',
					cfg.strings.progress.replace( '%1$s', formatBytes( bytesDone ) ).replace( '%2$s', formatBytes( bytesTotal ) )
				);

				// Estimate time left from a recent-rate window (last ~5s) over bytes, not the
				// average since the start: with large early files a since-start rate reads in
				// hours then collapses. Withhold the estimate until enough has copied (10%)
				// that the rate is steady, showing only elapsed until then.
				samples.push( { t: Date.now(), bytes: bytesDone } );
				while ( samples.length > 1 && ( samples[ samples.length - 1 ].t - samples[ 0 ].t ) > 5000 ) {
					samples.shift();
				}
				var span = ( samples[ samples.length - 1 ].t - samples[ 0 ].t ) / 1000;
				var rate = span > 0 ? ( samples[ samples.length - 1 ].bytes - samples[ 0 ].bytes ) / span : 0;
				if ( rate > 0 && bytesTotal > 0 && bytesDone >= bytesTotal * 0.1 && bytesDone < bytesTotal ) {
					var remaining = ( bytesTotal - bytesDone ) / rate;
					setText(
						'pontifex-backup-timing',
						cfg.strings.timing
							.replace( '%1$s', fmtDuration( elapsed ) )
							.replace( '%2$s', fmtDuration( remaining ) )
					);
				} else {
					setText( 'pontifex-backup-timing', cfg.strings.elapsed.replace( '%s', fmtDuration( elapsed ) ) );
				}
			} ).catch( function () {} );
		}, 1000 );

		request( 'pontifex_create_backup' ).then( function ( res ) {
			window.clearInterval( poll );
			if ( res && res.success && res.data && res.data.cancelled ) {
				resetIdle( cfg.strings.cancelled );
				return;
			}
			if ( res && res.success ) {
				setIndeterminate( false );
				setBar( 1, 1 );
				window.location.reload();
				return;
			}
			resetIdle( ( res && res.data && res.data.message ) ? res.data.message : cfg.strings.failed );
		} ).catch( function () {
			window.clearInterval( poll );
			resetIdle( cfg.strings.failed );
		} );
	}

	/**
	 * Bind a delete button to confirm, then remove its backup.
	 *
	 * @param {HTMLButtonElement} button The delete button, carrying data-file.
	 */
	function bindDelete( button ) {
		button.addEventListener( 'click', function () {
			if ( ! window.confirm( cfg.strings.confirmDelete ) ) {
				return;
			}
			button.disabled = true;
			request( 'pontifex_delete_backup', { file: button.getAttribute( 'data-file' ) } ).then( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
					return;
				}
				button.disabled = false;
			} ).catch( function () {
				button.disabled = false;
			} );
		} );
	}

	/**
	 * Bind the Cancel button to request a stop of the running backup.
	 *
	 * The pending create request resolves with a cancelled response once the
	 * export has stopped; that is what resets the UI, so this only signals the
	 * request and reflects the "cancelling" state.
	 *
	 * @param {HTMLButtonElement} button The cancel button.
	 */
	function bindCancel( button ) {
		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = cfg.strings.cancelling;
			request( 'pontifex_cancel_backup' ).catch( function () {} );
		} );
	}

	/**
	 * Wire the create, cancel, and delete buttons present on the page.
	 */
	function init() {
		var create = document.getElementById( 'pontifex-create-backup' );
		if ( create ) {
			create.addEventListener( 'click', function () {
				startCreate();
			} );
		}
		var cancel = document.getElementById( 'pontifex-cancel-backup' );
		if ( cancel ) {
			bindCancel( cancel );
		}
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-delete-backup' ),
			bindDelete
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
