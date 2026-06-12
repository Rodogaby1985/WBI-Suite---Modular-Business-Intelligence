<?php
/**
 * Checkout fee / payment-adjustment logic.
 *
 * Keeps the financed/list total for non-transfer gateways while showing a
 * visible discount line only when the configured transfer gateway is chosen.
 *
 * @package WooPrecioPromo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPP_Checkout_Fee
 */
class WPP_Checkout_Fee {

	/**
	 * Blank visible label used for hidden non-transfer adjustments.
	 */
	const HIDDEN_FEE_LABEL = ' ';

	/**
	 * Epsilon used when comparing calculated fee amounts.
	 */
	const AMOUNT_EPSILON = 0.0001;

	/**
	 * Amount of the hidden non-transfer adjustment added in the current request.
	 */
	private static $hidden_adjustment_amount = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'maybe_add_surcharge' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'append_transfer_price_note_to_subtotal' ), 20, 3 );
		add_filter( 'woocommerce_widget_cart_item_quantity', array( __CLASS__, 'append_transfer_price_note_to_mini_cart' ), 20, 3 );
		add_filter( 'woocommerce_cart_totals_fee_html', array( __CLASS__, 'maybe_hide_adjustment_fee_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_subtotal', array( __CLASS__, 'filter_checkout_cart_subtotal' ), 20, 3 );
	}

	/**
	 * Conditionally add the checkout adjustment to the cart.
	 *
	 * @param WC_Cart $cart Current cart instance.
	 */
	public static function maybe_add_surcharge( $cart ) {
		self::$hidden_adjustment_amount = null;

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! WPP_Settings::get( 'enabled' ) ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		$uplift   = WPP_Settings::get( 'uplift' );
		$subtotal = $cart->get_subtotal();

		if ( $subtotal <= 0 || $uplift <= 0 ) {
			return;
		}

		$adjustment     = $subtotal * $uplift;
		$chosen_gateway = WC()->session->get( 'chosen_payment_method' );

		if ( empty( $chosen_gateway ) ) {
			return;
		}

		if ( $chosen_gateway === WPP_Settings::get( 'transfer_gateway' ) ) {
			$cart->add_fee(
				WPP_Settings::get( 'fee_label' ),
				-$adjustment,
				false
			);
			return;
		}

		$cart->add_fee(
			self::HIDDEN_FEE_LABEL,
			$adjustment,
			false
		);
		self::$hidden_adjustment_amount = $adjustment;
	}

	/**
	 * Add transfer-price note below line subtotal in cart, or show financed
	 * subtotal when a non-transfer gateway is selected in checkout.
	 *
	 * @param string $subtotal_html Current subtotal HTML.
	 * @param array  $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function append_transfer_price_note_to_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		if ( ! $product instanceof WC_Product ) {
			return $subtotal_html;
		}

		if ( is_checkout() ) {
			if ( ! self::is_transfer_gateway_selected() ) {
				// Non-transfer checkout: display the financed (higher) price per line.
				return self::get_financed_subtotal_html( $product, $quantity, $subtotal_html );
			}
			// Transfer checkout: show the base subtotal + transfer note.
			return $subtotal_html . self::get_transfer_note_html( $product, $quantity );
		}

		// Cart and other contexts: always append transfer note.
		return $subtotal_html . self::get_transfer_note_html( $product, $quantity );
	}

	/**
	 * Add transfer-price note to mini-cart line.
	 *
	 * @param string $html Existing mini-cart quantity/price HTML.
	 * @param array  $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function append_transfer_price_note_to_mini_cart( $html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		return $html . self::get_transfer_note_html( $product, $quantity );
	}

	/**
	 * Hide the visual fee output for non-transfer payment adjustments.
	 *
	 * @param string $fee_html Existing fee HTML.
	 * @param object $fee Fee object.
	 * @return string
	 */
	public static function maybe_hide_adjustment_fee_html( $fee_html, $fee ) {
		if ( self::is_hidden_adjustment_fee( $fee ) ) {
			return '<span class="wpp-hidden-adjustment-marker"></span>';
		}

		return $fee_html;
	}

	/**
	 * Override the cart subtotal display in checkout for non-transfer payment.
	 *
	 * When a non-transfer gateway is selected the product lines are shown at the
	 * financed (higher) price, so the subtotal row must reflect the same amount
	 * to remain numerically consistent with the displayed line items and the
	 * final order total.
	 *
	 * @param string  $cart_subtotal_html Formatted subtotal HTML produced by WooCommerce.
	 * @param bool    $compound           Whether the subtotal includes compound taxes.
	 * @param WC_Cart $cart               Current cart instance.
	 * @return string
	 */
	public static function filter_checkout_cart_subtotal( $cart_subtotal_html, $compound, $cart ) {
		unset( $compound );

		if ( ! is_checkout() ) {
			return $cart_subtotal_html;
		}

		if ( self::is_transfer_gateway_selected() ) {
			return $cart_subtotal_html;
		}

		if ( ! WPP_Settings::get( 'enabled' ) ) {
			return $cart_subtotal_html;
		}

		$uplift = WPP_Settings::get( 'uplift' );
		if ( $uplift <= 0 ) {
			return $cart_subtotal_html;
		}

		$base_subtotal = $cart->get_subtotal();
		if ( $base_subtotal <= 0 ) {
			return $cart_subtotal_html;
		}

		return wp_kses_post( wc_price( $base_subtotal * ( 1 + $uplift ) ) );
	}

	/**
	 * Build red transfer-price note HTML.
	 *
	 * @param WC_Product $product Product instance.
	 * @param int        $quantity Quantity multiplier.
	 * @return string
	 */
	private static function get_transfer_note_html( $product, $quantity ) {
		if ( ! WPP_Settings::get( 'enabled' ) ) {
			return '';
		}

		$base_price = (float) $product->get_price();
		if ( $product->is_type( 'variable' ) ) {
			$base_price = (float) $product->get_variation_price( 'min', false );
		}

		if ( $base_price <= 0 ) {
			return '';
		}

		ob_start();
		?>
		<div class="wpp-transfer-note" style="margin-top:4px; line-height:1.3; color:#c0392b; font-size:0.92em; font-weight:700;">
			<div class="wpp-transfer-note-label"><?php echo esc_html__( 'Precio por pago con transferencia', 'woo-precio-promo' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build financed line-subtotal HTML for non-transfer checkout.
	 *
	 * Returns the base price multiplied by the uplift and the quantity,
	 * formatted as a WooCommerce price string.  Falls back to $fallback_html
	 * when the price cannot be determined or the plugin is disabled.
	 *
	 * Note: in cart/checkout contexts $product is always the concrete product or
	 * the already-selected variation instance, so $product->get_price() returns
	 * the correct unit price directly without any variable-type special-casing.
	 *
	 * @param WC_Product $product       Product instance.
	 * @param int        $quantity      Line quantity.
	 * @param string     $fallback_html HTML returned when calculation is not possible.
	 * @return string
	 */
	private static function get_financed_subtotal_html( $product, $quantity, $fallback_html = '' ) {
		if ( ! WPP_Settings::get( 'enabled' ) ) {
			return $fallback_html;
		}

		$uplift = WPP_Settings::get( 'uplift' );
		if ( $uplift <= 0 ) {
			return $fallback_html;
		}

		$base_price = (float) $product->get_price();

		if ( $base_price <= 0 ) {
			return $fallback_html;
		}

		$financed_subtotal = $base_price * ( 1 + $uplift ) * $quantity;

		return wp_kses_post( wc_price( $financed_subtotal ) );
	}

	/**
	 * Check whether the currently selected checkout gateway is the configured
	 * transfer gateway.
	 *
	 * @return bool
	 */
	private static function is_transfer_gateway_selected() {
		if ( ! WC()->session ) {
			return false;
		}

		$chosen_gateway = WC()->session->get( 'chosen_payment_method' );
		if ( empty( $chosen_gateway ) ) {
			return false;
		}

		return $chosen_gateway === WPP_Settings::get( 'transfer_gateway' );
	}

	/**
	 * Enqueue the front-end script on cart and checkout pages.
	 */
	public static function enqueue_scripts() {
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'wpp-checkout-refresh',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/checkout-refresh.js',
			array( 'jquery' ),
			WPP_PLUGIN_VERSION,
			true
		);
	}

	/**
	 * Check whether a fee is the hidden non-transfer adjustment.
	 *
	 * @param object $fee Fee object.
	 * @return bool
	 */
	private static function is_hidden_adjustment_fee( $fee ) {
		return null !== self::$hidden_adjustment_amount
			&& isset( $fee->amount )
			&& isset( $fee->name )
			&& self::HIDDEN_FEE_LABEL === $fee->name
			&& (float) $fee->amount > 0
			&& abs( (float) $fee->amount - (float) self::$hidden_adjustment_amount ) < self::AMOUNT_EPSILON;
	}
}
