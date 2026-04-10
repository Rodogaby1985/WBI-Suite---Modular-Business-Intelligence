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

        echo '<div class="wrap">';
        echo '<h1>💬 WhatsApp — Notificaciones</h1>';

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
        $order_id = $order->get_id();

        // Customer info
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();
        $email      = $order->get_billing_email();
        $phone      = $order->get_billing_phone();
        $address    = $order->get_billing_address_1();
        $city       = $order->get_billing_city();
        $postcode   = $order->get_billing_postcode();
        $state_raw  = $order->get_billing_state();

        // Convert province code to full name if WBI_Metrics_Engine is available
        if ( class_exists( 'WBI_Metrics_Engine' ) ) {
            $state = WBI_Metrics_Engine::get_province_name( $state_raw );
            if ( empty( $state ) ) {
                $state = $state_raw;
            }
        } else {
            $state = $state_raw;
        }

        // Build product lines
        $items_text = '';
        foreach ( $order->get_items() as $item ) {
            $product_name = $item->get_name();
            $qty          = $item->get_quantity();
            $line_total   = number_format( (float) $item->get_total(), 2, ',', '.' );
            $items_text  .= '• ' . $product_name . ' x' . $qty . ' — $' . $line_total . "\n";
        }

        // Totals
        $subtotal       = number_format( (float) $order->get_subtotal(), 2, ',', '.' );
        $shipping_total = number_format( (float) $order->get_shipping_total(), 2, ',', '.' );
        $order_total    = number_format( (float) $order->get_total(), 2, ',', '.' );
        $payment_method = $order->get_payment_method_title();
        $site_name      = get_bloginfo( 'name' );

        $message  = '🛒 *Nuevo Pedido #' . $order_id . '*' . "\n\n";
        $message .= '👤 *Cliente:* ' . $first_name . ' ' . $last_name . "\n";
        $message .= '📧 *Email:* ' . $email . "\n";
        $message .= '📞 *Teléfono:* ' . $phone . "\n";
        $message .= '🏠 *Dirección:* ' . $address . ', ' . $city . ', ' . $state . "\n";
        $message .= '📮 *CP:* ' . $postcode . "\n\n";
        $message .= '📦 *Productos:*' . "\n";
        $message .= $items_text . "\n";
        $message .= '💰 *Subtotal:* $' . $subtotal . "\n";
        $message .= '🚚 *Envío:* $' . $shipping_total . "\n";
        $message .= '💵 *Total:* $' . $order_total . "\n\n";
        $message .= '🔗 *Método de pago:* ' . $payment_method . "\n\n";
        $message .= 'Pedido realizado desde ' . $site_name;

        return $message;
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

        $message     = $this->build_order_message( $order );
        $encoded_msg = rawurlencode( $message );
        $wa_url      = 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . $encoded_msg;

        echo '<div style="text-align:center; margin:30px 0; padding:20px; background:#f0faf0; border:2px solid #25D366; border-radius:8px;">';
        echo '<p style="font-size:16px; margin-bottom:12px; color:#333;">📲 ¿Querés enviarnos tu pedido por WhatsApp?</p>';
        echo '<a href="' . esc_url( $wa_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block; background:#25D366; color:#fff; padding:14px 32px; border-radius:6px; font-size:18px; font-weight:bold; text-decoration:none;">';
        echo '💬 Enviar Pedido por WhatsApp';
        echo '</a>';
        echo '</div>';
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

        $message     = $this->build_order_message( $order );
        $encoded_msg = rawurlencode( $message );
        $wa_url      = 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . $encoded_msg;

        if ( $plain_text ) {
            echo "\n💬 Enviá tu pedido por WhatsApp: " . esc_url( $wa_url ) . "\n";
        } else {
            echo '<p style="text-align:center; margin:20px 0;">';
            echo '<a href="' . esc_url( $wa_url ) . '" style="color:#25D366; font-weight:bold;" target="_blank" rel="noopener noreferrer">';
            echo '💬 Enviar detalle del pedido por WhatsApp';
            echo '</a>';
            echo '</p>';
        }
    }
}
