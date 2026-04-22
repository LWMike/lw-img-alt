/**
 * LW Image Alt — inline alt-text editor, CSV import progress, log utilities.
 *
 * Expects lwiaData (localised via wp_localize_script):
 *   ajaxUrl       string
 *   nonce         string   lwia_save_alt nonce
 *   importNonce   string   lwia_import nonce
 *   saving        string
 *   saved         string
 *   savedToast    string   "%s" replaced with filename
 *   errorToast    string   "%s" replaced with filename
 *   errorMsg      string
 *   imageSingular string
 *   imagePlural   string
 *   undoConfirm   string   "%1$s" = image count, "%2$s" = batch short ID
 *   copied        string
 *   dismiss       string
 */

( function ( $, lwiaData ) {
	'use strict';

	// =========================================================================
	// Scan screen — Save button with dirty-state tracking + autosave fallback
	// =========================================================================

	// Enable/disable Save button as the user types; clear any stale error indicator.
	$( document ).on( 'input', '.lwia-alt-input', function () {
		var $input    = $( this );
		var $wrap     = $input.closest( '.lwia-inline-edit-wrap' );
		var $btn      = $wrap.find( '.lwia-row-save' );
		var $saveInd  = $wrap.find( '.lwia-save-indicator' );
		var dirty     = $input.val() !== ( $input.data( 'original-alt' ) || '' );

		$btn.prop( 'disabled', ! dirty )
			.toggleClass( 'is-dirty button-primary', dirty );

		// Clear a lingering error indicator as soon as the user edits again.
		if ( dirty ) {
			$saveInd.removeClass( 'is-error' );
		}
	} );

	// Save button click.
	$( document ).on( 'click', '.lwia-row-save', function () {
		saveAlt( $( this ).closest( 'tr[data-attachment-id]' ) );
	} );

	// Save on Enter key.
	$( document ).on( 'keydown', '.lwia-alt-input', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			saveAlt( $( this ).closest( 'tr[data-attachment-id]' ) );
		}
	} );

	// Silent autosave on blur (power-user shortcut — same as clicking Save).
	$( document ).on( 'blur', '.lwia-alt-input', function () {
		var $input = $( this );
		if ( $input.val() !== ( $input.data( 'original-alt' ) || '' ) ) {
			saveAlt( $input.closest( 'tr[data-attachment-id]' ) );
		}
	} );

	// Re-scan immediately when the attachment filter changes.
	$( '#lwia-filter-attachment' ).on( 'change', function () {
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	// ---- Core AJAX function ---- //

	function saveAlt( $row ) {
		var attachmentId = $row.data( 'attachment-id' );
		var filename     = $row.data( 'filename' ) || '';
		var $wrap        = $row.find( '.lwia-inline-edit-wrap' );
		var $input       = $wrap.find( '.lwia-alt-input' );
		var $btn         = $wrap.find( '.lwia-row-save' );
		var $spinner     = $wrap.find( '.lwia-spinner' );
		var $saveInd     = $wrap.find( '.lwia-save-indicator' );
		var $srStatus    = $wrap.find( '.lwia-status' );
		var newAlt       = $input.val();

		// Prevent duplicate in-flight saves.
		if ( $input.data( 'saving' ) ) {
			return;
		}
		$input.data( 'saving', true );
		$input.prop( 'disabled', true );
		$btn.prop( 'disabled', true );

		$saveInd.removeClass( 'is-success is-error is-fading' );
		$spinner.addClass( 'is-active' );
		$srStatus.text( lwiaData.saving );

		$.ajax( {
			url:    lwiaData.ajaxUrl,
			method: 'POST',
			data: {
				action:             'lwia_save_alt',
				nonce:              lwiaData.nonce,
				attachment_id:      attachmentId,
				alt_text:           newAlt,
				ai_model:           $input.data( 'ai-model' )           || '',
				ai_prompt_version:  $input.data( 'ai-prompt-version' )  || '',
				ai_confidence:      $input.data( 'ai-confidence' )      || '',
			}
		} )
		.done( function ( response ) {
			$spinner.removeClass( 'is-active' );
			$input.prop( 'disabled', false ).data( 'saving', false );

			if ( response.success ) {
				// Update original so button stays disabled on subsequent blur.
				$input.data( 'original-alt', newAlt );
				$btn.prop( 'disabled', true ).removeClass( 'is-dirty button-primary' );

				$srStatus.text( lwiaData.saved );
				$saveInd.addClass( 'is-success' );

				// Toast: "Alt text saved for filename."
				showToast( lwiaData.savedToast.replace( '%s', filename ), 'success', true );

				// Fade the success indicator after 2s.
				setTimeout( function () {
					$saveInd.addClass( 'is-fading' );
					setTimeout( function () { $saveInd.removeClass( 'is-success is-fading' ); }, 300 );
				}, 2000 );

				// Fade out the row after a short delay.
				setTimeout( function () {
					$row.fadeOut( 300, function () {
						$( this ).remove();
						decrementCount();
					} );
				}, 900 );

			} else {
				// Leave button enabled so the user can retry.
				$btn.prop( 'disabled', false );

				var errMsg = ( response.data && response.data.message )
					? response.data.message
					: lwiaData.errorMsg;

				$saveInd.addClass( 'is-error' );
				$srStatus.text( errMsg );

				showToast( lwiaData.errorToast.replace( '%s', filename ) + ' ' + errMsg, 'error', false );
			}
		} )
		.fail( function () {
			$spinner.removeClass( 'is-active' );
			$input.prop( 'disabled', false ).data( 'saving', false );
			$btn.prop( 'disabled', false );

			$saveInd.addClass( 'is-error' );
			$srStatus.text( lwiaData.errorMsg );

			showToast( lwiaData.errorToast.replace( '%s', filename ) + ' ' + lwiaData.errorMsg, 'error', false );
		} );
	}

	// ---- DOM helpers ---- //

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
	// Toast notifications
	// =========================================================================

	function showToast( message, type, autoDismiss ) {
		var $container = $( '#lwia-toasts' );
		if ( ! $container.length ) {
			return;
		}

		var $toast = $(
			'<div class="notice notice-' + ( 'error' === type ? 'error' : 'success' ) + ' is-dismissible lwia-toast" role="alert">'
			+ '<p>' + $( '<span>' ).text( message ).html() + '</p>'
			+ '<button type="button" class="notice-dismiss">'
			+ '<span class="screen-reader-text">' + lwiaData.dismiss + '</span>'
			+ '</button>'
			+ '</div>'
		);

		$container.append( $toast );

		$toast.find( '.notice-dismiss' ).on( 'click', function () {
			$toast.fadeOut( 200, function () { $( this ).remove(); } );
		} );

		if ( autoDismiss ) {
			setTimeout( function () {
				$toast.fadeOut( 300, function () { $( this ).remove(); } );
			}, 3000 );
		}
	}

	// Expose showToast so sibling scripts (ai-generate.js) can reuse it.
	window.lwiaShowToast = showToast;

	// =========================================================================
	// CSV Import — chunked apply with progress bar
	// =========================================================================

	$( document ).on( 'click', '.lwia-apply-import-btn', function () {
		var $btn     = $( this );
		var importId = $btn.data( 'import-id' );
		var total    = parseInt( $btn.data( 'total' ), 10 ) || 0;

		$btn.prop( 'disabled', true );
		$( '#lwia-import-progress' ).show();

		applyImport( importId, total );
	} );

	function applyImport( importId, total ) {
		var applied = 0;
		var skipped = 0;
		var errors  = 0;

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

	function showImportResults( applied, skipped, errors ) {
		$( '#lwia-import-progress' ).hide();

		var html = '<div class="notice notice-success"><p>'
			+ '<strong>' + applied + '</strong> updated'
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

	function showImportError( msg ) {
		$( '#lwia-import-progress' ).hide();
		$( '#lwia-import-results' )
			.html( '<div class="notice notice-error"><p>' + $( '<span>' ).text( msg ).html() + '</p></div>' )
			.show();

		$( '.lwia-apply-import-btn' ).prop( 'disabled', false );
	}

	// =========================================================================
	// Change Log — auto-apply filters + batch ID clipboard copy
	// =========================================================================

	// Auto-submit log filter form when source or user dropdown changes.
	$( '#lwia-log-source, #lwia-log-user' ).on( 'change', function () {
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	// Submit on date field blur.
	$( '#lwia-log-date-from, #lwia-log-date-to' ).on( 'blur', function () {
		$( this ).closest( 'form' ).trigger( 'submit' );
	} );

	// Batch ID copy to clipboard.
	$( document ).on( 'click', '.lwia-batch-copy', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var fullId  = $btn.data( 'batch-id' );
		var $code   = $btn.find( 'code' );
		var origHtml = $code.html();

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( fullId ).then( function () {
				$code.text( lwiaData.copied );
				setTimeout( function () { $code.html( origHtml ); }, 1000 );
			} );
		} else {
			// Fallback for older browsers.
			var $tmp = $( '<textarea>' ).val( fullId ).appendTo( 'body' ).select();
			document.execCommand( 'copy' );
			$tmp.remove();
			$code.text( lwiaData.copied );
			setTimeout( function () { $code.html( origHtml ); }, 1000 );
		}
	} );

	// =========================================================================
	// Undo screen — confirmation dialog
	// =========================================================================

	$( document ).on( 'submit', '.lwia-undo-form', function ( e ) {
		var $form      = $( this );
		var rowCount   = parseInt( $form.data( 'row-count' ), 10 ) || 0;
		var batchShort = $form.data( 'batch-short' ) || '';

		var countLabel = rowCount === 1
			? '1 image'
			: rowCount + ' images';

		var msg = lwiaData.undoConfirm
			.replace( '%1$s', countLabel )
			.replace( '%2$s', batchShort );

		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	} );

} )( jQuery, window.lwiaData );
