/**
 * Woo Precio Promo – checkout refresh
 *
 * Triggers a WooCommerce checkout update whenever the customer changes
 * the payment method and hides internal adjustment rows used to keep totals
 * aligned without showing an extra non-transfer fee label.
 */
jQuery( function ( $ ) {
	'use strict';

	function hideInternalAdjustmentRows() {
		$( '.wpp-hidden-adjustment-marker' ).closest( 'tr' ).hide();
	}

	$( 'form.checkout' ).on( 'change', 'input[name="payment_method"]', function () {
		$( 'body' ).trigger( 'update_checkout' );
	} );

	$( document.body ).on( 'updated_checkout updated_cart_totals updated_wc_div', hideInternalAdjustmentRows );

	hideInternalAdjustmentRows();
} );
