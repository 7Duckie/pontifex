/*
 * Pontifex admin Restore screen behaviour.
 *
 * Drives the typed-action box over admin-ajax: the operator selects a backup and
 * types an action (`restore` or `rollback`); the Run button stays disabled until
 * the typed word is a valid action and its precondition holds (a selection, for
 * restore). One background request runs the operation while this script polls a
 * second endpoint for byte progress, labelling each phase (verifying, backing up,
 * restoring), then shows the verdict the server phrased. No build step — plain
 * browser JavaScript. Configuration (the ajax URL, the nonce, and the translated
 * strings) arrives on window.pontifexRestore via wp_localize_script; every
 * request carries the nonce, and the server re-checks the capability and nonce on
 * each action.
 */
( function () {
	'use strict';

	var cfg = window.pontifexRestore;
	if ( ! cfg ) {
		return;
	}

	var ACTIONS = [ 'restore', 'rollback' ];

	/**
	 * POST an admin-ajax action with the nonce and optional extra fields.
	 *
	 * A rejected promise carries a `pontifexReason` telling the failure shapes
	 * apart, which matters on this screen: 'auth' means admin-ajax's bare "0" or
	 * "-1" reply (the session is gone), 'server' means WordPress answered but
	 * with some other non-JSON body (a PHP fatal's HTML page), and 'network'
	 * means no response arrived at all (a severed connection). A plain
	 * response.json() would collapse all three into one indistinguishable
	 * rejection.
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
		} ).then(
			function ( response ) {
				return response.text().then( function ( text ) {
					try {
						return JSON.parse( text );
					} catch ( e ) {
						var trimmed = text.trim();
						var reason = ( '0' === trimmed || '-1' === trimmed ) ? 'auth' : 'server';
						var parseError = new Error( 'pontifex: non-JSON response' );
						parseError.pontifexReason = reason;
						throw parseError;
					}
				} );
			},
			function () {
				var networkError = new Error( 'pontifex: no response' );
				networkError.pontifexReason = 'network';
				throw networkError;
			}
		);
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
		var track = document.getElementById( 'pontifex-restore-track' );
		if ( track ) {
			track.hidden = ! visible;
		}
	}

	/**
	 * Toggle the indeterminate (scanning) animation on the bar.
	 *
	 * @param {boolean} on Whether the scanning animation should run.
	 */
	function setIndeterminate( on ) {
		var track = document.getElementById( 'pontifex-restore-track' );
		var fill = document.getElementById( 'pontifex-restore-bar' );
		if ( track ) {
			track.classList.toggle( 'is-indeterminate', on );
			// An indeterminate progressbar carries NO aria-valuenow; leaving the
			// last percentage in place reads as "stuck at N%" to a screen reader.
			// setBar restores the value once the phase turns determinate again.
			if ( on ) {
				track.removeAttribute( 'aria-valuenow' );
			}
		}
		// Clear any inline width so the CSS sliding animation (width: 40%) shows;
		// an inline width: 0% left by setBar would otherwise leave the bar blank.
		if ( on && fill ) {
			fill.style.width = '';
		}
	}

	/**
	 * Fill the progress bar to the fraction of the current phase done so far.
	 *
	 * @param {number} done  Bytes done in the phase.
	 * @param {number} total Total bytes in the phase.
	 */
	function setBar( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var fill = document.getElementById( 'pontifex-restore-bar' );
		var track = document.getElementById( 'pontifex-restore-track' );
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
	 * Translate a server progress phase into its display label.
	 *
	 * @param {string} phase The phase reported by the progress endpoint.
	 * @return {string} The label to show, or '' for an unknown phase.
	 */
	function phaseLabel( phase ) {
		if ( 'verifying' === phase ) {
			return cfg.strings.verifying;
		}
		if ( 'backing_up' === phase ) {
			return cfg.strings.backingUp;
		}
		if ( 'restoring' === phase ) {
			return cfg.strings.restoring;
		}
		if ( 'rolling_back' === phase ) {
			return cfg.strings.rollingBack;
		}
		return '';
	}

	/**
	 * The action currently typed, normalised to lower case and trimmed.
	 *
	 * @return {string} The normalised action word.
	 */
	function currentAction() {
		var input = document.getElementById( 'pontifex-restore-action' );
		return input ? input.value.trim().toLowerCase() : '';
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
	 * Whether the "relink this backup to this site" box is ticked.
	 *
	 * @return {boolean} True when the restore should rewrite the backup's links here.
	 */
	function migrateChecked() {
		var box = document.getElementById( 'pontifex-restore-migrate' );
		return !! ( box && box.checked );
	}

	/**
	 * Whether the given action can run now (valid word, plus a selection for restore).
	 *
	 * @param {string} action The normalised action word.
	 * @return {boolean} True when the action is runnable.
	 */
	function isRunnable( action ) {
		if ( ACTIONS.indexOf( action ) === -1 ) {
			return false;
		}
		if ( 'restore' === action && null === selectedFile() ) {
			return false;
		}
		return true;
	}

	/**
	 * Enable or disable the Run button to match the typed action and selection.
	 */
	function updateRunButton() {
		var run = document.getElementById( 'pontifex-restore-run' );
		if ( run ) {
			run.disabled = ! isRunnable( currentAction() );
		}
	}

	var migratePreviewToken = 0;

	/**
	 * Toggle the "this backup is from another site" hint for the selected backup.
	 *
	 * Read-only: asks the server whether the chosen backup records a source URL
	 * unlike this site and, if so, reveals the advisory hint by the rewrite-links
	 * checkbox. It never ticks the box. Fail-soft — any error, or a backup from
	 * this same site, simply leaves the hint hidden. A token guards against a
	 * slow answer for a previously-selected backup overwriting a newer choice.
	 *
	 * @param {?string} file The selected backup filename, or null for none.
	 */
	function previewMigration( file ) {
		var hint = document.getElementById( 'pontifex-restore-migrate-hint' );
		if ( hint ) {
			hint.hidden = true;
		}
		if ( ! file || ! hint ) {
			return;
		}
		var token = ++migratePreviewToken;
		request( 'pontifex_restore_preview', { file: file } ).then( function ( res ) {
			if ( token !== migratePreviewToken ) {
				return;
			}
			if ( res && res.success && res.data && res.data.migration ) {
				hint.hidden = false;
			}
		} ).catch( function () {
			// Fail-soft: a preview that cannot be fetched simply shows no hint.
		} );
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
		previewMigration( chosen ? chosen.getAttribute( 'data-file' ) : null );
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
	 * Enable or disable the action controls while an operation runs.
	 *
	 * @param {boolean} enabled Whether the controls should be usable.
	 */
	function setControlsEnabled( enabled ) {
		var input = document.getElementById( 'pontifex-restore-action' );
		if ( input ) {
			input.disabled = ! enabled;
		}
		Array.prototype.forEach.call(
			document.querySelectorAll( '.pontifex-restore-row' ),
			function ( row ) {
				row.disabled = ! enabled;
			}
		);
		if ( enabled ) {
			updateRunButton();
		} else {
			var run = document.getElementById( 'pontifex-restore-run' );
			if ( run ) {
				run.disabled = true;
			}
		}
	}

	/**
	 * Run one restore or rollback: poll for progress while the request completes.
	 *
	 * @param {string} action The validated action word, `restore` or `rollback`.
	 */
	function startRun( action ) {
		var ajaxAction = 'restore' === action ? 'pontifex_restore' : 'pontifex_rollback';
		var extra = 'restore' === action ? { file: selectedFile(), migrate: migrateChecked() ? '1' : '' } : null;

		setControlsEnabled( false );
		setText( 'pontifex-restore-result', '' );
		setText( 'pontifex-restore-progress', cfg.strings.starting );
		setText( 'pontifex-restore-timing', '' );
		showBar( true );
		setIndeterminate( false );
		setBar( 0, 1 );

		// A restore replaces the users and sessions tables, so once it reaches that
		// stage the progress poll can no longer read an authenticated answer and the
		// main request below freezes until the destination server times it out. These
		// two guards let the poll declare the terminal state itself rather than
		// leaving the bar stuck: `settled` stops the poll-driven and main-request
		// completion paths from both firing, and `authMisses` requires two
		// consecutive dead-session polls so a single fluke does not trip it early.
		var settled = false;
		var authMisses = 0;

		var startedAt = Date.now();
		var poll = window.setInterval( function () {
			request( 'pontifex_restore_progress' ).then( function ( res ) {
				authMisses = 0;
				if ( ! res || ! res.success || ! res.data || 'idle' === res.data.phase ) {
					return;
				}
				var data = res.data;
				var elapsed = ( Date.now() - startedAt ) / 1000;
				setText( 'pontifex-restore-timing', cfg.strings.elapsed.replace( '%s', fmtDuration( elapsed ) ) );

				var label = phaseLabel( data.phase );
				if ( data.bytes_total > 0 ) {
					// A known total (verifying, restoring): a determinate bar.
					setIndeterminate( false );
					setBar( data.bytes_done, data.bytes_total );
					setText(
						'pontifex-restore-progress',
						label + ' ' + cfg.strings.progress.replace( '%1$s', formatBytes( data.bytes_done ) ).replace( '%2$s', formatBytes( data.bytes_total ) )
					);
				} else {
					// No total yet — the safety-archive backup walks the filesystem before
					// the byte total is known. The sliding animation keeps the bar alive;
					// setBar is deliberately NOT called here, so an inline width: 0% does not
					// override the animation and leave the bar blank during the scan.
					setIndeterminate( true );
					setText( 'pontifex-restore-progress', label );
				}
			} ).catch( function ( err ) {
				if ( err && 'auth' === err.pontifexReason ) {
					authMisses++;
					// Two consecutive dead-session polls: the restore has reached the
					// users-table swap and reset this session. Declare the terminal state
					// once, early — do not wait on the frozen main request.
					if ( authMisses >= 2 && ! settled ) {
						settled = true;
						window.clearInterval( poll );
						finishSessionReset();
					}
					return;
				}
				// A transient 'server'/'network' blip: ignore and keep polling.
			} );
		}, 1000 );

		request( ajaxAction, extra ).then( function ( res ) {
			window.clearInterval( poll );
			if ( settled ) {
				return;
			}
			settled = true;
			var data = ( res && res.data ) ? res.data : {};
			var message = data.message ? data.message : cfg.strings.failed;
			// A successful restore or rollback replaces the database — including the
			// users table — so this session may have been signed out. Check before
			// deciding whether to show the sign-out notice.
			if ( res && res.success && ( data.restored || data.rolled_back ) ) {
				finishAfterSessionCheck( message, 0 );
			} else {
				finishRun( message );
			}
		} ).catch( function () {
			window.clearInterval( poll );
			if ( settled ) {
				return;
			}
			settled = true;
			// The request was dispatched but no verdict came back — a severed
			// connection or a fatal on the server. On a destructive action a flat
			// "failed" here would be a lie: the operation may have completed, or may
			// still be running server-side. Say exactly that, and point at Rollback
			// rather than inviting a blind retry.
			finishRun( cfg.strings.failedUnknown );
		} );
	}

	/**
	 * Reset the controls after an operation and show its verdict.
	 *
	 * The verdict — restored, rolled back, broken, or a refusal — all arrive as a
	 * message the server already phrased, so it is shown verbatim.
	 *
	 * @param {string} message The verdict or error message to show.
	 */
	function finishRun( message ) {
		showBar( false );
		setIndeterminate( false );
		setText( 'pontifex-restore-progress', '' );
		setText( 'pontifex-restore-timing', '' );
		setControlsEnabled( true );
		setText( 'pontifex-restore-result', message );
	}

	/**
	 * After a restore or rollback wrote the database, check whether this session is
	 * still authenticated before finishing. A restore replaces wp_usermeta, so the
	 * operator may have been signed out; an authenticated ping that comes back
	 * unauthenticated means it is so, and only then is the in-page session-reset
	 * terminal state shown (not a blocking modal — see finishSessionReset()).
	 *
	 * The signals are told apart so a transient network blip cannot raise that
	 * terminal state over a restore that succeeded: WordPress answering without a
	 * JSON envelope — either admin-ajax's bare "0"/"-1" or some other non-JSON body
	 * — is the authoritative signed-out signal; a network-level failure is retried
	 * twice, and if the session state still cannot be determined the verdict is
	 * shown with a soft caveat instead — fail toward not blocking the operator.
	 *
	 * @param {string} message The verdict the server phrased.
	 * @param {number} attempt How many checks have already failed at network level.
	 */
	function finishAfterSessionCheck( message, attempt ) {
		request( 'pontifex_restore_progress' ).then( function ( res ) {
			if ( res && res.success ) {
				finishRun( message );
			} else {
				finishSessionReset();
			}
		} ).catch( function ( err ) {
			if ( err && ( 'server' === err.pontifexReason || 'auth' === err.pontifexReason ) ) {
				finishSessionReset();
				return;
			}
			if ( attempt < 2 ) {
				window.setTimeout( function () {
					finishAfterSessionCheck( message, attempt + 1 );
				}, 1500 );
				return;
			}
			finishRun( message + ' ' + cfg.strings.sessionUnknown );
		} );
	}

	var reauthPoll = 0;
	var reauthDone = false;

	/**
	 * Ask the server whether the operator is authenticated again and, once they
	 * are, reload to a normal Restore screen. Nonce-free by necessity: the
	 * restore invalidated the session the page's nonce was tied to, so a fresh
	 * login mints a new token the old nonce can never match. A logged-out check
	 * answers a bare 0 (not the success envelope) and is simply ignored. The
	 * guard makes the reload fire once even if two triggers land together.
	 */
	function checkReauth() {
		if ( reauthDone ) {
			return;
		}
		request( 'pontifex_auth_check' ).then( function ( res ) {
			if ( res && res.success && res.data && res.data.authenticated ) {
				reauthDone = true;
				window.clearInterval( reauthPoll );
				window.location.reload();
			}
		} ).catch( function () {
			// Still logged out, or a transient error: wait for the next trigger.
		} );
	}

	/**
	 * After the session-reset terminal state is shown, watch for the operator
	 * logging back in (in this tab or another — the login cookie is shared
	 * browser-wide) and reload to the real outcome the moment they have. It
	 * checks immediately whenever this tab regains focus or becomes visible — so
	 * returning to it after logging in through core's session modal clears it at
	 * once — and polls slowly in the background as a fallback for a tab that is
	 * never refocused.
	 */
	function watchForReauth() {
		if ( reauthPoll ) {
			return;
		}
		reauthPoll = window.setInterval( checkReauth, 5000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' === document.visibilityState ) {
				checkReauth();
			}
		} );
		window.addEventListener( 'focus', checkReauth );
	}

	/**
	 * Show the terminal state when a restore reset this session mid-run.
	 *
	 * A restore replaces the users and sessions tables, so once it reaches that
	 * stage the browser is signed out and the progress poll can no longer read an
	 * authenticated answer. Rather than freezing the bar, this renders a truthful,
	 * NON-blocking in-page message: the restore reached its final stage (which is
	 * expected on success) and the session was reset. It deliberately does NOT
	 * claim the whole restore succeeded — the proven outcome is recorded in the
	 * transfer history and shown on the Overview after re-login. WordPress core's
	 * native session-expired modal provides the actual log-in; this only explains
	 * what happened and, via watchForReauth(), reloads to the real result once the
	 * operator is back. No blocking overlay is stacked over core's modal.
	 */
	function finishSessionReset() {
		showBar( false );
		setIndeterminate( false );
		setText( 'pontifex-restore-progress', '' );
		setText( 'pontifex-restore-timing', '' );
		setText( 'pontifex-restore-result', cfg.strings.sessionReset );
		watchForReauth();
	}

	/**
	 * Re-attach to a restore or rollback already running server-side.
	 *
	 * A reloaded page finds the operation's live progress instead of an idle
	 * screen. The final verdict was addressed to the request that started the
	 * operation, so on completion this can only say it finished and point at
	 * where the outcome is recorded.
	 */
	function reattachIfRunning() {
		request( 'pontifex_restore_progress' ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || 'idle' === res.data.phase ) {
				return;
			}
			setControlsEnabled( false );
			showBar( true );
			setText( 'pontifex-restore-result', '' );
			setText( 'pontifex-restore-progress', phaseLabel( res.data.phase ) );

			var poll = window.setInterval( function () {
				request( 'pontifex_restore_progress' ).then( function ( r ) {
					if ( ! r || ! r.success || ! r.data ) {
						return;
					}
					if ( 'idle' === r.data.phase ) {
						window.clearInterval( poll );
						finishRun( cfg.strings.reattachedFinished );
						return;
					}
					var label = phaseLabel( r.data.phase );
					if ( r.data.bytes_total > 0 ) {
						setIndeterminate( false );
						setBar( r.data.bytes_done, r.data.bytes_total );
						setText(
							'pontifex-restore-progress',
							label + ' ' + cfg.strings.progress.replace( '%1$s', formatBytes( r.data.bytes_done ) ).replace( '%2$s', formatBytes( r.data.bytes_total ) )
						);
					} else {
						setIndeterminate( true );
						setText( 'pontifex-restore-progress', label );
					}
				} ).catch( function () {} );
			}, 1000 );
		} ).catch( function () {} );
	}

	/**
	 * Wire the action input, the backup row buttons, and the Run button.
	 */
	function init() {
		reattachIfRunning();
		var input = document.getElementById( 'pontifex-restore-action' );
		if ( input ) {
			input.addEventListener( 'input', updateRunButton );
		}

		// Each backup row is a button; clicking it (or pressing Enter/Space while it
		// is focused) selects that backup, and the arrow keys move the selection
		// within the group. The row carries the filename in data-file — there is no
		// radio or checkbox.
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

		var run = document.getElementById( 'pontifex-restore-run' );
		if ( run ) {
			run.addEventListener( 'click', function () {
				var action = currentAction();
				if ( isRunnable( action ) ) {
					startRun( action );
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
