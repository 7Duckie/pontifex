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
	 * Run a backup: poll for progress while the create request completes.
	 *
	 * @param {HTMLButtonElement} button The create button.
	 */
	function startCreate( button ) {
		button.disabled = true;
		setText( 'pontifex-backup-result', '' );
		setText( 'pontifex-backup-progress', cfg.strings.starting );

		var poll = window.setInterval( function () {
			request( 'pontifex_backup_progress' ).then( function ( res ) {
				if ( res && res.success && res.data && res.data.total > 0 ) {
					setText(
						'pontifex-backup-progress',
						cfg.strings.progress
							.replace( '%1$s', res.data.done )
							.replace( '%2$s', res.data.total )
					);
				}
			} ).catch( function () {} );
		}, 1500 );

		request( 'pontifex_create_backup' ).then( function ( res ) {
			window.clearInterval( poll );
			if ( res && res.success ) {
				window.location.reload();
				return;
			}
			button.disabled = false;
			setText( 'pontifex-backup-progress', '' );
			setText(
				'pontifex-backup-result',
				( res && res.data && res.data.message ) ? res.data.message : cfg.strings.failed
			);
		} ).catch( function () {
			window.clearInterval( poll );
			button.disabled = false;
			setText( 'pontifex-backup-progress', '' );
			setText( 'pontifex-backup-result', cfg.strings.failed );
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
	 * Wire the create button and any delete buttons present on the page.
	 */
	function init() {
		var create = document.getElementById( 'pontifex-create-backup' );
		if ( create ) {
			create.addEventListener( 'click', function () {
				startCreate( create );
			} );
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
