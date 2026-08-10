<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WBI Registration Fields
 *
 * Segmenta el registro de WooCommerce para clientes mayoristas y minoristas.
 */
class WBI_Registration_Fields {

    private const SETTINGS_OPTION        = 'wbi_modules_settings';
    private const ENABLE_OPTION          = 'wbi_enable_registration_fields';
    private const REQUIRE_WHATSAPP       = 'wbi_require_registration_whatsapp';
    private const WHOLESALE_ROLE_OPTION  = 'wbi_registration_wholesale_role_slug';
    private const RETAIL_ROLE_OPTION     = 'wbi_registration_retail_role_slug';
    private const ROLE_NOTICE_TRANSIENT  = 'wbi_registration_role_notice';
    private $settings_cache              = null;
    private $feature_enabled_cache       = null;
    private $whatsapp_required_cache     = null;
    private $account_page_cache          = null;

    public function __construct() {
        add_action( 'woocommerce_register_form', array( $this, 'render_fields' ) );
        add_filter( 'woocommerce_registration_errors', array( $this, 'validate_fields' ), 10, 3 );
        add_action( 'woocommerce_created_customer', array( $this, 'save_fields' ), 10, 3 );
        add_filter( 'pre_option_woocommerce_registration_generate_password', array( $this, 'force_user_defined_password' ), 10, 3 );
        add_filter( 'pre_option_woocommerce_registration_generate_username', array( $this, 'force_user_defined_username' ), 10, 3 );
        add_filter( 'gettext', array( $this, 'translate_registration_strings' ), 20, 3 );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
    }

    private function get_settings() {
        if ( null !== $this->settings_cache ) {
            return $this->settings_cache;
        }

        $settings = get_option( self::SETTINGS_OPTION, array() );

        $this->settings_cache = is_array( $settings ) ? $settings : array();

        return $this->settings_cache;
    }

    private function is_feature_enabled() {
        if ( null !== $this->feature_enabled_cache ) {
            return $this->feature_enabled_cache;
        }

        $settings                    = $this->get_settings();
        $this->feature_enabled_cache = ! array_key_exists( self::ENABLE_OPTION, $settings ) || ! empty( $settings[ self::ENABLE_OPTION ] );

        return $this->feature_enabled_cache;
    }

    private function is_whatsapp_required() {
        if ( null !== $this->whatsapp_required_cache ) {
            return $this->whatsapp_required_cache;
        }

        $settings                      = $this->get_settings();
        $this->whatsapp_required_cache = ! array_key_exists( self::REQUIRE_WHATSAPP, $settings ) || ! empty( $settings[ self::REQUIRE_WHATSAPP ] );

        return $this->whatsapp_required_cache;
    }

    private function is_registration_account_page() {
        if ( null !== $this->account_page_cache ) {
            return $this->account_page_cache;
        }

        $this->account_page_cache = function_exists( 'is_account_page' ) && is_account_page();

        return $this->account_page_cache;
    }

    private function get_posted_value( $key ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return '';
        }

        return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
    }

    private function get_posted_password() {
        if ( ! isset( $_POST['password'] ) ) {
            return '';
        }

        return (string) wp_unslash( $_POST['password'] );
    }

    private function get_customer_type_options() {
        return array(
            'wholesale' => __( 'Mayorista', 'wbi-suite' ),
            'retail'    => __( 'Minorista', 'wbi-suite' ),
        );
    }

    private function is_valid_customer_type( $customer_type ) {
        return array_key_exists( $customer_type, $this->get_customer_type_options() );
    }

    private function is_valid_whatsapp( $phone ) {
        return '' !== $phone && 1 === preg_match( '/^[0-9+\s-]+$/', $phone );
    }

    public function force_user_defined_password( $default_value, $option, $passed_default ) {
        if ( ! $this->is_feature_enabled() ) {
            return $default_value;
        }

        return 'no';
    }

    public function force_user_defined_username( $default_value, $option, $passed_default ) {
        if ( ! $this->is_feature_enabled() ) {
            return $default_value;
        }

        return 'no';
    }

    public function translate_registration_strings( $translation, $text, $domain ) {
        if ( is_admin() || ! $this->is_feature_enabled() || ! $this->is_registration_account_page() ) {
            return $translation;
        }

        if ( in_array( $text, array( 'Password', 'Contraseña' ), true ) || in_array( $translation, array( 'Password', 'Contraseña' ), true ) ) {
            return __( 'Contraseña', 'wbi-suite' );
        }

        if ( in_array( $text, array( 'Register', 'Registrarse', 'Crear una cuenta' ), true ) || in_array( $translation, array( 'Register', 'Registrarse', 'Crear una cuenta' ), true ) ) {
            return __( 'Crear cuenta', 'wbi-suite' );
        }

        return $translation;
    }

    public function render_fields() {
        if ( ! $this->is_feature_enabled() ) {
            return;
        }

        $whatsapp      = $this->get_posted_value( 'billing_phone' );
        $customer_type = $this->get_posted_value( 'customer_type' );
        ?>
        <p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
            <label for="billing_phone">
                <?php esc_html_e( 'WhatsApp de contacto', 'wbi-suite' ); ?>
                <?php if ( $this->is_whatsapp_required() ) : ?>
                    <span class="required">*</span>
                <?php endif; ?>
            </label>
            <input
                type="tel"
                name="billing_phone"
                id="billing_phone"
                class="woocommerce-Input woocommerce-Input--text input-text"
                value="<?php echo esc_attr( $whatsapp ); ?>"
                <?php if ( $this->is_whatsapp_required() ) : ?>required="required"<?php endif; ?>
            />
        </p>

        <p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
            <label for="customer_type"><?php esc_html_e( 'Tipo de cliente', 'wbi-suite' ); ?> <span class="required">*</span></label>
            <select name="customer_type" id="customer_type" class="woocommerce-Input woocommerce-Input--select input-text" required>
                <option value=""><?php esc_html_e( 'Seleccionar tipo', 'wbi-suite' ); ?></option>
                <?php foreach ( $this->get_customer_type_options() as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $customer_type, $value ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    public function validate_fields( $errors, $username, $email ) {
        if ( ! $this->is_feature_enabled() ) {
            return $errors;
        }

        $phone         = $this->get_posted_value( 'billing_phone' );
        $customer_type = $this->get_posted_value( 'customer_type' );
        $password      = $this->get_posted_password();

        if ( $this->is_whatsapp_required() && '' === $phone ) {
            $errors->add( 'billing_phone_required', __( 'El WhatsApp de contacto es obligatorio.', 'wbi-suite' ) );
        }

        if ( '' !== $phone && ! $this->is_valid_whatsapp( $phone ) ) {
            $errors->add( 'billing_phone_invalid', __( 'Ingresá un WhatsApp válido (solo números, +, espacios o guiones).', 'wbi-suite' ) );
        }

        if ( ! $this->is_valid_customer_type( $customer_type ) ) {
            $errors->add( 'customer_type_invalid', __( 'Seleccioná el tipo de cliente.', 'wbi-suite' ) );
        }

        $password_length = function_exists( 'mb_strlen' ) ? mb_strlen( $password ) : strlen( $password );

        if ( '' === $password ) {
            $errors->add( 'password_required', __( 'La contraseña es obligatoria.', 'wbi-suite' ) );
        } elseif ( $password_length < 8 ) {
            $errors->add( 'password_too_short', __( 'La contraseña debe tener al menos 8 caracteres.', 'wbi-suite' ) );
        }

        return $errors;
    }

    public function save_fields( $customer_id, $new_customer_data = array(), $password_generated = false ) {
        if ( ! $this->is_feature_enabled() ) {
            return;
        }

        $phone         = $this->get_posted_value( 'billing_phone' );
        $customer_type = $this->get_posted_value( 'customer_type' );

        if ( '' !== $phone && $this->is_valid_whatsapp( $phone ) ) {
            update_user_meta( $customer_id, 'billing_phone', $phone );
            update_user_meta( $customer_id, 'whatsapp', $phone );
        }

        if ( $this->is_valid_customer_type( $customer_type ) ) {
            update_user_meta( $customer_id, 'customer_type', $customer_type );
            $this->assign_customer_role( $customer_id, $customer_type );
        }
    }

    private function assign_customer_role( $customer_id, $customer_type ) {
        if ( ! $this->is_valid_customer_type( $customer_type ) ) {
            return;
        }

        $role = $this->resolve_role_slug( $customer_type );
        $user = new WP_User( $customer_id );

        if ( $user->exists() ) {
            $user->set_role( $role );
        }
    }

    private function resolve_role_slug( $customer_type ) {
        $settings     = $this->get_settings();
        $default_role = 'wholesale' === $customer_type ? 'wholesale_customer' : 'customer';
        $option_key   = 'wholesale' === $customer_type ? self::WHOLESALE_ROLE_OPTION : self::RETAIL_ROLE_OPTION;
        $configured   = isset( $settings[ $option_key ] ) ? sanitize_key( $settings[ $option_key ] ) : $default_role;

        if ( '' === $configured ) {
            $configured = $default_role;
        }

        if ( get_role( $configured ) ) {
            return $configured;
        }

        $this->queue_missing_role_notice( $customer_type, $configured );
        error_log(
            sprintf(
                'WBI registration fields fallback: missing role "%1$s" for customer type "%2$s"; fallback role "customer" applied.',
                $configured,
                $customer_type
            )
        );

        return 'customer';
    }

    private function queue_missing_role_notice( $customer_type, $role_slug ) {
        $payload = get_transient( self::ROLE_NOTICE_TRANSIENT );
        $payload = is_array( $payload ) ? $payload : array();
        $key     = $customer_type . ':' . $role_slug;

        $payload[ $key ] = array(
            'customer_type' => $customer_type,
            'role_slug'     => $role_slug,
        );

        set_transient( self::ROLE_NOTICE_TRANSIENT, $payload, DAY_IN_SECONDS );
    }

    public function render_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $payload = get_transient( self::ROLE_NOTICE_TRANSIENT );
        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return;
        }

        foreach ( $payload as $notice ) {
            if ( empty( $notice['role_slug'] ) || empty( $notice['customer_type'] ) ) {
                continue;
            }

            $customer_type = 'wholesale' === $notice['customer_type']
                ? __( 'mayorista', 'wbi-suite' )
                : __( 'minorista', 'wbi-suite' );

            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        __( 'El rol configurado "%1$s" para el registro %2$s no existe. Se asignó el rol customer como respaldo.', 'wbi-suite' ),
                        $notice['role_slug'],
                        $customer_type
                    )
                )
            );
        }

        delete_transient( self::ROLE_NOTICE_TRANSIENT );
    }
}
