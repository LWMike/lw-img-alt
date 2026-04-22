/**
 * LW Image Alt — AI Review screen interactions.
 *
 * Expects lwiaReview (localised via wp_localize_script):
 *   confirmApply   string   Confirmation dialog message
 */

( function ( $, lwiaReview ) {
	'use strict';

	// ---- Select all / Select none buttons ----

	$( '#lwia-select-all' ).on( 'click', function () {
		$( '.lwia-review-table input[name="selected[]"]' ).prop( 'checked', true );
		$( '#lwia-check-all' ).prop( 'checked', true );
	} );

	$( '#lwia-select-none' ).on( 'click', function () {
		$( '.lwia-review-table input[name="selected[]"]' ).prop( 'checked', false );
		$( '#lwia-check-all' ).prop( 'checked', false );
	} );

	// ---- Header checkbox — toggle all rows ----

	$( '#lwia-check-all' ).on( 'change', function () {
		$( '.lwia-review-table input[name="selected[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
	} );

	// ---- Form submit — confirm before applying ----

	$( '#lwia-review-form' ).on( 'submit', function ( e ) {
		if ( ! window.confirm( lwiaReview.confirmApply ) ) {
			e.preventDefault();
		}
	} );

} )( jQuery, window.lwiaReview );
