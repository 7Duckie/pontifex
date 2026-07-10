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

		var startedAt = Date.now();
		var poll = window.setInterval( function () {
			request( 'pontifex_restore_progress' ).then( function ( res ) {
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
			} ).catch( function () {} );
		}, 1000 );

		request( ajaxAction, extra ).then( function ( res ) {
			window.clearInterval( poll );
			var data = ( res && res.data ) ? res.data : {};
			var message = data.message ? data.message : cfg.strings.failed;
			// A successful restore or rollback replaces the database — including the
			// users table — so this session may have been signed out. Check before
			// deciding whether to show the sign-out notice.
			if ( res && res.success && ( data.restored || data.rolled_back ) ) {
				finishAfterSessionCheck( message );
			} else {
				finishRun( message );
			}
		} ).catch( function () {
			window.clearInterval( poll );
			finishRun( cfg.strings.failed );
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
	 * unauthenticated means it is so, and only then is the sign-out notice shown.
	 *
	 * @param {string} message The verdict the server phrased.
	 */
	function finishAfterSessionCheck( message ) {
		request( 'pontifex_restore_progress' ).then( function ( res ) {
			if ( res && res.success ) {
				finishRun( message );
			} else {
				finishSignedOut();
			}
		} ).catch( function () {
			finishSignedOut();
		} );
	}

	/**
	 * Show the sign-out modal: a blocking overlay whose only action is to log in.
	 *
	 * Reached only when the post-restore session check came back unauthenticated —
	 * the restored users table did not carry this session, so WordPress signed the
	 * operator out. The restore itself still succeeded. The overlay deliberately has
	 * no close control and no dismiss-on-backdrop, so the sign-out cannot be missed
	 * or assumed away the way the old inline notice below the Run button could.
	 */
	function finishSignedOut() {
		showBar( false );
		setIndeterminate( false );
		setText( 'pontifex-restore-progress', '' );
		setText( 'pontifex-restore-timing', '' );

		// If, somehow, the modal is already up, do not stack a second one.
		if ( document.querySelector( '.pontifex-modal-backdrop' ) ) {
			return;
		}

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'pontifex-modal-backdrop';

		var modal = document.createElement( 'div' );
		modal.className = 'pontifex-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-label', cfg.strings.signedOutTitle );

		var title = document.createElement( 'p' );
		title.className = 'pontifex-modal-title';
		title.textContent = cfg.strings.signedOutTitle;

		var message = document.createElement( 'p' );
		message.className = 'pontifex-modal-message';
		message.textContent = cfg.strings.signedOut;

		var actions = document.createElement( 'p' );
		actions.className = 'pontifex-modal-actions';

		var link = document.createElement( 'a' );
		link.className = 'pontifex-button';
		link.href = cfg.loginUrl;
		link.textContent = cfg.strings.loginLink;

		actions.appendChild( link );
		modal.appendChild( title );
		modal.appendChild( message );
		modal.appendChild( actions );
		backdrop.appendChild( modal );

		// Mount inside the admin wrap so the .pontifex-admin-scoped styles apply; the
		// backdrop is position: fixed, so it still covers the whole viewport.
		var host = document.querySelector( '.pontifex-admin' ) || document.body;
		host.appendChild( backdrop );

		// Move focus to the only action, so keyboard and screen-reader users land on it.
		link.focus();
	}

	/**
	 * Wire the action input, the backup row buttons, and the Run button.
	 */
	function init() {
		var input = document.getElementById( 'pontifex-restore-action' );
		if ( input ) {
			input.addEventListener( 'input', updateRunButton );
		}

		// Each backup row is a button; clicking it (or pressing Enter/Space while it
		// is focused) selects that backup. The row carries the filename in data-file —
		// there is no radio or checkbox.
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
