/*
 * Pontifex admin backup-upload behaviour.
 *
 * Uploads a foreign backup (a .wpmig taken on another site) so it can be restored
 * here. A single POST cannot carry a large archive, so the chosen file is sliced
 * into chunks and posted one at a time to a server endpoint that appends them and,
 * on the last chunk, validates and stores the result. A determinate bar tracks the
 * bytes sent; on success the page reloads so the new backup appears in the list. No
 * build step — plain browser JavaScript. Configuration (the ajax URL, the nonce,
 * the chunk size, and the translated strings) arrives on window.pontifexUpload via
 * wp_localize_script; every request carries the nonce, and the server re-checks the
 * capability and nonce on each chunk.
 */
( function () {
	'use strict';

	var cfg = window.pontifexUpload;
	if ( ! cfg ) {
		return;
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
		var track = document.getElementById( 'pontifex-upload-track' );
		if ( track ) {
			track.hidden = ! visible;
		}
	}

	/**
	 * Fill the progress bar to the fraction uploaded so far.
	 *
	 * @param {number} done  Bytes uploaded.
	 * @param {number} total Total bytes.
	 */
	function setBar( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var fill = document.getElementById( 'pontifex-upload-bar' );
		var track = document.getElementById( 'pontifex-upload-track' );
		if ( fill ) {
			fill.style.width = pct + '%';
		}
		if ( track ) {
			track.setAttribute( 'aria-valuenow', String( pct ) );
		}
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
	 * Generate an opaque hex upload id (server pattern: 8–64 letters/digits).
	 *
	 * @return {string} A 32-character hex token.
	 */
	function generateId() {
		var bytes = new Uint8Array( 16 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	/**
	 * The currently chosen file, or null when none is selected.
	 *
	 * @return {?File} The selected file.
	 */
	function chosenFile() {
		var input = document.getElementById( 'pontifex-upload-file' );
		return input && input.files && input.files.length ? input.files[ 0 ] : null;
	}

	/**
	 * Enable or disable the upload controls.
	 *
	 * @param {boolean} enabled Whether the controls should be usable.
	 */
	function setControlsEnabled( enabled ) {
		var input = document.getElementById( 'pontifex-upload-file' );
		var run = document.getElementById( 'pontifex-upload-run' );
		if ( input ) {
			input.disabled = ! enabled;
		}
		if ( run ) {
			run.disabled = ! enabled || ! chosenFile();
		}
	}

	/**
	 * Finish an upload run: show the verdict, then reload on success so the new
	 * backup appears in the list, or re-enable the controls on failure.
	 *
	 * @param {string}  message The verdict to show.
	 * @param {boolean} ok      Whether the upload succeeded.
	 */
	function finishUpload( message, ok ) {
		setText( 'pontifex-upload-progress', '' );
		setText( 'pontifex-upload-result', message );
		if ( ok ) {
			setBar( 1, 1 );
			window.setTimeout( function () {
				window.location.reload();
			}, 1200 );
		} else {
			showBar( false );
			setControlsEnabled( true );
		}
	}

	/**
	 * Upload one file in sequential chunks over admin-ajax.
	 *
	 * @param {File} file The chosen .wpmig file.
	 */
	function uploadFile( file ) {
		var id = generateId();
		var total = file.size;
		var chunkSize = cfg.chunkSize > 0 ? cfg.chunkSize : 1048576;
		var offset = 0;

		setControlsEnabled( false );
		setText( 'pontifex-upload-result', '' );
		showBar( true );
		setBar( 0, total );
		setText( 'pontifex-upload-progress', cfg.strings.starting );

		function sendNext() {
			var end = Math.min( offset + chunkSize, total );
			var blob = file.slice( offset, end );
			var body = new FormData();
			body.append( 'action', 'pontifex_upload_chunk' );
			body.append( '_wpnonce', cfg.nonce );
			body.append( 'upload_id', id );
			body.append( 'offset', String( offset ) );
			body.append( 'total', String( total ) );
			body.append( 'name', file.name );
			body.append( 'chunk', blob, 'chunk' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var data = ( res && res.data ) ? res.data : {};
					// The server fell out of step with us; resume from its byte count.
					if ( typeof data.expected_offset === 'number' ) {
						offset = data.expected_offset;
						setBar( offset, total );
						sendNext();
						return;
					}
					finishUpload( data.message ? data.message : cfg.strings.failed, false );
					return;
				}

				var ok = res.data || {};
				if ( ok.done ) {
					finishUpload( ok.message ? ok.message : cfg.strings.done, true );
					return;
				}

				offset = ok.received;
				setBar( offset, total );
				setText(
					'pontifex-upload-progress',
					cfg.strings.progress.replace( '%1$s', formatBytes( offset ) ).replace( '%2$s', formatBytes( total ) )
				);
				sendNext();
			} ).catch( function () {
				finishUpload( cfg.strings.failed, false );
			} );
		}

		sendNext();
	}

	/**
	 * Wire the file picker and the Upload button.
	 */
	function init() {
		var input = document.getElementById( 'pontifex-upload-file' );
		if ( input ) {
			input.addEventListener( 'change', function () {
				var name = document.getElementById( 'pontifex-upload-name' );
				var file = chosenFile();
				if ( name ) {
					name.textContent = file ? file.name : cfg.strings.noFile;
				}
				setControlsEnabled( true );
			} );
		}

		var run = document.getElementById( 'pontifex-upload-run' );
		if ( run ) {
			run.addEventListener( 'click', function () {
				var file = chosenFile();
				if ( file ) {
					uploadFile( file );
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
