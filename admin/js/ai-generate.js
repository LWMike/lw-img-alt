/**
 * LW Image Alt — AI inline generation for the Scan screen.
 *
 * Expects lwiaAI (localised via wp_localize_script):
 *   ajaxUrl        string
 *   nonce          string   lwia_ai_generate nonce
 *   generating     string   Button label while in flight
 *   generateBtn    string   Button label at rest
 *   generateFailed string   Generic failure message
 *   rateLimited    string   Message when spend cap is reached
 */

( function ( $, lwiaAI ) {
	'use strict';

	$( document ).on( 'click', '.lwia-ai-generate-btn', function () {
		var $btn           = $( this );
		var $row           = $btn.closest( 'tr[data-attachment-id]' );
		var attachmentId   = $row.data( 'attachment-id' );
		var $wrap          = $row.find( '.lwia-inline-edit-wrap' );
		var $input         = $wrap.find( '.lwia-alt-input' );
		var $spinner       = $wrap.find( '.lwia-spinner' );

		if ( $btn.data( 'generating' ) ) {
			return;
		}

		$btn.data( 'generating', true ).prop( 'disabled', true ).text( lwiaAI.generating );
		$spinner.addClass( 'is-active' );

		$.ajax( {
			url:    lwiaAI.ajaxUrl,
			method: 'POST',
			data: {
				action:        'lwia_ai_generate',
				nonce:         lwiaAI.nonce,
				attachment_id: attachmentId,
			}
		} )
		.done( function ( response ) {
			$spinner.removeClass( 'is-active' );
			$btn.data( 'generating', false ).prop( 'disabled', false ).text( lwiaAI.generateBtn );

			if ( response.success ) {
				// Populate the input with the AI suggestion.
				$input
					.val( response.data.alt )
					.data( 'ai-model',          response.data.model )
					.data( 'ai-prompt-version', response.data.prompt_version )
					.data( 'ai-confidence',     response.data.confidence )
					.trigger( 'input' ); // marks the row dirty so the Save button activates

			} else {
				var msg = ( response.data && response.data.message )
					? response.data.message
					: lwiaAI.generateFailed;

				if ( response.data && response.data.rate_limit ) {
					msg = lwiaAI.rateLimited;
				}

				// Reuse the toast function exposed on window by admin.js.
				if ( typeof window.lwiaShowToast === 'function' ) {
					window.lwiaShowToast( msg, 'error', false );
				}
			}
		} )
		.fail( function ( jqXHR ) {
			$spinner.removeClass( 'is-active' );
			$btn.data( 'generating', false ).prop( 'disabled', false ).text( lwiaAI.generateBtn );

			var json = jqXHR.responseJSON;
			var msg  = ( json && json.data && json.data.message ) ? json.data.message : lwiaAI.generateFailed;
			if ( json && json.data && json.data.rate_limit ) {
				msg = lwiaAI.rateLimited;
			}

			if ( typeof window.lwiaShowToast === 'function' ) {
				window.lwiaShowToast( msg, 'error', false );
			}
		} );
	} );

} )( jQuery, window.lwiaAI );
