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
	 * Stash a one-time notice to show after the page reloads.
	 *
	 * @param {string} message The notice to show on the next load.
	 */
	function storeNotice( message ) {
		try {
			window.sessionStorage.setItem( 'pontifexBackupNotice', message );
		} catch ( e ) {}
	}

	/**
	 * Show and clear a notice stashed before a reload, if any.
	 */
	function showStoredNotice() {
		var message = null;
		try {
			message = window.sessionStorage.getItem( 'pontifexBackupNotice' );
			window.sessionStorage.removeItem( 'pontifexBackupNotice' );
		} catch ( e ) {}
		if ( message ) {
			setText( 'pontifex-backup-result', message );
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
			// An indeterminate progressbar carries NO aria-valuenow; leaving the
			// last percentage in place reads as "stuck at N%" to a screen reader.
			// setBar restores the value once the phase turns determinate again.
			if ( on ) {
				track.removeAttribute( 'aria-valuenow' );
			}
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
				var created = cfg.strings.createdPlain;
				if ( res.data && res.data.bytes && res.data.source_bytes ) {
					created = cfg.strings.created
						.replace( '%1$s', formatBytes( res.data.bytes ) )
						.replace( '%2$s', formatBytes( res.data.source_bytes ) );
				}
				// Reloading while the browser reports itself offline would land on
				// the browser's own error page and destroy this screen — and the
				// notice with it. Show the verdict inline instead; the operator can
				// reload once the connection is back to see the new backup listed.
				if ( false === navigator.onLine ) {
					resetIdle( created );
					return;
				}
				storeNotice( created );
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
			if ( ! window.confirm( cfg.strings.confirmCancel ) ) {
				return;
			}
			button.disabled = true;
			button.textContent = cfg.strings.cancelling;
			request( 'pontifex_cancel_backup' ).catch( function () {} );
		} );
	}

	/**
	 * Bind the schedule Save button to persist the periodic-backup settings.
	 *
	 * Reads the form's four fields, posts them to the save-schedule action, and
	 * shows the verdict — the next run time when the schedule is on, a plain
	 * confirmation when it is off — in the section's own notice line. The server
	 * re-validates every field; this only reflects its answer.
	 *
	 * @param {HTMLButtonElement} button The schedule Save button.
	 */
	function bindScheduleSave( button ) {
		button.addEventListener( 'click', function () {
			var enabled = document.getElementById( 'pontifex-schedule-enabled' );
			var frequency = document.getElementById( 'pontifex-schedule-frequency' );
			var hour = document.getElementById( 'pontifex-schedule-hour' );
			var retention = document.getElementById( 'pontifex-schedule-retention' );
			button.disabled = true;
			setText( 'pontifex-schedule-result', '' );
			request( 'pontifex_save_schedule', {
				enabled: enabled && enabled.checked ? '1' : '0',
				frequency: frequency ? frequency.value : '',
				hour: hour ? hour.value : '',
				retention: retention ? retention.value : ''
			} ).then( function ( res ) {
				button.disabled = false;
				if ( res && res.success && res.data ) {
					if ( res.data.enabled && res.data.next_run ) {
						setText( 'pontifex-schedule-result', cfg.strings.scheduleSaved.replace( '%s', res.data.next_run ) );
					} else {
						setText( 'pontifex-schedule-result', cfg.strings.scheduleSavedOff );
					}
					return;
				}
				setText( 'pontifex-schedule-result', ( res && res.data && res.data.message ) ? res.data.message : cfg.strings.scheduleFailed );
			} ).catch( function () {
				button.disabled = false;
				setText( 'pontifex-schedule-result', cfg.strings.scheduleFailed );
			} );
		} );
	}

	/**
	 * Re-attach to a backup already running server-side.
	 *
	 * The job a backup runs as is persisted (ADR 0014), so a reloaded page —
	 * or one opened after the starting tab was closed — can ask the progress
	 * endpoint, discover the live backup, and re-enter the running state
	 * instead of showing an idle screen while work continues underneath.
	 */
	function reattachIfRunning() {
		request( 'pontifex_backup_progress' ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || 'idle' === res.data.phase ) {
				return;
			}
			swapRunning( true );
			setDeleteEnabled( false );
			showBar( true );
			setIndeterminate( 'copying' !== res.data.phase );
			setText( 'pontifex-backup-result', '' );
			setText( 'pontifex-backup-progress', cfg.strings.reattached );

			var poll = window.setInterval( function () {
				request( 'pontifex_backup_progress' ).then( function ( r ) {
					if ( ! r || ! r.success || ! r.data ) {
						return;
					}
					if ( 'idle' === r.data.phase ) {
						window.clearInterval( poll );
						storeNotice( cfg.strings.finishedElsewhere );
						if ( false === navigator.onLine ) {
							resetIdle( cfg.strings.finishedElsewhere );
							return;
						}
						window.location.reload();
						return;
					}
					if ( r.data.bytes_total > 0 ) {
						setIndeterminate( false );
						setBar( r.data.bytes_done, r.data.bytes_total );
						setText(
							'pontifex-backup-progress',
							cfg.strings.progress.replace( '%1$s', formatBytes( r.data.bytes_done ) ).replace( '%2$s', formatBytes( r.data.bytes_total ) )
						);
					}
				} ).catch( function () {} );
			}, 1000 );
		} ).catch( function () {} );
	}

	/**
	 * Wire the create, cancel, and delete buttons present on the page.
	 */
	function init() {
		showStoredNotice();
		reattachIfRunning();
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
		var scheduleSave = document.getElementById( 'pontifex-schedule-save' );
		if ( scheduleSave ) {
			bindScheduleSave( scheduleSave );
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
