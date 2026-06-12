<?php
/**
 * Admin settings page for Woo Precio Promo.
 *
 * Registers a settings page under WooCommerce → Precio Promo and exposes
 * a static helper (`WPP_Settings::get()`) used by the price-display and
 * checkout-fee classes to read the current configuration.
 *
 * Priority order (highest → lowest):
 *   1. PHP constants defined in wp-config.php (backward compatibility).
 *   2. Values saved through the admin settings page.
 *   3. Hard-coded defaults (matching the original plugin behaviour).
 *
 * @package WooPrecioPromo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPP_Settings
 */
class WPP_Settings {

	/** WordPress option key used to store all settings in a single row. */
	const OPTION_KEY = 'wpp_settings';

	/** Settings page slug. */
	const PAGE_SLUG = 'wpp-settings';

	/** Settings group used by Settings API. */
	const GROUP = 'wpp_settings_group';

	// -----------------------------------------------------------------------
	// Bootstrap
	// -----------------------------------------------------------------------

	/**
	 * Register WordPress hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate_settings' ), 5 );
	}

	// -----------------------------------------------------------------------
	// Migration
	// -----------------------------------------------------------------------

	/**
	 * Migrate saved settings that use old/renamed defaults.
	 *
	 * Called early on plugins_loaded so the correct values are available
	 * before any fee or price logic runs. Guarded by a DB version flag so the
	 * migration only executes once.
	 */
	public static function maybe_migrate_settings() {
		if ( get_option( 'wpp_db_version' ) === WPP_PLUGIN_VERSION ) {
			return;
		}

		$options = (array) get_option( self::OPTION_KEY, array() );
		$changed = false;

		// fee_label was previously called "Recargo por financiación" in older
		// releases. Replace it with the current correct label.
		if ( isset( $options['fee_label'] ) && 'Recargo por financiación' === $options['fee_label'] ) {
			$options['fee_label'] = self::defaults()['fee_label'];
			$changed              = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $options );
		}

		update_option( 'wpp_db_version', WPP_PLUGIN_VERSION );
	}

	// -----------------------------------------------------------------------
	// Settings retrieval
	// -----------------------------------------------------------------------

	/**
	 * Return the hard-coded default values.
	 *
	 * These match the behaviour of the original plugin so that a fresh install
	 * (with no admin settings saved yet) works identically to v1.0.0.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'              => 1,
			/* Uplift stored as percentage (36 = 36 %) in the DB. */
			'uplift'               => 36,
			'installments'         => 18,
			'transfer_gateway'     => 'bacs',
			/* translators: label appended to the base/transfer price */
			'transfer_label'       => __( 'con Transferencia', 'woo-precio-promo' ),
			/*
			 * Placeholders available: {count} = number of installments,
			 * {amount} = formatted installment amount.
			 */
			/* translators: installment line template; {count} and {amount} are replaced at runtime */
			'installment_template' => __( '{count} cuotas sin interés de {amount}', 'woo-precio-promo' ),
			/* translators: label shown as a line item in the checkout totals for transfer payments */
			'fee_label'            => __( 'Descuento por forma de pago', 'woo-precio-promo' ),
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * Constants defined in wp-config.php take the highest priority so that
	 * existing deployments continue to work without any change.
	 *
	 * For `uplift`, the method always returns the **fractional** value
	 * (0.36 for 36 %) regardless of where the setting comes from, so callers
	 * do not need to know whether the value was stored as a percentage or as a
	 * decimal fraction.
	 *
	 * @param string $key Setting key (see self::defaults() for valid keys).
	 * @return mixed
	 */
	public static function get( $key ) {
		$options  = (array) get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();

		switch ( $key ) {

			case 'enabled':
				if ( defined( 'WPP_ENABLED' ) ) {
					return (bool) WPP_ENABLED;
				}
				if ( isset( $options['enabled'] ) ) {
					return (bool) absint( $options['enabled'] );
				}
				return (bool) $defaults['enabled'];

			// -----------------------------------------------------------------
			// uplift – constant WPP_UPLIFT is fractional (0.36), DB is percent.
			// Always return fractional.
			// -----------------------------------------------------------------
			case 'uplift':
				if ( defined( 'WPP_UPLIFT' ) ) {
					return (float) WPP_UPLIFT;
				}
				if ( isset( $options['uplift'] ) && $options['uplift'] !== '' ) {
					return (float) $options['uplift'] / 100.0;
				}
				return (float) $defaults['uplift'] / 100.0;

			// -----------------------------------------------------------------
			// installments
			// -----------------------------------------------------------------
			case 'installments':
				if ( defined( 'WPP_INSTALLMENTS' ) ) {
					return absint( WPP_INSTALLMENTS );
				}
				if ( isset( $options['installments'] ) && $options['installments'] !== '' ) {
					return absint( $options['installments'] );
				}
				return absint( $defaults['installments'] );

			// -----------------------------------------------------------------
			// transfer_gateway
			// -----------------------------------------------------------------
			case 'transfer_gateway':
				if ( defined( 'WPP_TRANSFER_GATEWAY' ) ) {
					return (string) WPP_TRANSFER_GATEWAY;
				}
				if ( ! empty( $options['transfer_gateway'] ) ) {
					return sanitize_text_field( $options['transfer_gateway'] );
				}
				return (string) $defaults['transfer_gateway'];

			// -----------------------------------------------------------------
			// Text labels – no constant equivalent; DB → default.
			// -----------------------------------------------------------------
			case 'transfer_label':
				if ( ! empty( $options['transfer_label'] ) ) {
					return $options['transfer_label'];
				}
				return $defaults['transfer_label'];

			case 'installment_template':
				if ( ! empty( $options['installment_template'] ) ) {
					return $options['installment_template'];
				}
				return $defaults['installment_template'];

			case 'fee_label':
				$label = ! empty( $options['fee_label'] ) ? $options['fee_label'] : $defaults['fee_label'];
				// Legacy guard: old installs may have persisted the old surcharge label.
				// Always return the current correct label regardless of what is in the DB.
				if ( 'Recargo por financiación' === $label ) {
					return $defaults['fee_label'];
				}
				return $label;

			default:
				return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
		}
	}

	// -----------------------------------------------------------------------
	// Admin menu
	// -----------------------------------------------------------------------

	/**
	 * Add a sub-menu page under the WooCommerce menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			/* translators: browser tab title for the settings page */
			__( 'Ajustes de Precio Promo', 'woo-precio-promo' ),
			/* translators: sidebar menu label */
			__( 'Precio Promo', 'woo-precio-promo' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	// -----------------------------------------------------------------------
	// Settings API registration
	// -----------------------------------------------------------------------

	/**
	 * Register settings, sections and fields with the WordPress Settings API.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		// -------------------------------------------------------------------
		// Section: Calculation
		// -------------------------------------------------------------------
		add_settings_section(
			'wpp_section_calc',
			__( 'Cálculo', 'woo-precio-promo' ),
			array( __CLASS__, 'section_calc_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpp_field_enabled',
			__( 'Activar plugin', 'woo-precio-promo' ),
			array( __CLASS__, 'field_enabled' ),
			self::PAGE_SLUG,
			'wpp_section_calc'
		);

		add_settings_field(
			'wpp_field_uplift',
			__( 'Porcentaje de recargo (%)', 'woo-precio-promo' ),
			array( __CLASS__, 'field_uplift' ),
			self::PAGE_SLUG,
			'wpp_section_calc'
		);

		add_settings_field(
			'wpp_field_installments',
			__( 'Cantidad de cuotas', 'woo-precio-promo' ),
			array( __CLASS__, 'field_installments' ),
			self::PAGE_SLUG,
			'wpp_section_calc'
		);

		add_settings_field(
			'wpp_field_transfer_gateway',
			__( 'ID de medio de pago sin recargo', 'woo-precio-promo' ),
			array( __CLASS__, 'field_transfer_gateway' ),
			self::PAGE_SLUG,
			'wpp_section_calc'
		);

		// -------------------------------------------------------------------
		// Section: Text Labels
		// -------------------------------------------------------------------
		add_settings_section(
			'wpp_section_text',
			__( 'Textos visibles', 'woo-precio-promo' ),
			array( __CLASS__, 'section_text_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpp_field_transfer_label',
			__( 'Texto del precio por transferencia', 'woo-precio-promo' ),
			array( __CLASS__, 'field_transfer_label' ),
			self::PAGE_SLUG,
			'wpp_section_text'
		);

		add_settings_field(
			'wpp_field_installment_template',
			__( 'Plantilla de línea de cuotas', 'woo-precio-promo' ),
			array( __CLASS__, 'field_installment_template' ),
			self::PAGE_SLUG,
			'wpp_section_text'
		);

		add_settings_field(
			'wpp_field_fee_label',
			__( 'Texto del descuento en checkout', 'woo-precio-promo' ),
			array( __CLASS__, 'field_fee_label' ),
			self::PAGE_SLUG,
			'wpp_section_text'
		);
	}

	// -----------------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------------

	/**
	 * Sanitize and validate the submitted settings before saving.
	 *
	 * @param array $input Raw POST values.
	 * @return array Sanitized values.
	 */
	public static function sanitize( $input ) {
		$clean    = array();
		$defaults = self::defaults();

		$clean['enabled'] = isset( $input['enabled'] ) ? 1 : 0;

		// uplift: percentage, 0–1000.
		$raw_uplift       = isset( $input['uplift'] ) ? $input['uplift'] : $defaults['uplift'];
		$clean['uplift']  = max( 0, min( 1000, (float) str_replace( ',', '.', $raw_uplift ) ) );

		// installments: non-negative integer; 0 = hide the line.
		$clean['installments'] = isset( $input['installments'] )
			? absint( $input['installments'] )
			: $defaults['installments'];

		// transfer_gateway: single word, lowercase.
		$clean['transfer_gateway'] = isset( $input['transfer_gateway'] ) && '' !== $input['transfer_gateway']
			? sanitize_key( $input['transfer_gateway'] )
			: $defaults['transfer_gateway'];

		// Text labels: plain text, no HTML.
		$clean['transfer_label'] = isset( $input['transfer_label'] ) && '' !== $input['transfer_label']
			? sanitize_text_field( $input['transfer_label'] )
			: $defaults['transfer_label'];

		$clean['installment_template'] = isset( $input['installment_template'] ) && '' !== $input['installment_template']
			? sanitize_text_field( $input['installment_template'] )
			: $defaults['installment_template'];

		$clean['fee_label'] = isset( $input['fee_label'] ) && '' !== $input['fee_label']
			? sanitize_text_field( $input['fee_label'] )
			: $defaults['fee_label'];

		return $clean;
	}

	// -----------------------------------------------------------------------
	// Section descriptions
	// -----------------------------------------------------------------------

	/** @internal */
	public static function section_calc_description() {
		echo '<p>' . esc_html__( 'Definí si el plugin está activo y cómo se calcula el precio financiado y el ajuste por medio de pago.', 'woo-precio-promo' ) . '</p>';
	}

	/** @internal */
	public static function section_text_description() {
		echo '<p>' . esc_html__( 'Personalizá los textos que ven tus clientes en productos y checkout.', 'woo-precio-promo' ) . '</p>';
	}

	// -----------------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------------

	/** @internal */
	public static function field_enabled() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = isset( $options['enabled'] ) ? absint( $options['enabled'] ) : self::defaults()['enabled'];
		printf(
			'<label for="wpp_enabled"><input type="checkbox" id="wpp_enabled" name="%s[enabled]" value="1" %s> %s</label><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			checked( 1, $value, false ),
			esc_html__( 'Activar comportamiento de Precio Promo', 'woo-precio-promo' ),
			esc_html__( 'Si lo desactivás, el plugin no modifica precios ni agrega ajustes en el checkout.', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_uplift() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = isset( $options['uplift'] ) && $options['uplift'] !== '' ? $options['uplift'] : self::defaults()['uplift'];
		printf(
			'<input type="number" id="wpp_uplift" name="%s[uplift]" value="%s" min="0" max="1000" step="0.01" class="small-text"> %%<p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_html__( 'Porcentaje que se suma sobre el precio base cuando se usa un medio distinto al de transferencia (ejemplo: 36 para 36%).', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_installments() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = isset( $options['installments'] ) && $options['installments'] !== '' ? $options['installments'] : self::defaults()['installments'];
		printf(
			'<input type="number" id="wpp_installments" name="%s[installments]" value="%s" min="0" step="1" class="small-text"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_html__( 'Cantidad de cuotas iguales que se muestran en la línea de cuotas. Usá 0 para ocultarla.', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_transfer_gateway() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = ! empty( $options['transfer_gateway'] ) ? $options['transfer_gateway'] : self::defaults()['transfer_gateway'];
		printf(
			'<input type="text" id="wpp_transfer_gateway" name="%s[transfer_gateway]" value="%s" class="regular-text"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_html__( 'ID de WooCommerce que se considera medio de transferencia para aplicar el descuento (por defecto: bacs).', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_transfer_label() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = ! empty( $options['transfer_label'] ) ? $options['transfer_label'] : self::defaults()['transfer_label'];
		printf(
			'<input type="text" id="wpp_transfer_label" name="%s[transfer_label]" value="%s" class="regular-text"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_html__( 'Texto que se agrega al precio base en la página de producto (ejemplo: "con Transferencia").', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_installment_template() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = ! empty( $options['installment_template'] ) ? $options['installment_template'] : self::defaults()['installment_template'];
		printf(
			'<input type="text" id="wpp_installment_template" name="%s[installment_template]" value="%s" class="large-text"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			/* translators: description of available template placeholders */
			esc_html__( 'Plantilla para la línea de cuotas. Usá {count} para la cantidad de cuotas y {amount} para el importe formateado.', 'woo-precio-promo' )
		);
	}

	/** @internal */
	public static function field_fee_label() {
		$options = (array) get_option( self::OPTION_KEY, array() );
		$value   = ! empty( $options['fee_label'] ) ? $options['fee_label'] : self::defaults()['fee_label'];
		printf(
			'<input type="text" id="wpp_fee_label" name="%s[fee_label]" value="%s" class="regular-text"><p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value ),
			esc_html__( 'Texto que se muestra como línea de descuento cuando se elige la transferencia en el checkout.', 'woo-precio-promo' )
		);
	}

	// -----------------------------------------------------------------------
	// Page renderer
	// -----------------------------------------------------------------------

	/**
	 * Output the HTML for the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ajustes de Woo Precio Promo', 'woo-precio-promo' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Guardar ajustes', 'woo-precio-promo' ) );
				?>
			</form>
		</div>
		<?php
	}
}
