/**
 * LW Image Alt — inline alt-text editor + CSV import progress.
 *
 * Expects lwiaData (localised via wp_localize_script):
 *   ajaxUrl       string
 *   nonce         string  (lwia_save_alt nonce)
 *   importNonce   string  (lwia_import nonce)
 *   saving        string
 *   saved         string
 *   errorMsg      string
 *   imageSingular string
 *   imagePlural   string
 */

( function ( $, lwiaData ) {
	'use strict';

	// ---- Event delegation ---- //

	// Re-scan immediately when the attachment filter changes (All / Attached / Unattached).
	$( '#lwia-filter-attachment' ).on( 'change', function () {
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	// Save on button click.
	$( document ).on( 'click', '.lwia-save-btn', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr[data-attachment-id]' );
		saveAlt( $row );
	} );

	// Save on Enter key in the input.
	$( document ).on( 'keydown', '.lwia-alt-input', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			$( this ).closest( 'tr[data-attachment-id]' ).find( '.lwia-save-btn' ).trigger( 'click' );
		}
	} );

	// ---- Core AJAX function ---- //

	/**
	 * POST the new alt text for a table row, then update the DOM on success.
	 *
	 * @param {jQuery} $row  The <tr> element with data-attachment-id.
	 */
	function saveAlt( $row ) {
		var attachmentId = $row.data( 'attachment-id' );
		var $wrap        = $row.find( '.lwia-inline-edit-wrap' );
		var $input       = $wrap.find( '.lwia-alt-input' );
		var $btn         = $wrap.find( '.lwia-save-btn' );
		var $spinner     = $wrap.find( '.lwia-spinner' );
		var $status      = $wrap.find( '.lwia-status' );
		var newAlt       = $input.val();

		// Indicate saving.
		$btn.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );
		$status
			.text( lwiaData.saving )
			.removeClass( 'lwia-success lwia-error' )
			.show();

		$.ajax( {
			url:    lwiaData.ajaxUrl,
			method: 'POST',
			data: {
				action:        'lwia_save_alt',
				nonce:         lwiaData.nonce,
				attachment_id: attachmentId,
				alt_text:      newAlt
			}
		} )
		.done( function ( response ) {
			$spinner.removeClass( 'is-active' );
			$btn.prop( 'disabled', false );

			if ( response.success ) {
				$status.text( lwiaData.saved ).addClass( 'lwia-success' );

				// Remove the row — image no longer needs alt text.
				setTimeout( function () {
					$row.fadeOut( 300, function () {
						$( this ).remove();
						decrementCount();
					} );
				}, 700 );

			} else {
				var msg = ( response.data && response.data.message )
					? response.data.message
					: lwiaData.errorMsg;
				$status.text( msg ).addClass( 'lwia-error' );
			}
		} )
		.fail( function () {
			$spinner.removeClass( 'is-active' );
			$btn.prop( 'disabled', false );
			$status.text( lwiaData.errorMsg ).addClass( 'lwia-error' );
		} );
	}

	// ---- DOM helpers ---- //

	/**
	 * Decrement the total-count display by 1 after a successful save.
	 */
	function decrementCount() {
		var $counter = $( '#lwia-total-count' );
		var current  = parseInt( $counter.data( 'count' ), 10 ) || 0;
		var updated  = Math.max( 0, current - 1 );

		$counter.data( 'count', updated );

		if ( 0 === updated ) {
			$counter.text( '0 ' + lwiaData.imagePlural );
		} else if ( 1 === updated ) {
			$counter.text( '1 ' + lwiaData.imageSingular );
		} else {
			$counter.text( updated + ' ' + lwiaData.imagePlural );
		}
	}

	// =========================================================================
	// CSV Import — chunked apply with progress bar
	// =========================================================================

	// "Apply Changes" button on the import preview screen.
	$( document ).on( 'click', '.lwia-apply-import-btn', function () {
		var $btn     = $( this );
		var importId = $btn.data( 'import-id' );
		var total    = parseInt( $btn.data( 'total' ), 10 ) || 0;

		$btn.prop( 'disabled', true );
		$( '#lwia-import-progress' ).show();

		applyImport( importId, total );
	} );

	/**
	 * Drive the chunked import loop until all rows are processed.
	 *
	 * @param {string} importId  Transient key returned by the upload handler.
	 * @param {number} total     Total rows in the import (for progress calculation).
	 */
	function applyImport( importId, total ) {
		var applied  = 0;
		var skipped  = 0;
		var errors   = 0;

		function nextChunk( offset ) {
			$.ajax( {
				url:    lwiaData.ajaxUrl,
				method: 'POST',
				data: {
					action:       'lwia_apply_chunk',
					nonce:        lwiaData.importNonce,
					import_id:    importId,
					chunk_offset: offset
				}
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					var msg = ( response.data && response.data.message )
						? response.data.message
						: lwiaData.errorMsg;
					showImportError( msg );
					return;
				}

				applied += response.data.applied;
				skipped += response.data.skipped;
				errors  += response.data.errors;

				var processed = applied + skipped + errors;
				var pct       = total > 0 ? Math.min( 100, Math.round( processed / total * 100 ) ) : 100;

				$( '#lwia-progress-bar-fill' ).css( 'width', pct + '%' );
				$( '#lwia-progress-label' ).text( processed + ' / ' + total );

				if ( response.data.done ) {
					showImportResults( applied, skipped, errors );
				} else {
					nextChunk( response.data.next_offset );
				}
			} )
			.fail( function () {
				showImportError( lwiaData.errorMsg );
			} );
		}

		nextChunk( 0 );
	}

	/**
	 * Hide the progress bar and display the results summary.
	 */
	function showImportResults( applied, skipped, errors ) {
		$( '#lwia-import-progress' ).hide();

		var html = '<div class="notice notice-success"><p>'
			+ '<strong>' + applied + '</strong> ' + 'updated'
			+ ( skipped > 0 ? ', <strong>' + skipped + '</strong> skipped' : '' )
			+ ( errors  > 0 ? ', <strong>' + errors  + '</strong> failed'  : '' )
			+ '.</p>';

		if ( applied > 0 ) {
			html += '<p><a href="' + window.location.pathname + '?page=lw-img-alt">'
				+ 'View updated images in the Scan screen'
				+ '</a></p>';
		}

		html += '</div>';

		$( '#lwia-import-results' ).html( html ).show();
	}

	/**
	 * Hide the progress bar and show an error message.
	 *
	 * @param {string} msg
	 */
	function showImportError( msg ) {
		$( '#lwia-import-progress' ).hide();
		$( '#lwia-import-results' )
			.html( '<div class="notice notice-error"><p>' + $( '<span>' ).text( msg ).html() + '</p></div>' )
			.show();

		// Re-enable the apply button so the user can retry.
		$( '.lwia-apply-import-btn' ).prop( 'disabled', false );
	}

} )( jQuery, window.lwiaData );
