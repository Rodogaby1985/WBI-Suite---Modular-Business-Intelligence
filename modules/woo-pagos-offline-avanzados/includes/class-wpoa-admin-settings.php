<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WPOA_Admin_Settings 2.1
 *
 * Maneja los ajustes del plugin en el panel de administración.
 */
class WPOA_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'init_settings' ) );
    }

    /**
     * Añade la página de ajustes al menú de WooCommerce.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Pagos Offline Avanzados', 'woo-pagos-offline-avanzados' ),
            __( 'Pagos Offline Avanzados', 'woo-pagos-offline-avanzados' ),
            'manage_woocommerce', // Capacidad requerida para acceder
            'wpoa-pagos-offline-avanzados-settings',
            array( $this, 'settings_page_content' )
        );
    }

    /**
     * Inicializa los campos y secciones de ajustes.
     */
    public function init_settings() {
        // Sección de Cuentas Bancarias
        add_settings_section(
            'wpoa_bank_accounts_section',
            __( 'Cuentas Bancarias para Pagos', 'woo-pagos-offline-avanzados' ),
            array( $this, 'bank_accounts_section_callback' ),
            'wpoa-pagos-offline-avanzados-settings'
        );

        // Campo para el monto límite
        add_settings_field(
            'wpoa_amount_threshold',
            __( 'Monto Límite para Cuentas ($)', 'woo-pagos-offline-avanzados' ),
            array( $this, 'amount_threshold_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section'
        );

        // Campo para Cuenta 1
        add_settings_field(
            'wpoa_bank_account_1',
            __( 'Cuenta Bancaria (para montos menores al límite)', 'woo-pagos-offline-avanzados' ),
            array( $this, 'bank_account_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section',
            array( 'id' => 'wpoa_bank_account_1', 'description' => 'Datos para pedidos con monto menor al límite configurado.' )
        );

        // Campo para Cuenta 2
        add_settings_field(
            'wpoa_bank_account_2',
            __( 'Cuenta Bancaria (para montos mayores o iguales al límite)', 'woo-pagos-offline-avanzados' ),
            array( $this, 'bank_account_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section',
            array( 'id' => 'wpoa_bank_account_2', 'description' => 'Datos para pedidos con monto mayor o igual al límite configurado.' )
        );

        // Campo para Número de WhatsApp (CORREGIDO)
        add_settings_field(
            'wpoa_whatsapp_number',
            __( 'Número de WhatsApp de Contacto', 'woo-pagos-offline-avanzados' ),
            array( $this, 'whatsapp_number_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section',
            array( 'id' => 'wpoa_whatsapp_number' ) // ✅ Argumentos agregados
        );

        // Nuevos campos para URL base de WhatsApp
        add_settings_field(
            'wpoa_whatsapp_base_url_mobile',
            __( 'URL Base WhatsApp (Móvil)', 'woo-pagos-offline-avanzados' ),
            array( $this, 'whatsapp_base_url_mobile_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section'
        );

        add_settings_field(
            'wpoa_whatsapp_base_url_desktop',
            __( 'URL Base WhatsApp (Escritorio)', 'woo-pagos-offline-avanzados' ),
            array( $this, 'whatsapp_base_url_desktop_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_bank_accounts_section'
        );

        // Sección de Caducidad del Link
        add_settings_section(
            'wpoa_link_expiration_section',
            __( 'Caducidad del Link de Pago', 'woo-pagos-offline-avanzados' ),
            array( $this, 'link_expiration_section_callback' ),
            'wpoa-pagos-offline-avanzados-settings'
        );

        add_settings_field(
            'wpoa_link_expiration_enabled',
            __( 'Habilitar Caducidad', 'woo-pagos-offline-avanzados' ),
            array( $this, 'checkbox_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_link_expiration_section',
            array( 'id' => 'wpoa_link_expiration_enabled', 'label' => 'Activar la caducidad automática de links de pago y pedidos.' )
        );

        add_settings_field(
            'wpoa_expiration_hours',
            __( 'Horas para Caducidad', 'woo-pagos-offline-avanzados' ),
            array( $this, 'text_field_callback' ),
            'wpoa-pagos-offline-avanzados-settings',
            'wpoa_link_expiration_section',
            array( 'id' => 'wpoa_expiration_hours', 'description' => 'Número de horas que el link de pago es válido. (Ej: 24 para 1 día).' )
        );

        // Registrar los ajustes
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_amount_threshold', array( 'sanitize_callback' => 'floatval' ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_bank_account_1', array( 'sanitize_callback' => array( $this, 'sanitize_bank_account_data' ) ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_bank_account_2', array( 'sanitize_callback' => array( $this, 'sanitize_bank_account_data' ) ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_whatsapp_number', array( 'sanitize_callback' => array( $this, 'sanitize_whatsapp_number' ) ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_whatsapp_base_url_mobile', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_whatsapp_base_url_desktop', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_link_expiration_enabled', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_expiration_hours', array( 'sanitize_callback' => 'absint' ) );

        // El slug de la página de pagos ya no es necesario en la interfaz.
        register_setting( 'wpoa-pagos-offline-avanzados-settings', 'wpoa_pagos_offline_avanzados_payment_page_slug', array( 'sanitize_callback' => 'sanitize_title' ) );
    }

    /**
     * Callback para la sección de cuentas bancarias.
     */
    public function bank_accounts_section_callback() {
        echo '<p>' . esc_html__( 'Define los datos de las cuentas bancarias para los diferentes rangos de monto.', 'woo-pagos-offline-avanzados' ) . '</p>';
    }

    /**
     * Callback para la sección de caducidad de link.
     */
    public function link_expiration_section_callback() {
        echo '<p>' . esc_html__( 'Configura si los links de pago deben caducar y en cuánto tiempo.', 'woo-pagos-offline-avanzados' ) . '</p>';
    }

    /**
     * Callback para campos de texto genéricos.
     */
    public function text_field_callback( $args ) {
        $id = isset($args['id']) ? esc_attr( $args['id'] ) : '';
        $value = esc_attr( get_option( $id, '' ) );
        echo "<input type='text' id='$id' name='$id' value='$value' class='regular-text' />";
        if ( ! empty( $args['description'] ) ) {
            echo "<p class='description'>" . esc_html( $args['description'] ) . "</p>";
        }
    }

    /**
     * Callback para checkbox genéricos.
     */
    public function checkbox_field_callback( $args ) {
        $id = isset($args['id']) ? esc_attr( $args['id'] ) : '';
        $value = get_option( $id );
        echo "<input type='checkbox' id='$id' name='$id' value='yes' " . checked( 'yes', $value, false ) . " />";
        echo "<label for='$id'>" . esc_html( $args['label'] ) . "</label>";
    }

    /**
     * Callback para el campo de monto límite.
     */
    public function amount_threshold_field_callback() {
        $threshold = get_option( 'wpoa_amount_threshold', 400000 );
        echo '<input type="number" step="0.01" min="0" id="wpoa_amount_threshold" name="wpoa_amount_threshold" value="' . esc_attr( $threshold ) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__( 'Monto en la moneda base de WooCommerce a partir del cual se utilizará la segunda cuenta bancaria. (Ej: 400000).', 'woo-pagos-offline-avanzados' ) . '</p>';
    }

    /**
     * Callback para el campo de cuenta bancaria (Alias/CBU/Titular).
     */
    public function bank_account_field_callback( $args ) {
        $id = isset($args['id']) ? esc_attr( $args['id'] ) : '';
        $options = get_option( $id );
        $alias = isset( $options['alias'] ) ? esc_attr( $options['alias'] ) : '';
        $cbu = isset( $options['cbu'] ) ? esc_attr( $options['cbu'] ) : '';
        $titular = isset( $options['titular'] ) ? esc_attr( $options['titular'] ) : '';
        $banco = isset( $options['banco'] ) ? esc_attr( $options['banco'] ) : '';

        echo '<p><label for="' . $id . '_alias">' . esc_html__( 'Alias/CVU:', 'woo-pagos-offline-avanzados' ) . ' <input type="text" id="' . $id . '_alias" name="' . $id . '[alias]" value="' . $alias . '" class="regular-text" /></label></p>';
        echo '<p><label for="' . $id . '_cbu">' . esc_html__( 'CBU/Cuenta:', 'woo-pagos-offline-avanzados' ) . ' <input type="text" id="' . $id . '_cbu" name="' . $id . '[cbu]" value="' . $cbu . '" class="regular-text" /></label></p>';
        echo '<p><label for="' . $id . '_titular">' . esc_html__( 'Titular:', 'woo-pagos-offline-avanzados' ) . ' <input type="text" id="' . $id . '_titular" name="' . $id . '[titular]" value="' . $titular . '" class="regular-text" /></label></p>';
        echo '<p><label for="' . $id . '_banco">' . esc_html__( 'Banco:', 'woo-pagos-offline-avanzados' ) . ' <input type="text" id="' . $id . '_banco" name="' . $id . '[banco]" value="' . $banco . '" class="regular-text" /></label></p>';

        if ( ! empty( $args['description'] ) ) {
            echo "<p class='description'>" . esc_html( $args['description'] ) . "</p>";
        }
    }

    /**
     * Callback para el campo de número de WhatsApp (MEJORADO).
     */
    public function whatsapp_number_field_callback( $args ) {
        $id = isset($args['id']) ? esc_attr( $args['id'] ) : 'wpoa_whatsapp_number';
        $value = esc_attr( get_option( $id, '' ) );
        echo "<input type='text' id='$id' name='$id' value='$value' class='regular-text' placeholder='Ej: 5491112345678' />";
        echo "<p class='description'>" . esc_html__( 'Ingresa tu número de WhatsApp con el código de país y de área, sin el "+" ni espacios. Ej: 5491112345678 para Argentina, Buenos Aires.', 'woo-pagos-offline-avanzados' ) . "</p>";
    }

    /**
     * Callback para el campo de URL Base WhatsApp (Móvil).
     */
    public function whatsapp_base_url_mobile_callback() {
        $current_option = get_option( 'wpoa_whatsapp_base_url_mobile', 'api' );
        ?>
        <select name="wpoa_whatsapp_base_url_mobile" id="wpoa_whatsapp_base_url_mobile">
            <option value="api" <?php selected( $current_option, 'api' ); ?>><?php esc_html_e( 'api.whatsapp.com/send (Recomendado para móviles)', 'woo-pagos-offline-avanzados' ); ?></option>
            <option value="protocol" <?php selected( $current_option, 'protocol' ); ?>><?php esc_html_e( 'whatsapp://send (Intenta abrir la app directamente)', 'woo-pagos-offline-avanzados' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Elige cómo se abrirá WhatsApp en dispositivos móviles.', 'woo-pagos-offline-avanzados' ); ?></p>
        <?php
    }

    /**
     * Callback para el campo de URL Base WhatsApp (Escritorio).
     */
    public function whatsapp_base_url_desktop_callback() {
        $current_option = get_option( 'wpoa_whatsapp_base_url_desktop', 'web' );
        ?>
        <select name="wpoa_whatsapp_base_url_desktop" id="wpoa_whatsapp_base_url_desktop">
            <option value="web" <?php selected( $current_option, 'web' ); ?>><?php esc_html_e( 'web.whatsapp.com/send (Recomendado para WhatsApp Web)', 'woo-pagos-offline-avanzados' ); ?></option>
            <option value="api" <?php selected( $current_option, 'api' ); ?>><?php esc_html_e( 'api.whatsapp.com/send (Puede sugerir app de escritorio)', 'woo-pagos-offline-avanzados' ); ?></option>
            <option value="protocol" <?php selected( $current_option, 'protocol' ); ?>><?php esc_html_e( 'whatsapp://send (Intenta abrir la app de escritorio directamente)', 'woo-pagos-offline-avanzados' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Elige cómo se abrirá WhatsApp en dispositivos de escritorio (ordenadores).', 'woo-pagos-offline-avanzados' ); ?></p>
        <?php
    }

    /**
     * Saneamiento de los datos de la cuenta bancaria.
     */
    public function sanitize_bank_account_data( $input ) {
        $sanitized = array();
        if ( isset( $input['alias'] ) ) {
            $sanitized['alias'] = sanitize_text_field( $input['alias'] );
        }
        if ( isset( $input['cbu'] ) ) {
            $sanitized['cbu'] = sanitize_text_field( $input['cbu'] );
        }
        if ( isset( $input['titular'] ) ) {
            $sanitized['titular'] = sanitize_text_field( $input['titular'] );
        }
        if ( isset( $input['banco'] ) ) {
            $sanitized['banco'] = sanitize_text_field( $input['banco'] );
        }
        return $sanitized;
    }

    /**
     * Saneamiento personalizado para el número de WhatsApp.
     */
    public function sanitize_whatsapp_number( $number ) {
        error_log( 'WPOA WhatsApp Saneamiento: Valor recibido: ' . $number );
        $sanitized_number = preg_replace('/[^0-9]/', '', $number);
        error_log( 'WPOA WhatsApp Saneamiento: Valor saneado: ' . $sanitized_number );
        return sanitize_text_field( $sanitized_number );
    }

    /**
     * Contenido de la página de ajustes.
     */
    public function settings_page_content() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ajustes de Pagos Offline Avanzados', 'woo-pagos-offline-avanzados' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'wpoa-pagos-offline-avanzados-settings' );
                do_settings_sections( 'wpoa-pagos-offline-avanzados-settings' );
                submit_button( __( 'Guardar Cambios', 'woo-pagos-offline-avanzados' ) );
                ?>
            </form>
        </div>
        <?php
    }
}