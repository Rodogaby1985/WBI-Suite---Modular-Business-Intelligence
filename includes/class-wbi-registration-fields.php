<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Registration Fields
 *
 * Agrega los campos nativos de billing de WooCommerce (Provincia, Localidad
 * y Teléfono) al formulario de registro de /mi-cuenta/, valida su presencia y
 * los guarda como user_meta estándar de WooCommerce para que el checkout los
 * precargue automáticamente.
 */
class WBI_Registration_Fields {

    /** Mapa de provincias argentinas: código ISO (sin prefijo AR-) => nombre */
    private static $provincias = array(
        'C' => 'Ciudad Autónoma de Buenos Aires',
        'B' => 'Buenos Aires',
        'K' => 'Catamarca',
        'H' => 'Chaco',
        'U' => 'Chubut',
        'X' => 'Córdoba',
        'W' => 'Corrientes',
        'E' => 'Entre Ríos',
        'P' => 'Formosa',
        'Y' => 'Jujuy',
        'L' => 'La Pampa',
        'F' => 'La Rioja',
        'M' => 'Mendoza',
        'N' => 'Misiones',
        'Q' => 'Neuquén',
        'R' => 'Río Negro',
        'A' => 'Salta',
        'J' => 'San Juan',
        'D' => 'San Luis',
        'Z' => 'Santa Cruz',
        'S' => 'Santa Fe',
        'G' => 'Santiago del Estero',
        'V' => 'Tierra del Fuego',
        'T' => 'Tucumán',
    );

    public function __construct() {
        add_action( 'woocommerce_register_form',      array( $this, 'render_fields' ) );
        add_filter( 'woocommerce_registration_errors', array( $this, 'validate_fields' ), 10, 3 );
        add_action( 'woocommerce_created_customer',   array( $this, 'save_fields' ) );
    }

    // =========================================================================
    // RENDERIZADO
    // =========================================================================

    public function render_fields() {
        $selected_state = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '';
        $billing_city   = isset( $_POST['billing_city'] )  ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) )  : '';
        $billing_phone  = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
        ?>
        <p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
            <label for="billing_state"><?php esc_html_e( 'Provincia', 'wbi-suite' ); ?> <span class="required">*</span></label>
            <select name="billing_state" id="billing_state" class="woocommerce-Input woocommerce-Input--select input-text" required>
                <option value=""><?php esc_html_e( '— Seleccioná tu provincia —', 'wbi-suite' ); ?></option>
                <?php foreach ( self::$provincias as $code => $name ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>"<?php selected( $selected_state, $code ); ?>>
                        <?php echo esc_html( $name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
            <label for="billing_city"><?php esc_html_e( 'Localidad', 'wbi-suite' ); ?> <span class="required">*</span></label>
            <input
                type="text"
                name="billing_city"
                id="billing_city"
                class="woocommerce-Input woocommerce-Input--text input-text"
                placeholder="<?php esc_attr_e( 'Tu ciudad o localidad', 'wbi-suite' ); ?>"
                value="<?php echo esc_attr( $billing_city ); ?>"
                required
            />
        </p>

        <p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
            <label for="billing_phone"><?php esc_html_e( 'Teléfono', 'wbi-suite' ); ?> <span class="required">*</span></label>
            <input
                type="tel"
                name="billing_phone"
                id="billing_phone"
                class="woocommerce-Input woocommerce-Input--text input-text"
                placeholder="<?php esc_attr_e( 'Ej: 11-1234-5678', 'wbi-suite' ); ?>"
                value="<?php echo esc_attr( $billing_phone ); ?>"
                required
            />
        </p>
        <?php
    }

    // =========================================================================
    // VALIDACIÓN
    // =========================================================================

    /**
     * @param WP_Error $errors
     * @param string   $username
     * @param string   $email
     * @return WP_Error
     */
    public function validate_fields( $errors, $username, $email ) {
        $state = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '';
        $city  = isset( $_POST['billing_city'] )  ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) )  : '';
        $phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';

        if ( '' === $state || ! array_key_exists( $state, self::$provincias ) ) {
            $errors->add( 'billing_state_required', __( 'Por favor seleccioná tu provincia.', 'wbi-suite' ) );
        }

        if ( '' === $city ) {
            $errors->add( 'billing_city_required', __( 'Por favor ingresá tu localidad.', 'wbi-suite' ) );
        }

        if ( '' === $phone ) {
            $errors->add( 'billing_phone_required', __( 'Por favor ingresá tu teléfono.', 'wbi-suite' ) );
        }

        return $errors;
    }

    // =========================================================================
    // GUARDADO
    // =========================================================================

    /**
     * @param int $customer_id
     */
    public function save_fields( $customer_id ) {
        $state = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '';
        $city  = isset( $_POST['billing_city'] )  ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) )  : '';
        $phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';

        if ( '' !== $state && array_key_exists( $state, self::$provincias ) ) {
            update_user_meta( $customer_id, 'billing_state', $state );
        }

        if ( '' !== $city ) {
            update_user_meta( $customer_id, 'billing_city', $city );
        }

        if ( '' !== $phone ) {
            update_user_meta( $customer_id, 'billing_phone', $phone );
        }

        // Siempre establecer Argentina como país de facturación
        update_user_meta( $customer_id, 'billing_country', 'AR' );
    }
}
