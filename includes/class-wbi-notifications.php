<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Notification Center Module
 *
 * Centro de alertas unificado con badge en la barra de administración
 * y página de gestión de notificaciones agrupadas por severidad.
 */
class WBI_Notifications_Module {

    // Transient TTL (5 min)
    const CACHE_TTL = 300;
    const TRANSIENT = 'wbi_notification_counts';

    public function __construct() {
        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // Admin bar badge
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 999 );

        // Dashboard widget
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

        // Admin head CSS for badge
        add_action( 'admin_head', array( $this, 'inject_badge_css' ) );

        // Cache invalidation hooks
        add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate_cache' ) );
        add_action( 'save_post_product', array( $this, 'invalidate_cache' ) );
        add_action( 'woocommerce_update_product', array( $this, 'invalidate_cache' ) );
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public function invalidate_cache() {
        delete_transient( self::TRANSIENT );
    }

    // -------------------------------------------------------------------------
    // Notification computation
    // -------------------------------------------------------------------------

    /**
     * Returns an array of notification objects:
     * [ 'severity' => 'critical|warning|info', 'icon' => '...', 'message' => '...', 'link' => '...', 'id' => '...' ]
     */
    public function get_notifications() {
        global $wpdb;
        $notifications = array();

        // 1. Stock crítico (≤ threshold)
        $threshold = absint( get_option( 'wbi_stock_alert_threshold', 5 ) );
        $critical_stock = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_stock'
             AND CAST(pm.meta_value AS SIGNED) <= %d
             AND CAST(pm.meta_value AS SIGNED) >= 0
             AND p.post_type = 'product'
             AND p.post_status = 'publish'",
            $threshold
        ) );
        if ( $critical_stock > 0 ) {
            $notifications[] = array(
                'severity' => 'critical',
                'icon'     => '🔴',
                'message'  => sprintf( '%d producto(s) con stock crítico (≤ %d unidades)', $critical_stock, $threshold ),
                'link'     => admin_url( 'admin.php?page=wbi-products&stock_filter=critical' ),
                'id'       => 'stock_critical',
            );
        }

        // 2. Stock bajo (≤ 2× threshold)
        $low_threshold = $threshold * 2;
        $low_stock = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_stock'
             AND CAST(pm.meta_value AS SIGNED) > %d
             AND CAST(pm.meta_value AS SIGNED) <= %d
             AND p.post_type = 'product'
             AND p.post_status = 'publish'",
            $threshold,
            $low_threshold
        ) );
        if ( $low_stock > 0 ) {
            $notifications[] = array(
                'severity' => 'warning',
                'icon'     => '🟡',
                'message'  => sprintf( '%d producto(s) con stock bajo (≤ %d unidades)', $low_stock, $low_threshold ),
                'link'     => admin_url( 'admin.php?page=wbi-products&stock_filter=low' ),
                'id'       => 'stock_low',
            );
        }

        // 3. Scoring bajo (if scoring module active)
        if ( class_exists( 'WBI_Scoring_Module' ) ) {
            $low_score = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                 WHERE meta_key = 'wbi_rfm_score'
                 AND CAST(meta_value AS SIGNED) <= 2"
            );
            if ( $low_score > 0 ) {
                $notifications[] = array(
                    'severity' => 'warning',
                    'icon'     => '⭐',
                    'message'  => sprintf( '%d cliente(s) con scoring RFM bajo (≤ 2)', $low_score ),
                    'link'     => admin_url( 'admin.php?page=wbi-scoring' ),
                    'id'       => 'scoring_low',
                );
            }
        }

        // 4. Licencia por vencer
        if ( class_exists( 'WBI_License_Manager' ) ) {
            $plan = WBI_License_Manager::get_plan_info();
            if ( $plan['days_remaining'] > 0 && $plan['days_remaining'] <= 7 ) {
                $notifications[] = array(
                    'severity' => 'critical',
                    'icon'     => '🔑',
                    'message'  => sprintf( 'Licencia WBI vence en %d día(s)', $plan['days_remaining'] ),
                    'link'     => admin_url( 'admin.php?page=wbi-license' ),
                    'id'       => 'license_expiring',
                );
            }
        }

        // 5. Pedidos sin armar (en processing > 48h)
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );
        $pending_orders = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
             AND post_status = 'wc-processing'
             AND post_date < %s",
            $cutoff
        ) );
        if ( $pending_orders > 0 ) {
            $notifications[] = array(
                'severity' => 'warning',
                'icon'     => '📦',
                'message'  => sprintf( '%d pedido(s) en proceso sin armar por más de 48h', $pending_orders ),
                'link'     => admin_url( 'edit.php?post_type=shop_order&post_status=wc-processing' ),
                'id'       => 'orders_pending',
            );
        }

        // 6. Productos sin código de barra (if barcode module active)
        if ( class_exists( 'WBI_Barcode_Module' ) ) {
            $no_barcode = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wbi_barcode'
                 WHERE p.post_type = 'product'
                 AND p.post_status = 'publish'
                 AND (pm.meta_value IS NULL OR pm.meta_value = '')"
            );
            if ( $no_barcode > 0 ) {
                $notifications[] = array(
                    'severity' => 'info',
                    'icon'     => '📊',
                    'message'  => sprintf( '%d producto(s) sin código de barra asignado', $no_barcode ),
                    'link'     => admin_url( 'admin.php?page=wbi-barcode' ),
                    'id'       => 'barcode_missing',
                );
            }
        }

        // 7. Flujo de caja negativo (if cashflow module active)
        if ( class_exists( 'WBI_Cashflow_Module' ) ) {
            $cashflow_data = get_option( 'wbi_cashflow_last_balance', null );
            if ( $cashflow_data !== null && floatval( $cashflow_data ) < 0 ) {
                $notifications[] = array(
                    'severity' => 'critical',
                    'icon'     => '💰',
                    'message'  => 'Flujo de caja proyectado negativo — revisar gastos e ingresos',
                    'link'     => admin_url( 'admin.php?page=wbi-cashflow' ),
                    'id'       => 'cashflow_negative',
                );
            }
        }

        return $notifications;
    }

    /**
     * Get cached counts by severity.
     */
    public function get_counts() {
        $cached = get_transient( self::TRANSIENT );
        if ( false !== $cached ) return $cached;

        $notifications = $this->get_notifications();
        $counts = array( 'critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0 );
        foreach ( $notifications as $n ) {
            $counts[ $n['severity'] ]++;
            $counts['total']++;
        }
        set_transient( self::TRANSIENT, $counts, self::CACHE_TTL );
        return $counts;
    }

    // -------------------------------------------------------------------------
    // Admin bar badge
    // -------------------------------------------------------------------------

    public function inject_badge_css() {
        echo '<style>
        #wp-admin-bar-wbi-notifications .ab-label {
            background: #cc1818;
            color: #fff;
            border-radius: 10px;
            padding: 1px 6px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 4px;
            vertical-align: middle;
        }
        #wp-admin-bar-wbi-notifications.has-critical > .ab-item {
            background: #cc1818 !important;
            color: #fff !important;
        }
        </style>';
    }

    public function add_admin_bar_node( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $counts = $this->get_counts();
        $total  = $counts['total'];
        $label  = '🔔 WBI' . ( $total > 0 ? ' <span class="ab-label">' . $total . '</span>' : '' );

        $wp_admin_bar->add_node( array(
            'id'    => 'wbi-notifications',
            'title' => $label,
            'href'  => admin_url( 'admin.php?page=wbi-notifications' ),
            'meta'  => array(
                'class' => $counts['critical'] > 0 ? 'has-critical' : '',
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Dashboard widget
    // -------------------------------------------------------------------------

    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'wbi_notifications_widget',
            '🔔 wooErp — Notificaciones',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        $notifications = $this->get_notifications();
        if ( empty( $notifications ) ) {
            echo '<p style="color:green;">✅ Todo en orden. Sin alertas activas.</p>';
            return;
        }
        $top5 = array_slice( $notifications, 0, 5 );
        echo '<ul style="margin:0;padding:0;list-style:none;">';
        foreach ( $top5 as $n ) {
            echo '<li style="padding:5px 0; border-bottom:1px solid #eee;">';
            echo esc_html( $n['icon'] ) . ' ';
            echo '<a href="' . esc_url( $n['link'] ) . '">' . esc_html( $n['message'] ) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
        if ( count( $notifications ) > 5 ) {
            echo '<p style="margin:8px 0 0;"><a href="' . esc_url( admin_url( 'admin.php?page=wbi-notifications' ) ) . '">Ver todas las notificaciones →</a></p>';
        }
    }

    // -------------------------------------------------------------------------
    // Admin menu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Notificaciones',
            '<span class="dashicons dashicons-bell" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Notificaciones',
            'manage_options',
            'wbi-notifications',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $uid       = get_current_user_id();
        $dismissed = get_user_meta( $uid, '_wbi_dismissed_notifications', true );
        $dismissed = is_array( $dismissed ) ? $dismissed : array();

        // Handle dismiss
        if ( isset( $_POST['wbi_dismiss_notification'] ) ) {
            check_admin_referer( 'wbi_dismiss_notification' );
            $nid       = sanitize_text_field( wp_unslash( $_POST['wbi_dismiss_notification'] ) );
            $dismissed[] = $nid;
            $dismissed   = array_unique( $dismissed );
            update_user_meta( $uid, '_wbi_dismissed_notifications', $dismissed );
        }

        // Handle clear all dismissed
        if ( isset( $_POST['wbi_clear_dismissed'] ) ) {
            check_admin_referer( 'wbi_clear_dismissed' );
            $dismissed = array();
            update_user_meta( $uid, '_wbi_dismissed_notifications', $dismissed );
        }

        $all_notifications = $this->get_notifications();
        $active = array_filter( $all_notifications, function( $n ) use ( $dismissed ) {
            return ! in_array( $n['id'], $dismissed, true );
        });

        $groups = array(
            'critical' => array( 'label' => '🔴 Crítico', 'color' => '#cc1818', 'items' => array() ),
            'warning'  => array( 'label' => '🟡 Advertencia', 'color' => '#dba617', 'items' => array() ),
            'info'     => array( 'label' => 'ℹ️ Información', 'color' => '#0073aa', 'items' => array() ),
        );
        foreach ( $active as $n ) {
            $groups[ $n['severity'] ]['items'][] = $n;
        }

        echo '<div class="wrap">';
        echo '<h1>🔔 Centro de Notificaciones</h1>';
        echo '<p>Las notificaciones se actualizan automáticamente en cada carga de página.</p>';

        if ( empty( $active ) ) {
            echo '<div style="background:#edfaed; border:1px solid #3ec44e; padding:20px; border-radius:4px;">';
            echo '<p style="color:green; font-size:16px; margin:0;">✅ ¡Todo en orden! No hay alertas activas.</p>';
            echo '</div>';
        } else {
            foreach ( $groups as $severity => $group ) {
                if ( empty( $group['items'] ) ) continue;
                echo '<h2 style="color:' . esc_attr( $group['color'] ) . ';">' . esc_html( $group['label'] ) . '</h2>';
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>Notificación</th><th style="width:120px;">Acción</th><th style="width:120px;">Descartar</th></tr></thead><tbody>';
                foreach ( $group['items'] as $n ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( $n['icon'] ) . ' ' . esc_html( $n['message'] ) . '</td>';
                    echo '<td><a href="' . esc_url( $n['link'] ) . '" class="button button-small">Revisar →</a></td>';
                    echo '<td>';
                    echo '<form method="post">';
                    wp_nonce_field( 'wbi_dismiss_notification' );
                    echo '<input type="hidden" name="wbi_dismiss_notification" value="' . esc_attr( $n['id'] ) . '">';
                    echo '<button type="submit" class="button button-small">Marcar como leída</button>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }

        if ( ! empty( $dismissed ) ) {
            echo '<p style="margin-top:20px;">';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'wbi_clear_dismissed' );
            echo '<input type="hidden" name="wbi_clear_dismissed" value="1">';
            echo '<button type="submit" class="button">🔄 Restablecer notificaciones descartadas (' . count( $dismissed ) . ')</button>';
            echo '</form></p>';
        }

        echo '</div>';
    }
}
