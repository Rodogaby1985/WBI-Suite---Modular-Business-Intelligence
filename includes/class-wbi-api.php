<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI REST API Module
 *
 * Expone endpoints REST bajo el namespace wbi/v1 protegidos con API key.
 * Permite integración con aplicaciones externas.
 */
class WBI_API_Module {

    const NAMESPACE = 'wbi/v1';
    const REQUEST_LOG_OPTION = 'wbi_api_request_log';
    const USAGE_TRANSIENT    = 'wbi_api_usage';

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // REST API
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Settings section (for API key display)
        add_action( 'admin_init', array( $this, 'register_api_settings' ) );

        // Handle regenerate key action
        add_action( 'admin_post_wbi_regenerate_api_key', array( $this, 'handle_regenerate_key' ) );
    }

    // -------------------------------------------------------------------------
    // API Key Management
    // -------------------------------------------------------------------------

    public static function get_api_key() {
        $key = get_option( 'wbi_api_key', '' );
        if ( empty( $key ) ) {
            $key = self::generate_key();
            update_option( 'wbi_api_key', $key );
        }
        return $key;
    }

    private static function generate_key() {
        return 'wbi_' . bin2hex( random_bytes( 16 ) );
    }

    public function handle_regenerate_key() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_regenerate_api_key' );
        update_option( 'wbi_api_key', self::generate_key() );
        wp_redirect( admin_url( 'admin.php?page=wbi-api&regenerated=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    private function authenticate( WP_REST_Request $request ) {
        $api_key    = self::get_api_key();
        $provided   = $request->get_header( 'X-WBI-API-Key' );
        if ( empty( $provided ) ) {
            $provided = sanitize_text_field( $request->get_param( 'api_key' ) ?? '' );
        }
        return hash_equals( $api_key, (string) $provided );
    }

    private function auth_error() {
        return new WP_Error( 'wbi_unauthorized', 'API key inválida o ausente.', array( 'status' => 401 ) );
    }

    private function wrap( $data, $total = null, $page = 1, $per_page = 20 ) {
        $meta = array(
            'generated_at' => gmdate( 'c' ),
            'page'         => $page,
            'per_page'     => $per_page,
        );
        if ( $total !== null ) $meta['total'] = $total;
        return array( 'success' => true, 'data' => $data, 'meta' => $meta );
    }

    // -------------------------------------------------------------------------
    // Request logging
    // -------------------------------------------------------------------------

    private function log_request( $endpoint, $status = 200 ) {
        $log   = get_option( self::REQUEST_LOG_OPTION, array() );
        $log[] = array(
            'date'     => current_time( 'Y-m-d H:i:s' ),
            'endpoint' => $endpoint,
            'ip'       => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
            'status'   => $status,
        );
        // Keep last 50
        if ( count( $log ) > 50 ) $log = array_slice( $log, -50 );
        update_option( self::REQUEST_LOG_OPTION, $log );

        // Usage counters
        $today      = gmdate( 'Y-m-d' );
        $week_start = gmdate( 'Y-W' );
        $usage      = get_transient( self::USAGE_TRANSIENT ) ?: array( 'today' => 0, 'week' => 0, 'date' => $today, 'week_start' => $week_start );
        if ( $usage['date'] !== $today )           { $usage['today'] = 0; $usage['date'] = $today; }
        if ( $usage['week_start'] !== $week_start ) { $usage['week'] = 0; $usage['week_start'] = $week_start; }
        $usage['today']++;
        $usage['week']++;
        set_transient( self::USAGE_TRANSIENT, $usage, WEEK_IN_SECONDS );
    }

    // -------------------------------------------------------------------------
    // Common param helpers
    // -------------------------------------------------------------------------

    private function get_date_range( WP_REST_Request $request ) {
        $from = sanitize_text_field( $request->get_param( 'date_from' ) ?? date( 'Y-m-d', strtotime( '-30 days' ) ) );
        $to   = sanitize_text_field( $request->get_param( 'date_to' )   ?? date( 'Y-m-d' ) );
        return array( $from . ' 00:00:00', $to . ' 23:59:59' );
    }

    private function get_pagination( WP_REST_Request $request ) {
        $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?? 20 ) ) );
        $page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
        return array( $per_page, $page, ( $page - 1 ) * $per_page );
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function register_routes() {
        $ns = self::NAMESPACE;

        register_rest_route( $ns, '/dashboard',              array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_dashboard' ),         'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/products/best-sellers',  array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_best_sellers' ),       'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/products/stock',         array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_stock' ),              'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/products/stock-alerts',  array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_stock_alerts' ),       'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/orders/status-counts',   array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_order_status_counts' ),'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/customers/ranking',      array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_customer_ranking' ),   'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/customers/scoring',      array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_customer_scoring' ),   'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/sales/by-period',        array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_sales_by_period' ),    'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/sales/by-province',      array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_sales_by_province' ),  'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/invoices',               array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_invoices' ),           'permission_callback' => '__return_true' ) );
        register_rest_route( $ns, '/notifications',          array( 'methods' => 'GET', 'callback' => array( $this, 'endpoint_notifications' ),      'permission_callback' => '__return_true' ) );
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    public function endpoint_dashboard( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/dashboard', 401 ); return $this->auth_error(); }
        $this->log_request( '/dashboard' );

        list( $from, $to ) = $this->get_date_range( $request );
        $data = array(
            'revenue' => $this->engine->get_revenue( $from, $to ),
            'units'   => $this->engine->get_units_sold( $from, $to ),
            'orders'  => count( (array) $this->engine->get_order_status_counts() ),
            'aov'     => $this->engine->get_average_order_value( $from, $to ),
        );
        return rest_ensure_response( $this->wrap( $data ) );
    }

    public function endpoint_best_sellers( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/products/best-sellers', 401 ); return $this->auth_error(); }
        $this->log_request( '/products/best-sellers' );

        list( $from, $to ) = $this->get_date_range( $request );
        $data = $this->engine->get_best_sellers( $from, $to );
        return rest_ensure_response( $this->wrap( is_array( $data ) ? array_slice( $data, 0, 10 ) : array() ) );
    }

    public function endpoint_stock( WP_REST_Request $request ) {
        global $wpdb;
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/products/stock', 401 ); return $this->auth_error(); }
        $this->log_request( '/products/stock' );

        list( $per_page, $page, $offset ) = $this->get_pagination( $request );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'"
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS stock
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             ORDER BY CAST(pm.meta_value AS SIGNED) ASC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        $data = array_map( function( $r ) {
            return array( 'id' => intval( $r->ID ), 'name' => $r->post_title, 'stock' => intval( $r->stock ) );
        }, $rows );

        return rest_ensure_response( $this->wrap( $data, $total, $page, $per_page ) );
    }

    public function endpoint_stock_alerts( WP_REST_Request $request ) {
        global $wpdb;
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/products/stock-alerts', 401 ); return $this->auth_error(); }
        $this->log_request( '/products/stock-alerts' );

        $threshold = absint( get_option( 'wbi_stock_alert_threshold', 5 ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS stock
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             AND CAST(pm.meta_value AS SIGNED) <= %d
             ORDER BY CAST(pm.meta_value AS SIGNED) ASC",
            $threshold
        ) );

        $data = array_map( function( $r ) {
            return array( 'id' => intval( $r->ID ), 'name' => $r->post_title, 'stock' => intval( $r->stock ) );
        }, $rows );

        return rest_ensure_response( $this->wrap( $data, count( $data ) ) );
    }

    public function endpoint_order_status_counts( WP_REST_Request $request ) {
        global $wpdb;
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/orders/status-counts', 401 ); return $this->auth_error(); }
        $this->log_request( '/orders/status-counts' );

        $rows = $wpdb->get_results(
            "SELECT post_status, COUNT(*) AS count
             FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
             GROUP BY post_status"
        );
        $data = array();
        foreach ( $rows as $row ) {
            $data[ $row->post_status ] = intval( $row->count );
        }
        return rest_ensure_response( $this->wrap( $data ) );
    }

    public function endpoint_customer_ranking( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/customers/ranking', 401 ); return $this->auth_error(); }
        $this->log_request( '/customers/ranking' );

        list( $from, $to ) = $this->get_date_range( $request );
        list( $per_page, $page, $offset ) = $this->get_pagination( $request );
        $data = $this->engine->get_clients_ranking( 'revenue', $from, $to );
        $data = is_array( $data ) ? array_slice( $data, $offset, $per_page ) : array();
        return rest_ensure_response( $this->wrap( $data, null, $page, $per_page ) );
    }

    public function endpoint_customer_scoring( WP_REST_Request $request ) {
        global $wpdb;
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/customers/scoring', 401 ); return $this->auth_error(); }
        $this->log_request( '/customers/scoring' );

        if ( ! class_exists( 'WBI_Scoring_Module' ) ) {
            return new WP_Error( 'wbi_module_inactive', 'El módulo de Scoring no está activo.', array( 'status' => 404 ) );
        }

        list( $per_page, $page, $offset ) = $this->get_pagination( $request );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID, u.user_email, um.meta_value AS score
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'wbi_rfm_score'
             ORDER BY CAST(um.meta_value AS SIGNED) DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        $data = array_map( function( $r ) {
            return array( 'id' => intval( $r->ID ), 'email' => $r->user_email, 'rfm_score' => intval( $r->score ) );
        }, $rows );

        return rest_ensure_response( $this->wrap( $data, null, $page, $per_page ) );
    }

    public function endpoint_sales_by_period( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/sales/by-period', 401 ); return $this->auth_error(); }
        $this->log_request( '/sales/by-period' );

        list( $from, $to ) = $this->get_date_range( $request );
        $period = sanitize_text_field( $request->get_param( 'period' ) ?? 'day' );
        if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) $period = 'day';

        $raw  = $this->engine->get_sales_by_period( $period, $from, $to );
        $data = is_array( $raw ) ? $raw : array();
        return rest_ensure_response( $this->wrap( $data ) );
    }

    public function endpoint_sales_by_province( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/sales/by-province', 401 ); return $this->auth_error(); }
        $this->log_request( '/sales/by-province' );

        list( $from, $to ) = $this->get_date_range( $request );
        $raw  = $this->engine->get_sales_by_province( $from, $to );
        $data = is_array( $raw ) ? $raw : array();
        return rest_ensure_response( $this->wrap( $data ) );
    }

    public function endpoint_invoices( WP_REST_Request $request ) {
        global $wpdb;
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/invoices', 401 ); return $this->auth_error(); }
        $this->log_request( '/invoices' );

        if ( ! class_exists( 'WBI_Invoice_Module' ) ) {
            return new WP_Error( 'wbi_module_inactive', 'El módulo de Facturación no está activo.', array( 'status' => 404 ) );
        }

        list( $per_page, $page, $offset ) = $this->get_pagination( $request );
        list( $from, $to ) = $this->get_date_range( $request );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS inv_number, pm2.meta_value AS inv_type, p.post_date
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_wbi_invoice_type'
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wbi_invoice_number'
             AND p.post_date BETWEEN %s AND %s
             ORDER BY pm.post_id DESC
             LIMIT %d OFFSET %d",
            $from, $to, $per_page, $offset
        ) );

        $data = array_map( function( $r ) {
            $order = wc_get_order( intval( $r->post_id ) );
            return array(
                'order_id'   => intval( $r->post_id ),
                'inv_number' => $r->inv_number,
                'inv_type'   => $r->inv_type,
                'date'       => $r->post_date,
                'total'      => $order ? floatval( $order->get_total() ) : null,
            );
        }, $rows );

        return rest_ensure_response( $this->wrap( $data, count( $data ), $page, $per_page ) );
    }

    public function endpoint_notifications( WP_REST_Request $request ) {
        if ( ! $this->authenticate( $request ) ) { $this->log_request( '/notifications', 401 ); return $this->auth_error(); }
        $this->log_request( '/notifications' );

        if ( ! class_exists( 'WBI_Notifications_Module' ) ) {
            return new WP_Error( 'wbi_module_inactive', 'El módulo de Notificaciones no está activo.', array( 'status' => 404 ) );
        }

        $nm   = new WBI_Notifications_Module();
        $data = $nm->get_notifications();
        return rest_ensure_response( $this->wrap( $data, count( $data ) ) );
    }

    // -------------------------------------------------------------------------
    // Settings section
    // -------------------------------------------------------------------------

    public function register_api_settings() {
        add_settings_section( 'wbi_api_section', '📱 API REST', null, 'wbi-settings' );
    }

    // -------------------------------------------------------------------------
    // Admin submenu & page
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '📱 API REST',
            '📱 API REST',
            'manage_options',
            'wbi-api',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $api_key    = self::get_api_key();
        $usage      = get_transient( self::USAGE_TRANSIENT ) ?: array( 'today' => 0, 'week' => 0 );
        $log        = get_option( self::REQUEST_LOG_OPTION, array() );
        $regenerated= isset( $_GET['regenerated'] );
        $base_url   = rest_url( self::NAMESPACE );

        $endpoints = array(
            array( 'GET', '/dashboard',             'KPIs generales (ingresos, pedidos, AOV)' ),
            array( 'GET', '/products/best-sellers',  'Top 10 productos más vendidos' ),
            array( 'GET', '/products/stock',         'Niveles de stock actuales (paginado)' ),
            array( 'GET', '/products/stock-alerts',  'Productos bajo stock mínimo' ),
            array( 'GET', '/orders/status-counts',   'Cantidad de pedidos por estado' ),
            array( 'GET', '/customers/ranking',      'Top clientes por facturación' ),
            array( 'GET', '/customers/scoring',      'Scores RFM de clientes (requiere módulo Scoring)' ),
            array( 'GET', '/sales/by-period',        'Ventas agrupadas por día/semana/mes (?period=day|week|month)' ),
            array( 'GET', '/sales/by-province',      'Ventas por provincia' ),
            array( 'GET', '/invoices',               'Listado de facturas (requiere módulo Facturación)' ),
            array( 'GET', '/notifications',          'Notificaciones activas (requiere módulo Notificaciones)' ),
        );

        echo '<div class="wrap">';
        echo '<h1>📱 API REST — wooErp</h1>';

        if ( $regenerated ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ API key regenerada exitosamente.</p></div>';
        }

        // API Key
        echo '<h2>🔑 API Key</h2>';
        echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:15px; max-width:700px;">';
        echo '<input type="text" id="wbi_api_key_field" value="' . esc_attr( $api_key ) . '" class="regular-text" style="width:500px; font-family:monospace;" readonly>';
        echo '<button type="button" class="button" id="wbi_copy_key_btn" style="margin-left:8px;">📋 Copiar</button>';
        echo '<script>document.getElementById("wbi_copy_key_btn").addEventListener("click",function(){var f=document.getElementById("wbi_api_key_field");if(navigator.clipboard){navigator.clipboard.writeText(f.value).then(function(){document.getElementById("wbi_copy_key_btn").textContent="✅ Copiado";})}else{f.select();document.execCommand("copy");document.getElementById("wbi_copy_key_btn").textContent="✅ Copiado";}});</script>';
        echo '<p class="description">Incluí este header en tus requests: <code>X-WBI-API-Key: ' . esc_html( $api_key ) . '</code></p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;" onsubmit="return confirm(\'¿Regenerar la key? Los clientes actuales perderán acceso.\');">';
        wp_nonce_field( 'wbi_regenerate_api_key' );
        echo '<input type="hidden" name="action" value="wbi_regenerate_api_key">';
        echo '<button type="submit" class="button button-secondary">🔄 Regenerar Key</button>';
        echo '</form></div>';

        // Usage stats
        echo '<h2>📊 Estadísticas de Uso</h2>';
        echo '<p>Hoy: <strong>' . intval( $usage['today'] ) . ' requests</strong> &nbsp;|&nbsp; Esta semana: <strong>' . intval( $usage['week'] ) . ' requests</strong></p>';

        // Endpoints
        echo '<h2>🔗 Endpoints Disponibles</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th style="width:60px;">Método</th><th style="width:260px;">Endpoint</th><th>Descripción</th><th>Ejemplo</th></tr></thead><tbody>';
        foreach ( $endpoints as $ep ) {
            $example_url = esc_url( $base_url . $ep[1] . '?api_key=' . $api_key );
            echo '<tr>';
            echo '<td><code style="background:#e8f4f8; padding:2px 6px; border-radius:3px;">' . esc_html( $ep[0] ) . '</code></td>';
            echo '<td><code>' . esc_html( $ep[1] ) . '</code></td>';
            echo '<td>' . esc_html( $ep[2] ) . '</td>';
            echo '<td><a href="' . $example_url . '" target="_blank">Probar →</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Request log
        echo '<h2>📋 Log de Requests (últimos 50)</h2>';
        echo '<table class="wp-list-table widefat fixed striped wbi-sortable">';
        echo '<thead><tr><th>Fecha</th><th>Endpoint</th><th>IP</th><th>Estado</th></tr></thead><tbody>';
        if ( empty( $log ) ) {
            echo '<tr><td colspan="4">Sin requests registrados.</td></tr>';
        } else {
            foreach ( array_reverse( $log ) as $entry ) {
                $status_color = $entry['status'] === 200 ? '#00a32a' : '#d63638';
                echo '<tr>';
                echo '<td>' . esc_html( $entry['date'] ) . '</td>';
                echo '<td><code>' . esc_html( $entry['endpoint'] ) . '</code></td>';
                echo '<td>' . esc_html( $entry['ip'] ) . '</td>';
                echo '<td style="color:' . esc_attr( $status_color ) . '; font-weight:bold;">' . intval( $entry['status'] ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '</div>';
    }
}
