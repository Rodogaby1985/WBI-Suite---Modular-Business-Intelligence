<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI WhatsApp Notifications Module
 *
 * Envía notificaciones automáticas por WhatsApp (wa.me) al cliente
 * cuando cambia el estado de un pedido WooCommerce.
 */
class WBI_Whatsapp_Module {

    // Statuses that can be tracked
    private $supported_statuses = array(
        'processing'   => 'En proceso',
        'completed'    => 'Completado',
        'on-hold'      => 'En espera',
        'cancelled'    => 'Cancelado',
        'shipped'      => 'Enviado',
        'picking-ready'=> 'Listo para retirar',
    );

    // Statuses for which the WhatsApp order button is shown to customers
    private $thankyou_valid_statuses = array( 'processing', 'completed', 'on-hold' );

    public function __construct() {
        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // Order status change hook
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 4 );

        // Admin notice after order save (show wa.me link if message was queued)
        add_action( 'admin_notices', array( $this, 'show_whatsapp_notice' ) );

        // Meta box on order edit screen
        add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );

        // Settings section for WBI Config
        add_action( 'admin_init', array( $this, 'register_whatsapp_settings' ) );

        // Thank you page button for customer to send order details to store via WhatsApp
        add_action( 'woocommerce_thankyou', array( $this, 'render_whatsapp_order_button' ), 20 );

        // Email: add WhatsApp link after order table (customer emails only)
        add_action( 'woocommerce_email_after_order_table', array( $this, 'render_whatsapp_order_email_link' ), 20, 4 );

        // Register query var and template redirect for public order receipt
        add_filter( 'query_vars', array( $this, 'add_receipt_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'render_order_receipt_page' ) );

        // Floating WhatsApp bubble on frontend
        add_action( 'wp_footer', array( $this, 'render_floating_bubble' ) );
    }

    // -------------------------------------------------------------------------
    // Admin Menu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'WhatsApp Notificaciones',
            '<span class="dashicons dashicons-phone" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> WhatsApp',
            'manage_options',
            'wbi-whatsapp',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Settings registration
    // -------------------------------------------------------------------------

    public function register_whatsapp_settings() {
        add_settings_section(
            'wbi_whatsapp_section',
            '💬 Notificaciones WhatsApp',
            null,
            'wbi-settings'
        );

        add_settings_field(
            'wbi_whatsapp_phone',
            'Teléfono WhatsApp Business',
            array( $this, 'render_phone_field' ),
            'wbi-settings',
            'wbi_whatsapp_section'
        );

        add_settings_field(
            'wbi_whatsapp_statuses',
            'Estados que disparan notificación',
            array( $this, 'render_statuses_field' ),
            'wbi-settings',
            'wbi_whatsapp_section'
        );

        add_settings_field(
            'wbi_whatsapp_templates',
            'Plantillas de mensaje',
            array( $this, 'render_templates_field' ),
            'wbi-settings',
            'wbi_whatsapp_section'
        );
    }

    public function render_phone_field() {
        $opts  = get_option( 'wbi_whatsapp_settings', array() );
        $phone = isset( $opts['phone'] ) ? esc_attr( $opts['phone'] ) : '';
        echo '<input type="text" name="wbi_whatsapp_settings[phone]" value="' . $phone . '" class="regular-text" placeholder="5491112345678">';
        echo '<p class="description">Número en formato internacional sin + (ej: 5491112345678)</p>';
    }

    public function render_statuses_field() {
        $opts     = get_option( 'wbi_whatsapp_settings', array() );
        $enabled  = isset( $opts['statuses'] ) ? (array) $opts['statuses'] : array( 'processing', 'completed' );
        foreach ( $this->supported_statuses as $key => $label ) {
            $checked = in_array( $key, $enabled, true ) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:4px;">';
            echo '<input type="checkbox" name="wbi_whatsapp_settings[statuses][]" value="' . esc_attr( $key ) . '" ' . $checked . '> ';
            echo esc_html( $label );
            echo '</label>';
        }
    }

    public function render_templates_field() {
        $opts      = get_option( 'wbi_whatsapp_settings', array() );
        $templates = isset( $opts['templates'] ) ? (array) $opts['templates'] : array();
        $defaults  = $this->get_default_templates();
        echo '<p class="description">Placeholders: <code>{order_number}</code>, <code>{customer_name}</code>, <code>{order_total}</code>, <code>{tracking_number}</code>, <code>{site_name}</code></p>';
        foreach ( $this->supported_statuses as $key => $label ) {
            $tpl = isset( $templates[ $key ] ) ? $templates[ $key ] : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
            echo '<p><strong>' . esc_html( $label ) . '</strong><br>';
            echo '<textarea name="wbi_whatsapp_settings[templates][' . esc_attr( $key ) . ']" rows="3" style="width:100%;max-width:600px;">' . esc_textarea( $tpl ) . '</textarea></p>';
        }
    }

    private function get_default_templates() {
        return array(
            'processing'    => "¡Hola {customer_name}! Tu pedido #{order_number} en {site_name} está *en proceso*. Total: {order_total}. ¡Gracias por tu compra!",
            'completed'     => "¡Hola {customer_name}! Tu pedido #{order_number} en {site_name} fue *completado*. ¡Esperamos verte pronto!",
            'on-hold'       => "¡Hola {customer_name}! Tu pedido #{order_number} en {site_name} está *en espera*. Nos comunicaremos pronto.",
            'cancelled'     => "¡Hola {customer_name}! Tu pedido #{order_number} en {site_name} fue *cancelado*. Si tenés dudas, contactanos.",
            'shipped'       => "¡Hola {customer_name}! Tu pedido #{order_number} fue *enviado*. Número de seguimiento: {tracking_number}.",
            'picking-ready' => "¡Hola {customer_name}! Tu pedido #{order_number} en {site_name} está *listo para retirar*.",
        );
    }

    // -------------------------------------------------------------------------
    // Order status change hook
    // -------------------------------------------------------------------------

    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        $opts     = get_option( 'wbi_whatsapp_settings', array() );
        $enabled  = isset( $opts['statuses'] ) ? (array) $opts['statuses'] : array();

        if ( ! in_array( $new_status, $enabled, true ) ) return;

        $templates = isset( $opts['templates'] ) ? (array) $opts['templates'] : array();
        $defaults  = $this->get_default_templates();
        $template  = isset( $templates[ $new_status ] ) ? $templates[ $new_status ] : ( isset( $defaults[ $new_status ] ) ? $defaults[ $new_status ] : '' );

        if ( empty( $template ) ) return;

        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $message       = str_replace(
            array( '{order_number}', '{customer_name}', '{order_total}', '{tracking_number}', '{site_name}' ),
            array(
                $order->get_order_number(),
                trim( $customer_name ),
                wc_price( $order->get_total() ),
                get_post_meta( $order_id, '_wbi_tracking_number', true ),
                get_bloginfo( 'name' ),
            ),
            $template
        );

        $phone = preg_replace( '/\D/', '', $order->get_billing_phone() );
        $url   = 'https://wa.me/' . $phone . '?text=' . rawurlencode( wp_strip_all_tags( $message ) );

        // Log to order meta
        $log   = get_post_meta( $order_id, '_wbi_whatsapp_log', true );
        $log   = is_array( $log ) ? $log : array();
        $log[] = array(
            'date'     => current_time( 'Y-m-d H:i:s' ),
            'status'   => $new_status,
            'message'  => $message,
            'phone'    => $phone,
            'sent_by'  => 'auto',
            'url'      => $url,
        );
        update_post_meta( $order_id, '_wbi_whatsapp_log', $log );

        // Store notice for current admin session
        $notices   = get_transient( 'wbi_whatsapp_notice_' . get_current_user_id() );
        $notices   = is_array( $notices ) ? $notices : array();
        $notices[] = array(
            'order_id' => $order_id,
            'url'      => $url,
            'phone'    => $phone,
            'status'   => $new_status,
        );
        set_transient( 'wbi_whatsapp_notice_' . get_current_user_id(), $notices, 120 );
    }

    // -------------------------------------------------------------------------
    // Admin notice with wa.me button
    // -------------------------------------------------------------------------

    public function show_whatsapp_notice() {
        $uid     = get_current_user_id();
        $notices = get_transient( 'wbi_whatsapp_notice_' . $uid );
        if ( empty( $notices ) ) return;
        delete_transient( 'wbi_whatsapp_notice_' . $uid );

        foreach ( $notices as $n ) {
            $status_label = isset( $this->supported_statuses[ $n['status'] ] ) ? $this->supported_statuses[ $n['status'] ] : $n['status'];
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p>📱 <strong>WBI WhatsApp:</strong> Pedido #' . intval( $n['order_id'] ) . ' cambió a <em>' . esc_html( $status_label ) . '</em>. ';
            echo '<a href="' . esc_url( $n['url'] ) . '" target="_blank" class="button button-small">📱 Enviar WhatsApp</a></p>';
            echo '</div>';
        }
    }

    // -------------------------------------------------------------------------
    // Order meta box
    // -------------------------------------------------------------------------

    public function add_order_metabox() {
        $screen = class_exists( 'WC_Order' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        add_meta_box(
            'wbi_whatsapp_metabox',
            '💬 WhatsApp',
            array( $this, 'render_order_metabox' ),
            $screen,
            'side',
            'default'
        );
        // Also add for HPOS
        add_meta_box(
            'wbi_whatsapp_metabox',
            '💬 WhatsApp',
            array( $this, 'render_order_metabox' ),
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_order_metabox( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order_id = $post_or_order->ID;
            $order    = wc_get_order( $order_id );
        } else {
            $order    = $post_or_order;
            $order_id = $order->get_id();
        }

        if ( ! $order ) return;

        $log      = get_post_meta( $order_id, '_wbi_whatsapp_log', true );
        $log      = is_array( $log ) ? $log : array();
        $phone    = preg_replace( '/\D/', '', $order->get_billing_phone() );
        $defaults = $this->get_default_templates();

        echo '<div style="font-size:12px;">';
        if ( empty( $log ) ) {
            echo '<p style="color:#777;">Sin mensajes enviados.</p>';
        } else {
            echo '<table style="width:100%;border-collapse:collapse;">';
            foreach ( array_reverse( $log ) as $entry ) {
                $status_label = isset( $this->supported_statuses[ $entry['status'] ] ) ? $this->supported_statuses[ $entry['status'] ] : $entry['status'];
                echo '<tr><td style="border-bottom:1px solid #eee;padding:4px 0;">';
                echo '<strong>' . esc_html( $status_label ) . '</strong><br>';
                echo '<span style="color:#777;">' . esc_html( $entry['date'] ) . '</span><br>';
                echo '<a href="' . esc_url( $entry['url'] ) . '" target="_blank">📱 Reenviar</a>';
                echo '</td></tr>';
            }
            echo '</table>';
        }

        // Quick send
        if ( $phone ) {
            echo '<hr><strong>Envío rápido</strong>';
            echo '<select id="wbi_wa_tpl" style="width:100%;margin:5px 0;">';
            foreach ( $this->supported_statuses as $key => $label ) {
                echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '<button type="button" class="button button-small" onclick="wbiWaSend(' . intval( $order_id ) . ', \'' . esc_js( $phone ) . '\')">📱 Enviar</button>';
            echo '<script>
function wbiWaSend(oid, ph){
    var tpl = document.getElementById("wbi_wa_tpl").value;
    var base = "https://wa.me/" + ph + "?text=" + encodeURIComponent("Pedido #" + oid + " - estado: " + tpl);
    window.open(base, "_blank");
}
</script>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Admin page: log table
    // -------------------------------------------------------------------------

    public function render_page() {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) return;

        // -----------------------------------------------------------------------
        // 1. Handle POST: save configuration
        // -----------------------------------------------------------------------
        $saved = false;
        if ( isset( $_POST['wbi_whatsapp_config_nonce'] ) &&
             wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wbi_whatsapp_config_nonce'] ) ), 'wbi_whatsapp_config' )
        ) {
            $new_settings = array();

            // Phone
            $new_settings['phone'] = isset( $_POST['wbi_whatsapp_settings']['phone'] )
                ? sanitize_text_field( wp_unslash( $_POST['wbi_whatsapp_settings']['phone'] ) )
                : '';

            // Statuses
            $new_settings['statuses'] = array();
            if ( isset( $_POST['wbi_whatsapp_settings']['statuses'] ) && is_array( $_POST['wbi_whatsapp_settings']['statuses'] ) ) {
                foreach ( $_POST['wbi_whatsapp_settings']['statuses'] as $s ) {
                    $s = sanitize_text_field( wp_unslash( $s ) );
                    if ( array_key_exists( $s, $this->supported_statuses ) ) {
                        $new_settings['statuses'][] = $s;
                    }
                }
            }

            // Templates
            $new_settings['templates'] = array();
            if ( isset( $_POST['wbi_whatsapp_settings']['templates'] ) && is_array( $_POST['wbi_whatsapp_settings']['templates'] ) ) {
                foreach ( $_POST['wbi_whatsapp_settings']['templates'] as $status_key => $tpl ) {
                    $status_key = sanitize_text_field( wp_unslash( $status_key ) );
                    if ( array_key_exists( $status_key, $this->supported_statuses ) ) {
                        $new_settings['templates'][ $status_key ] = sanitize_textarea_field( wp_unslash( $tpl ) );
                    }
                }
            }

            // Bubble settings
            $new_settings['bubble_enabled']       = ! empty( $_POST['wbi_whatsapp_settings']['bubble_enabled'] );
            $new_settings['bubble_phone']         = isset( $_POST['wbi_whatsapp_settings']['bubble_phone'] )
                ? sanitize_text_field( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_phone'] ) )
                : '';
            $new_settings['bubble_message']       = isset( $_POST['wbi_whatsapp_settings']['bubble_message'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_message'] ) )
                : 'Hola! Me gustaría hacer una consulta.';
            $bubble_pos = isset( $_POST['wbi_whatsapp_settings']['bubble_position'] )
                ? sanitize_text_field( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_position'] ) )
                : 'right';
            $new_settings['bubble_position']      = in_array( $bubble_pos, array( 'right', 'left' ), true ) ? $bubble_pos : 'right';
            $new_settings['bubble_delay']         = isset( $_POST['wbi_whatsapp_settings']['bubble_delay'] )
                ? max( 0, min( 30, intval( $_POST['wbi_whatsapp_settings']['bubble_delay'] ) ) )
                : 3;
            $new_settings['bubble_tooltip']       = isset( $_POST['wbi_whatsapp_settings']['bubble_tooltip'] )
                ? sanitize_text_field( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_tooltip'] ) )
                : '¿Necesitás ayuda?';
            $new_settings['bubble_exclude_pages'] = isset( $_POST['wbi_whatsapp_settings']['bubble_exclude_pages'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_exclude_pages'] ) )
                : '';
            $new_settings['bubble_color']         = isset( $_POST['wbi_whatsapp_settings']['bubble_color'] )
                ? ( sanitize_hex_color( wp_unslash( $_POST['wbi_whatsapp_settings']['bubble_color'] ) ) ?: '#25D366' )
                : '#25D366';

            update_option( 'wbi_whatsapp_settings', $new_settings );
            $saved = true;
        }

        // -----------------------------------------------------------------------
        // 2. Show save notice
        // -----------------------------------------------------------------------
        $opts      = get_option( 'wbi_whatsapp_settings', array() );
        $phone     = isset( $opts['phone'] ) ? esc_attr( $opts['phone'] ) : '';
        $enabled   = isset( $opts['statuses'] ) ? (array) $opts['statuses'] : array( 'processing', 'completed' );
        $templates = isset( $opts['templates'] ) ? (array) $opts['templates'] : array();
        $defaults  = $this->get_default_templates();

        echo '<div class="wrap">';
        echo '<h1>💬 WhatsApp — Notificaciones</h1>';

        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Configuración guardada.</p></div>';
        }

        // -----------------------------------------------------------------------
        // 3. Configuration form
        // -----------------------------------------------------------------------
        ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;margin-bottom:30px;">
            <h2 style="margin-top:0;">⚙️ Configuración WhatsApp</h2>
            <form method="post">
                <?php wp_nonce_field( 'wbi_whatsapp_config', 'wbi_whatsapp_config_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wbi_wa_phone">Teléfono WhatsApp Business</label></th>
                        <td>
                            <input type="text" id="wbi_wa_phone" name="wbi_whatsapp_settings[phone]"
                                   value="<?php echo esc_attr( $phone ); ?>"
                                   class="regular-text" placeholder="5491112345678">
                            <p class="description">Número en formato internacional sin + (ej: 5491112345678). Este número recibe los pedidos desde la página de agradecimiento.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Estados que disparan notificación automática</th>
                        <td>
                            <?php foreach ( $this->supported_statuses as $key => $label ) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox"
                                           name="wbi_whatsapp_settings[statuses][]"
                                           value="<?php echo esc_attr( $key ); ?>"
                                           <?php checked( in_array( $key, $enabled, true ) ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Plantillas de mensaje por estado</th>
                        <td>
                            <p class="description" style="margin-bottom:10px;">
                                Placeholders disponibles:
                                <code>{order_number}</code>,
                                <code>{customer_name}</code>,
                                <code>{order_total}</code>,
                                <code>{tracking_number}</code>,
                                <code>{site_name}</code>
                            </p>
                            <?php foreach ( $this->supported_statuses as $key => $label ) :
                                $tpl = isset( $templates[ $key ] ) ? $templates[ $key ] : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
                            ?>
                                <p>
                                    <strong><?php echo esc_html( $label ); ?></strong><br>
                                    <textarea name="wbi_whatsapp_settings[templates][<?php echo esc_attr( $key ); ?>]"
                                              rows="3"
                                              style="width:100%;max-width:600px;"><?php echo esc_textarea( $tpl ); ?></textarea>
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <hr style="margin:30px 0;">
                <h2>💬 Burbuja Flotante</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_enabled">Activar burbuja flotante</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wbi_wa_bubble_enabled"
                                       name="wbi_whatsapp_settings[bubble_enabled]" value="1"
                                       <?php checked( ! empty( $opts['bubble_enabled'] ) ); ?>>
                                Mostrar botón flotante de WhatsApp en el frontend
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_phone">Número de WhatsApp para la burbuja</label></th>
                        <td>
                            <input type="text" id="wbi_wa_bubble_phone"
                                   name="wbi_whatsapp_settings[bubble_phone]"
                                   value="<?php echo esc_attr( isset( $opts['bubble_phone'] ) ? $opts['bubble_phone'] : '' ); ?>"
                                   class="regular-text" placeholder="5491112345678">
                            <p class="description">Si queda vacío, se usa el número principal de arriba.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_message">Mensaje predefinido</label></th>
                        <td>
                            <textarea id="wbi_wa_bubble_message"
                                      name="wbi_whatsapp_settings[bubble_message]"
                                      rows="3"
                                      style="width:100%;max-width:600px;"><?php echo esc_textarea( isset( $opts['bubble_message'] ) ? $opts['bubble_message'] : 'Hola! Me gustaría hacer una consulta.' ); ?></textarea>
                            <p class="description">El mensaje que se pre-carga cuando el cliente hace clic en la burbuja.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_position">Posición</label></th>
                        <td>
                            <select id="wbi_wa_bubble_position" name="wbi_whatsapp_settings[bubble_position]">
                                <option value="right" <?php selected( ( isset( $opts['bubble_position'] ) ? $opts['bubble_position'] : 'right' ), 'right' ); ?>>Inferior derecha</option>
                                <option value="left"  <?php selected( ( isset( $opts['bubble_position'] ) ? $opts['bubble_position'] : 'right' ), 'left' ); ?>>Inferior izquierda</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_delay">Demora de aparición (segundos)</label></th>
                        <td>
                            <input type="number" id="wbi_wa_bubble_delay"
                                   name="wbi_whatsapp_settings[bubble_delay]"
                                   value="<?php echo intval( isset( $opts['bubble_delay'] ) ? $opts['bubble_delay'] : 3 ); ?>"
                                   min="0" max="30" style="width:80px;">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_tooltip">Tooltip / texto del badge</label></th>
                        <td>
                            <input type="text" id="wbi_wa_bubble_tooltip"
                                   name="wbi_whatsapp_settings[bubble_tooltip]"
                                   value="<?php echo esc_attr( isset( $opts['bubble_tooltip'] ) ? $opts['bubble_tooltip'] : '¿Necesitás ayuda?' ); ?>"
                                   class="regular-text">
                            <p class="description">Texto que aparece al lado de la burbuja. Dejá vacío para no mostrar tooltip.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_exclude">Ocultar en estas páginas</label></th>
                        <td>
                            <textarea id="wbi_wa_bubble_exclude"
                                      name="wbi_whatsapp_settings[bubble_exclude_pages]"
                                      rows="4"
                                      style="width:100%;max-width:600px;"><?php echo esc_textarea( isset( $opts['bubble_exclude_pages'] ) ? $opts['bubble_exclude_pages'] : '' ); ?></textarea>
                            <p class="description">URLs o slugs donde no mostrar la burbuja, una por línea (ej: /checkout, /mi-cuenta).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wbi_wa_bubble_color">Color de fondo</label></th>
                        <td>
                            <input type="color" id="wbi_wa_bubble_color"
                                   name="wbi_whatsapp_settings[bubble_color]"
                                   value="<?php echo esc_attr( isset( $opts['bubble_color'] ) ? $opts['bubble_color'] : '#25D366' ); ?>">
                        </td>
                    </tr>
                </table>

                <p><button type="submit" class="button button-primary">💾 Guardar Configuración</button></p>
            </form>
        </div>
        <?php

        // -----------------------------------------------------------------------
        // 4. Log of sent notifications
        // -----------------------------------------------------------------------

        // Date filter
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : date( 'Y-m-d', strtotime( '-30 days' ) );
        $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : date( 'Y-m-d' );

        // Fetch recent orders with WhatsApp log
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wbi_whatsapp_log'
                 AND post_id IN (
                     SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'shop_order'
                     AND post_date BETWEEN %s AND %s
                 )
                 ORDER BY post_id DESC
                 LIMIT 50",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59'
            )
        );

        // Filter form
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="wbi-whatsapp">';
        echo '<label>Desde: <input type="date" name="date_from" value="' . esc_attr( $date_from ) . '"></label> ';
        echo '<label>Hasta: <input type="date" name="date_to" value="' . esc_attr( $date_to ) . '"></label> ';
        echo '<button type="submit" class="button">Filtrar</button>';
        echo '</form>';

        echo '<table class="wp-list-table widefat fixed striped wbi-sortable">';
        echo '<thead><tr><th>Fecha</th><th>#Pedido</th><th>Cliente</th><th>Teléfono</th><th>Estado</th><th>Mensaje</th><th>Acción</th></tr></thead><tbody>';

        if ( empty( $order_ids ) ) {
            echo '<tr><td colspan="7">Sin notificaciones en el período seleccionado.</td></tr>';
        } else {
            foreach ( $order_ids as $oid ) {
                $order = wc_get_order( intval( $oid ) );
                if ( ! $order ) continue;
                $log = get_post_meta( intval( $oid ), '_wbi_whatsapp_log', true );
                if ( ! is_array( $log ) ) continue;
                foreach ( array_reverse( $log ) as $entry ) {
                    $status_label = isset( $this->supported_statuses[ $entry['status'] ] ) ? $this->supported_statuses[ $entry['status'] ] : esc_html( $entry['status'] );
                    echo '<tr>';
                    echo '<td>' . esc_html( $entry['date'] ) . '</td>';
                    echo '<td><a href="' . esc_url( get_edit_post_link( $oid ) ) . '">#' . intval( $oid ) . '</a></td>';
                    echo '<td>' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</td>';
                    echo '<td>' . esc_html( $entry['phone'] ) . '</td>';
                    echo '<td>' . esc_html( $status_label ) . '</td>';
                    echo '<td>' . esc_html( substr( $entry['message'], 0, 80 ) ) . '…</td>';
                    echo '<td><a href="' . esc_url( $entry['url'] ) . '" target="_blank" class="button button-small">📱 Enviar</a></td>';
                    echo '</tr>';
                }
            }
        }

        echo '</tbody></table></div>';
    }

    // -------------------------------------------------------------------------
    // Thank you page: WhatsApp button to send order details to store
    // -------------------------------------------------------------------------

    /**
     * Build the WhatsApp message text for a given order.
     *
     * @param WC_Order $order
     * @return string  Plain text message (not URL-encoded).
     */
    private function build_order_message( $order ) {
        $order_id  = $order->get_id();
        $site_name = get_bloginfo( 'name' );
        $total     = number_format( (float) $order->get_total(), 2, ',', '.' );

        // Build receipt URL
        $receipt_url = add_query_arg( array(
            'wbi_order_receipt' => $order_id,
            'key'               => $order->get_order_key(),
        ), home_url( '/' ) );

        $message  = 'Hola! Acabo de realizar el pedido *#' . $order_id . '* en ' . $site_name . ".\n\n";
        $message .= 'Total: *$' . $total . "*\n\n";
        $message .= "Ver detalle completo:\n";
        $message .= $receipt_url;

        return $message;
    }

    // -------------------------------------------------------------------------
    // Public order receipt endpoint
    // -------------------------------------------------------------------------

    /**
     * Register the wbi_order_receipt query variable.
     *
     * @param string[] $vars Array of registered query variable names.
     * @return string[]
     */
    public function add_receipt_query_vars( $vars ) {
        $vars[] = 'wbi_order_receipt';
        return $vars;
    }

    /**
     * Intercept the request and render the order receipt page when the
     * wbi_order_receipt query var is present.
     */
    public function render_order_receipt_page() {
        $order_id = get_query_var( 'wbi_order_receipt' );
        if ( empty( $order_id ) ) {
            return;
        }

        $order_id = intval( $order_id );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Pedido no encontrado.', 'Error', array( 'response' => 404 ) );
        }

        // Validate order key for security
        $provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( $provided_key !== $order->get_order_key() ) {
            wp_die( 'Acceso no autorizado.', 'Error', array( 'response' => 403 ) );
        }

        // Render the receipt page and exit
        $this->output_receipt_html( $order );
        exit;
    }

    /**
     * Output a standalone HTML receipt page for the given order.
     *
     * @param WC_Order $order
     */
    private function output_receipt_html( $order ) {
        $order_id       = $order->get_id();
        $site_name      = get_bloginfo( 'name' );
        $site_url       = home_url( '/' );
        $first_name     = $order->get_billing_first_name();
        $last_name      = $order->get_billing_last_name();
        $email          = $order->get_billing_email();
        $phone          = $order->get_billing_phone();
        $address        = $order->get_billing_address_1();
        $city           = $order->get_billing_city();
        $postcode       = $order->get_billing_postcode();
        $state_raw      = $order->get_billing_state();
        $payment_method = $order->get_payment_method_title();
        $subtotal       = number_format( (float) $order->get_subtotal(), 2, ',', '.' );
        $shipping_total = number_format( (float) $order->get_shipping_total(), 2, ',', '.' );
        $order_total    = number_format( (float) $order->get_total(), 2, ',', '.' );

        // Convert province code to full name if WBI_Metrics_Engine is available
        if ( class_exists( 'WBI_Metrics_Engine' ) ) {
            $state = WBI_Metrics_Engine::get_province_name( $state_raw );
            if ( empty( $state ) ) {
                $state = $state_raw;
            }
        } else {
            $state = $state_raw;
        }

        // Build product rows HTML
        $rows_html = '';
        $alt       = false;
        foreach ( $order->get_items() as $item ) {
            $product_name  = esc_html( $item->get_name() );
            $qty           = intval( $item->get_quantity() );
            $unit_price    = number_format( (float) ( $item->get_total() / max( $qty, 1 ) ), 2, ',', '.' );
            $line_total    = number_format( (float) $item->get_total(), 2, ',', '.' );
            $row_bg        = $alt ? ' style="background:#f9f9f9;"' : '';
            $rows_html    .= '<tr' . $row_bg . '>';
            $rows_html    .= '<td style="padding:10px 12px;border-bottom:1px solid #eee;">' . $product_name . '</td>';
            $rows_html    .= '<td style="padding:10px 12px;border-bottom:1px solid #eee;text-align:center;">' . $qty . '</td>';
            $rows_html    .= '<td style="padding:10px 12px;border-bottom:1px solid #eee;text-align:right;">$' . esc_html( $unit_price ) . '</td>';
            $rows_html    .= '<td style="padding:10px 12px;border-bottom:1px solid #eee;text-align:right;">$' . esc_html( $line_total ) . '</td>';
            $rows_html    .= '</tr>';
            $alt           = ! $alt;
        }

        // Output a standalone HTML page. All dynamic values below are escaped
        // individually with esc_html() / esc_url() / intval(). The $rows_html
        // string is pre-escaped at construction time (lines above).
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<!DOCTYPE html>';
        echo '<html lang="es">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . esc_html( $site_name ) . ' — Pedido #' . intval( $order_id ) . '</title>';
        echo '<style>';
        echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f4f4f4;margin:0;padding:16px;color:#333;}';
        echo '.receipt{max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);}';
        echo '.receipt-header{background:#25D366;color:#fff;padding:24px 20px;text-align:center;}';
        echo '.receipt-header h1{margin:0 0 4px;font-size:20px;font-weight:700;}';
        echo '.receipt-header p{margin:0;font-size:14px;opacity:0.9;}';
        echo '.receipt-section{padding:18px 20px;border-bottom:1px solid #eee;}';
        echo '.receipt-section h2{margin:0 0 12px;font-size:13px;font-weight:600;text-transform:uppercase;color:#888;letter-spacing:0.5px;}';
        echo '.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;}';
        echo '.info-item label{display:block;font-size:11px;color:#999;margin-bottom:2px;}';
        echo '.info-item span{font-size:14px;color:#333;}';
        echo 'table{width:100%;border-collapse:collapse;}';
        echo 'thead th{background:#f7f7f7;padding:10px 12px;font-size:12px;font-weight:600;text-transform:uppercase;color:#888;letter-spacing:0.4px;text-align:left;border-bottom:2px solid #eee;}';
        echo 'thead th:not(:first-child){text-align:center;}';
        echo 'thead th:last-child{text-align:right;}';
        echo '.totals{padding:16px 20px;}';
        echo '.totals-row{display:flex;justify-content:space-between;padding:5px 0;font-size:14px;color:#555;}';
        echo '.totals-row.total-final{font-size:16px;font-weight:700;color:#222;border-top:2px solid #eee;margin-top:8px;padding-top:10px;}';
        echo '.receipt-footer{padding:16px 20px;text-align:center;font-size:12px;color:#aaa;background:#fafafa;border-top:1px solid #eee;}';
        echo '.receipt-footer a{color:#25D366;text-decoration:none;}';
        echo '@media(max-width:480px){.info-grid{grid-template-columns:1fr;}}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="receipt">';
        echo '<div class="receipt-header">';
        echo '<h1>' . esc_html( $site_name ) . '</h1>';
        echo '<p>Detalle de Pedido #' . intval( $order_id ) . '</p>';
        echo '</div>';
        echo '<div class="receipt-section">';
        echo '<h2>Datos del cliente</h2>';
        echo '<div class="info-grid">';
        echo '<div class="info-item"><label>Nombre</label><span>' . esc_html( $first_name . ' ' . $last_name ) . '</span></div>';
        echo '<div class="info-item"><label>Email</label><span>' . esc_html( $email ) . '</span></div>';
        echo '<div class="info-item"><label>Tel&eacute;fono</label><span>' . esc_html( $phone ) . '</span></div>';
        echo '<div class="info-item"><label>Direcci&oacute;n</label><span>' . esc_html( $address ) . '</span></div>';
        echo '<div class="info-item"><label>Ciudad</label><span>' . esc_html( $city ) . '</span></div>';
        echo '<div class="info-item"><label>Provincia</label><span>' . esc_html( $state ) . '</span></div>';
        echo '<div class="info-item"><label>C&oacute;digo Postal</label><span>' . esc_html( $postcode ) . '</span></div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="receipt-section">';
        echo '<h2>Productos</h2>';
        echo '<table>';
        echo '<thead><tr>';
        echo '<th>Producto</th>';
        echo '<th style="text-align:center;">Cant.</th>';
        echo '<th style="text-align:right;">Precio unit.</th>';
        echo '<th style="text-align:right;">Subtotal</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        echo $rows_html;
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '<div class="totals">';
        echo '<div class="totals-row"><span>Subtotal</span><span>$' . esc_html( $subtotal ) . '</span></div>';
        echo '<div class="totals-row"><span>Env&iacute;o</span><span>$' . esc_html( $shipping_total ) . '</span></div>';
        echo '<div class="totals-row total-final"><span>Total</span><span>$' . esc_html( $order_total ) . '</span></div>';
        echo '</div>';
        echo '<div class="receipt-section">';
        echo '<h2>M&eacute;todo de pago</h2>';
        echo '<p style="margin:0;font-size:14px;">' . esc_html( $payment_method ) . '</p>';
        echo '</div>';
        echo '<div class="receipt-footer">';
        echo '<a href="' . esc_url( $site_url ) . '">' . esc_html( $site_name ) . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</body>';
        echo '</html>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render the WhatsApp button on the WooCommerce thank you page.
     *
     * @param int $order_id
     */
    public function render_whatsapp_order_button( $order_id ) {
        $opts  = get_option( 'wbi_whatsapp_settings', array() );
        $phone = isset( $opts['phone'] ) ? trim( $opts['phone'] ) : '';

        if ( empty( $phone ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only show for valid order statuses
        if ( ! in_array( $order->get_status(), $this->thankyou_valid_statuses, true ) ) {
            return;
        }

        $message = $this->build_order_message( $order );
        $wa_url  = 'https://wa.me/' . preg_replace( '/\D/', '', $phone ) . '?text=' . urlencode( $message );

        echo '<div style="text-align:center; margin:24px auto; max-width:420px; padding:24px 20px; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.08);">';
        echo '<div style="margin-bottom:12px;">';
        echo '<svg width="32" height="32" viewBox="0 0 24 24" fill="#25D366" xmlns="http://www.w3.org/2000/svg">';
        echo '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>';
        echo '</svg>';
        echo '</div>';
        echo '<p style="font-size:14px; color:#666; margin:0 0 16px; font-weight:400; line-height:1.4;">¿Querés enviarnos el detalle de tu pedido?</p>';
        echo '<a href="' . esc_url( $wa_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; gap:8px; background:#25D366; color:#fff; padding:12px 28px; border-radius:50px; font-size:15px; font-weight:600; text-decoration:none; transition:background 0.2s ease; letter-spacing:0.3px;" onmouseover="this.style.background=\'#1DA851\'" onmouseout="this.style.background=\'#25D366\'">';
        echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
        echo 'Enviar por WhatsApp';
        echo '</a>';
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Frontend: floating WhatsApp bubble
    // -------------------------------------------------------------------------

    /**
     * Render the floating WhatsApp bubble in the frontend footer.
     * Only rendered when bubble is enabled and a phone number is configured.
     */
    public function render_floating_bubble() {
        if ( is_admin() ) {
            return;
        }

        $opts = get_option( 'wbi_whatsapp_settings', array() );

        if ( empty( $opts['bubble_enabled'] ) ) {
            return;
        }

        // Determine phone: use bubble-specific number if set, otherwise fall back to main phone
        $phone = ! empty( $opts['bubble_phone'] ) ? trim( $opts['bubble_phone'] ) : ( isset( $opts['phone'] ) ? trim( $opts['phone'] ) : '' );
        $phone = preg_replace( '/\D/', '', $phone );

        if ( empty( $phone ) ) {
            return;
        }

        // Check excluded pages
        if ( ! empty( $opts['bubble_exclude_pages'] ) ) {
            $current_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $excludes     = array_filter( array_map( 'trim', explode( "\n", $opts['bubble_exclude_pages'] ) ) );
            foreach ( $excludes as $excluded ) {
                if ( ! empty( $excluded ) && false !== strpos( $current_path, $excluded ) ) {
                    return;
                }
            }
        }

        $message  = ! empty( $opts['bubble_message'] ) ? $opts['bubble_message'] : 'Hola! Me gustaría hacer una consulta.';
        $position = ( isset( $opts['bubble_position'] ) && 'left' === $opts['bubble_position'] ) ? 'left' : 'right';
        $delay    = isset( $opts['bubble_delay'] ) ? max( 0, min( 30, intval( $opts['bubble_delay'] ) ) ) : 3;
        $tooltip  = isset( $opts['bubble_tooltip'] ) ? trim( $opts['bubble_tooltip'] ) : '¿Necesitás ayuda?';
        $color    = ! empty( $opts['bubble_color'] ) ? $opts['bubble_color'] : '#25D366';

        $wa_url      = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
        $delay_ms    = $delay * 1000;
        $side_css    = ( 'left' === $position ) ? 'left:20px;right:auto;' : 'right:20px;left:auto;';
        $tooltip_css = ( 'left' === $position ) ? 'left:70px;right:auto;' : 'right:70px;left:auto;';

        ?>
        <!-- WBI WhatsApp Floating Bubble -->
        <div id="wbi-wa-bubble" style="display:none;position:fixed;bottom:20px;<?php echo esc_attr( $side_css ); ?>z-index:99999;align-items:center;">
            <?php if ( ! empty( $tooltip ) ) : ?>
            <div id="wbi-wa-tooltip" style="position:absolute;bottom:12px;<?php echo esc_attr( $tooltip_css ); ?>background:#fff;padding:8px 14px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);font-size:13px;white-space:nowrap;display:flex;align-items:center;gap:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
                <span><?php echo esc_html( $tooltip ); ?></span>
                <button id="wbi-wa-tooltip-close" style="background:none;border:none;cursor:pointer;font-size:16px;line-height:1;padding:0;color:#999;" aria-label="Cerrar">×</button>
            </div>
            <?php endif; ?>
            <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer"
               id="wbi-wa-btn"
               style="display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:<?php echo esc_attr( $color ); ?>;box-shadow:0 4px 12px rgba(0,0,0,0.15);text-decoration:none;transition:transform 0.3s ease,box-shadow 0.3s ease;"
               aria-label="Contactar por WhatsApp">                <svg width="28" height="28" viewBox="0 0 24 24" fill="#fff" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
            </a>
        </div>
        <style>
        #wbi-wa-bubble { opacity: 0; transform: translateY(20px); }
        #wbi-wa-bubble.wbi-show { opacity: 1; transform: translateY(0); transition: opacity 0.4s ease, transform 0.4s ease; }
        #wbi-wa-btn:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.2) !important; }
        @media (max-width: 480px) {
            #wbi-wa-btn { width: 48px !important; height: 48px !important; }
            #wbi-wa-tooltip { display: none !important; }
        }
        </style>
        <script>
        (function(){
            var closeBtn = document.getElementById('wbi-wa-tooltip-close');
            if ( closeBtn ) {
                closeBtn.addEventListener('click', function(){
                    var tooltip = document.getElementById('wbi-wa-tooltip');
                    if ( tooltip ) { tooltip.style.display = 'none'; }
                });
            }
            setTimeout(function(){
                var bubble = document.getElementById('wbi-wa-bubble');
                if ( bubble ) {
                    bubble.style.display = 'flex';
                    requestAnimationFrame(function(){
                        requestAnimationFrame(function(){
                            bubble.classList.add('wbi-show');
                        });
                    });
                }
            }, <?php echo intval( $delay_ms ); ?>);
        })();
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Email: WhatsApp link after order table (customer emails only)
    // -------------------------------------------------------------------------

    /**
     * Add a WhatsApp link in customer confirmation emails.
     *
     * @param WC_Order $order
     * @param bool     $sent_to_admin
     * @param bool     $plain_text
     * @param WC_Email $email
     */
    public function render_whatsapp_order_email_link( $order, $sent_to_admin, $plain_text, $email ) {
        // Only for customer emails, not admin copies
        if ( $sent_to_admin ) {
            return;
        }

        $opts  = get_option( 'wbi_whatsapp_settings', array() );
        $phone = isset( $opts['phone'] ) ? trim( $opts['phone'] ) : '';

        if ( empty( $phone ) ) {
            return;
        }

        // Only for valid order statuses
        if ( ! in_array( $order->get_status(), $this->thankyou_valid_statuses, true ) ) {
            return;
        }

        $message = $this->build_order_message( $order );
        $wa_url  = 'https://wa.me/' . preg_replace( '/\D/', '', $phone ) . '?text=' . urlencode( $message );

        if ( $plain_text ) {
            echo "\n💬 Enviá tu pedido por WhatsApp: " . esc_url( $wa_url ) . "\n";
        } else {
            echo '<div style="text-align:center; margin:24px 0; padding:16px;">';
            echo '<a href="' . esc_url( $wa_url ) . '" style="display:inline-block; background:#25D366; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:50px; font-size:14px; font-weight:600;" target="_blank" rel="noopener noreferrer">';
            echo 'Enviar pedido por WhatsApp';
            echo '</a>';
            echo '</div>';
        }
    }
}
