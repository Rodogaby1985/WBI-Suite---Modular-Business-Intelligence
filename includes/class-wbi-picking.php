<?php
/**
 * WBI Picking Module
 * Picking & Armado de Pedidos con escaneo de códigos de barra.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Picking_Module {

    /** @var bool|null Cached HPOS detection result. */
    private $hpos_enabled = null;

    public function __construct() {
        // Admin menu
        add_action( 'admin_menu', array( $this, 'register_pages' ), 100 );

        // Order meta box — picking status
        add_action( 'add_meta_boxes', array( $this, 'add_picking_metabox' ) );

        // Custom order status column in WooCommerce orders list (legacy + HPOS)
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_picking_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_picking_column' ), 10, 2 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_picking_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_picking_column_hpos' ), 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_wbi_picking_start',      array( $this, 'ajax_start_picking' ) );
        add_action( 'wp_ajax_wbi_picking_scan',       array( $this, 'ajax_scan_item' ) );
        add_action( 'wp_ajax_wbi_picking_complete',   array( $this, 'ajax_complete_picking' ) );
        add_action( 'wp_ajax_wbi_picking_reset',      array( $this, 'ajax_reset_picking' ) );
        add_action( 'wp_ajax_wbi_picking_mark_item',  array( $this, 'ajax_mark_item' ) );
        add_action( 'wp_ajax_wbi_picking_order_notes', array( $this, 'ajax_save_order_notes' ) );
        add_action( 'wp_ajax_wbi_picking_edit_qty',    array( $this, 'ajax_edit_qty' ) );
        add_action( 'wp_ajax_wbi_picking_remove_item', array( $this, 'ajax_remove_item' ) );
        add_action( 'wp_ajax_wbi_picking_add_item',    array( $this, 'ajax_add_item' ) );

        // Enqueue scripts on relevant pages
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    // =========================================================================
    // Admin menu
    // =========================================================================

    public function register_pages() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Picking & Armado',
            '<span class="dashicons dashicons-clipboard" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Picking & Armado',
            'manage_woocommerce',
            'wbi-picking',
            array( $this, 'render_picking_list' )
        );

        // Armador panel — accessible to users with 'read' capability
        add_menu_page(
            'Panel Armado',
            'Panel Armado',
            'read',
            'wbi-picking-panel',
            array( $this, 'render_armador_panel' ),
            'dashicons-clipboard',
            5
        );
    }

    // =========================================================================
    // Enqueue assets
    // =========================================================================

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wbi-picking' ) ) return;
        wp_register_script( 'wbi-picking-dummy', '', array(), '', true );
        wp_enqueue_script( 'wbi-picking-dummy' );
        wp_localize_script( 'wbi-picking-dummy', 'wbiPicking', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wbi_picking_nonce' ),
        ) );
    }

    // =========================================================================
    // Picking list / dispatch page
    // =========================================================================

    public function render_picking_list() {
        if ( ! $this->user_has_picking_access() ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a este módulo.', 'wbi-suite' ) );
        }

        $order_id   = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'pending';

        if ( $order_id ) {
            $this->render_picking_interface( $order_id );
            return;
        }
        ?>
        <div class="wrap">
            <h1>Picking & Armado de Pedidos</h1>

            <?php if ( isset( $_GET['completed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Armado completado exitosamente.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-picking&tab=pending"
                   class="nav-tab <?php echo 'pending' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    ⏳ Pendientes
                </a>
                <a href="?page=wbi-picking&tab=in_progress"
                   class="nav-tab <?php echo 'in_progress' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    🔄 En Proceso
                </a>
                <a href="?page=wbi-picking&tab=completed"
                   class="nav-tab <?php echo 'completed' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    ✅ Completados
                </a>
            </nav>

            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:0;">
                <?php
                if ( 'in_progress' === $active_tab ) {
                    $this->render_tab_in_progress();
                } elseif ( 'completed' === $active_tab ) {
                    $this->render_tab_completed();
                } else {
                    $this->render_tab_pending();
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ---- Tab: Pendientes ----------------------------------------------------

    private function render_tab_pending() {
        global $wpdb;

        $per_page = 20;
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;

        $hpos       = $this->is_hpos_enabled();
        $ot         = $this->get_orders_table_name();
        $mt         = $this->get_orders_meta_table_name();
        $id_col     = $hpos ? 'o.id'               : 'p.ID';
        $alias      = $hpos ? 'o'                  : 'p';
        $type_col   = $hpos ? 'o.type'             : 'p.post_type';
        $status_col = $hpos ? 'o.status'           : 'p.post_status';
        $date_col   = $hpos ? 'o.date_created_gmt' : 'p.post_date';
        $meta_fk    = $hpos ? 'order_id'           : 'post_id';

        // Lightweight total count (no ORDER BY, no data fetching)
        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT {$id_col})
             FROM {$ot} {$alias}
             WHERE {$type_col} = 'shop_order'
               AND {$status_col} = 'wc-processing'
               AND {$id_col} NOT IN (
                   SELECT {$meta_fk} FROM {$mt}
                   WHERE meta_key = '_wbi_picking_status'
                     AND meta_value != ''
               )"
        );

        // Paginated query
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT {$id_col}
             FROM {$ot} {$alias}
             WHERE {$type_col} = 'shop_order'
               AND {$status_col} = 'wc-processing'
               AND {$id_col} NOT IN (
                   SELECT {$meta_fk} FROM {$mt}
                   WHERE meta_key = '_wbi_picking_status'
                     AND meta_value != ''
               )
             ORDER BY {$date_col} ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        echo '<h2>⏳ Pedidos Pendientes de Armado <span style="background:#d63638;color:#fff;border-radius:12px;padding:2px 10px;font-size:14px;margin-left:8px;">' . intval( $total_count ) . '</span></h2>';

        if ( 0 === $total_count ) {
            echo '<p style="color:#00a32a;">✅ No hay pedidos pendientes de armado.</p>';
            return;
        }

        $from = $offset + 1;
        $to   = min( $offset + $per_page, $total_count );
        echo '<p style="margin-bottom:8px;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total_count ) . ' pedidos</p>';

        echo '<div class="wbi-table-responsive">';
        echo '<table class="widefat striped wbi-sortable"><thead><tr>
            <th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Items</th><th>Total</th><th>Acción</th>
        </tr></thead><tbody>';

        foreach ( $order_ids as $oid ) {
            $order = wc_get_order( $oid );
            if ( ! $order ) continue;
            $picking_url = admin_url( 'admin.php?page=wbi-picking&order_id=' . $oid );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . intval( $oid ) . '</a></td>';
            echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '—' ) . '</td>';
            echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
            echo '<td>' . intval( $order->get_item_count() ) . '</td>';
            echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
            echo '<td><a href="' . esc_url( $picking_url ) . '" class="button button-primary">▶ Iniciar Armado</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        $total_pages = (int) ceil( $total_count / $per_page );
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo; Anterior',
                'next_text' => 'Siguiente &raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // ---- Tab: En Proceso ----------------------------------------------------

    private function render_tab_in_progress() {
        global $wpdb;

        $per_page = 20;
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;

        $hpos       = $this->is_hpos_enabled();
        $ot         = $this->get_orders_table_name();
        $mt         = $this->get_orders_meta_table_name();
        $id_col     = $hpos ? 'o.id'               : 'p.ID';
        $alias      = $hpos ? 'o'                  : 'p';
        $type_col   = $hpos ? 'o.type'             : 'p.post_type';
        $status_col = $hpos ? 'o.status'           : 'p.post_status';
        $date_col   = $hpos ? 'o.date_created_gmt' : 'p.post_date';
        $meta_fk    = $hpos ? 'order_id'           : 'post_id';

        // Total count for pagination
        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT {$id_col})
             FROM {$ot} {$alias}
             INNER JOIN {$mt} om ON om.{$meta_fk} = {$id_col}
             WHERE {$type_col} = 'shop_order'
               AND {$status_col} IN ('wc-processing', 'wc-on-hold')
               AND om.meta_key = '_wbi_picking_status'
               AND om.meta_value = 'picking'"
        );

        $orders = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT {$id_col}
             FROM {$ot} {$alias}
             INNER JOIN {$mt} om ON om.{$meta_fk} = {$id_col}
             WHERE {$type_col} = 'shop_order'
               AND {$status_col} IN ('wc-processing', 'wc-on-hold')
               AND om.meta_key = '_wbi_picking_status'
               AND om.meta_value = 'picking'
             ORDER BY {$date_col} ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        echo '<h2>🔄 Pedidos en Proceso</h2>';

        if ( 0 === $total_count ) {
            echo '<p>No hay pedidos en proceso de armado.</p>';
            return;
        }

        $from   = $offset + 1;
        $to     = min( $offset + $per_page, $total_count );
        echo '<p style="margin-bottom:8px;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total_count ) . ' pedidos</p>';

        echo '<div class="wbi-table-responsive">';
        echo '<table class="widefat striped wbi-sortable"><thead><tr>
            <th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Progreso</th><th>Operador</th><th>Acción</th>
        </tr></thead><tbody>';

        foreach ( $orders as $oid ) {
            $order       = wc_get_order( $oid );
            if ( ! $order ) continue;
            $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
            $user_id      = $order->get_meta( '_wbi_picking_user' );
            $user         = $user_id ? get_userdata( $user_id ) : null;

            $total_req = 0;
            $total_scn = 0;
            if ( is_array( $picking_data ) ) {
                $total_req = array_sum( array_column( $picking_data, 'qty_required' ) );
                $total_scn = array_sum( array_column( $picking_data, 'qty_scanned' ) );
            }

            $picking_url = admin_url( 'admin.php?page=wbi-picking&order_id=' . $oid );

            echo '<tr>';
            echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . intval( $oid ) . '</a></td>';
            echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '—' ) . '</td>';
            echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
            echo '<td>' . intval( $total_scn ) . '/' . intval( $total_req ) . ' items</td>';
            echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
            echo '<td><a href="' . esc_url( $picking_url ) . '" class="button">🔄 Continuar</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        $total_pages = (int) ceil( $total_count / $per_page );
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo; Anterior',
                'next_text' => 'Siguiente &raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // ---- Tab: Completados ---------------------------------------------------

    private function render_tab_completed() {
        global $wpdb;

        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '';
        $per_page  = 20;
        $paged     = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset    = ( $paged - 1 ) * $per_page;

        $hpos       = $this->is_hpos_enabled();
        $ot         = $this->get_orders_table_name();
        $mt         = $this->get_orders_meta_table_name();
        $id_col     = $hpos ? 'o.id'               : 'p.ID';
        $alias      = $hpos ? 'o'                  : 'p';
        $type_col   = $hpos ? 'o.type'             : 'p.post_type';
        $date_col   = $hpos ? 'o.date_created_gmt' : 'p.post_date';
        $meta_fk    = $hpos ? 'order_id'           : 'post_id';

        // Build optional date conditions (values are escaped via $wpdb->prepare individually)
        $date_where = '';
        if ( $date_from ) {
            $date_where .= $wpdb->prepare( " AND {$date_col} >= %s", $date_from . ' 00:00:00' );
        }
        if ( $date_to ) {
            $date_where .= $wpdb->prepare( " AND {$date_col} <= %s", $date_to . ' 23:59:59' );
        }

        $base_where = "FROM {$ot} {$alias}
             INNER JOIN {$mt} om ON om.{$meta_fk} = {$id_col}
             WHERE {$type_col} = 'shop_order'
               AND om.meta_key = '_wbi_picking_status'
               AND om.meta_value IN ('picked', 'packed'){$date_where}";

        // Total count for pagination
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT {$id_col}) {$base_where}" );

        // Paginated query
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $orders = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT {$id_col} {$base_where} ORDER BY {$date_col} DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        echo '<h2>✅ Pedidos Completados</h2>';

        // Date filter form
        echo '<form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="wbi-picking">
            <input type="hidden" name="tab" value="completed">
            Desde: <input type="date" name="date_from" value="' . esc_attr( $date_from ) . '">
            Hasta: <input type="date" name="date_to" value="' . esc_attr( $date_to ) . '">
            <button type="submit" class="button">Filtrar</button>
        </form>';

        if ( 0 === $total_count ) {
            echo '<p>No hay pedidos completados' . ( $date_from || $date_to ? ' en el rango seleccionado' : '' ) . '.</p>';
            return;
        }

        $from = $offset + 1;
        $to   = min( $offset + $per_page, $total_count );
        echo '<p style="margin-bottom:8px;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total_count ) . ' pedidos</p>';

        echo '<div class="wbi-table-responsive">';
        echo '<table class="widefat striped wbi-sortable"><thead><tr>
            <th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Items</th>
            <th>Tiempo de Armado</th><th>Operador</th><th>Estado</th>
        </tr></thead><tbody>';

        foreach ( $orders as $oid ) {
            $order    = wc_get_order( $oid );
            if ( ! $order ) continue;
            $status   = $order->get_meta( '_wbi_picking_status' );
            $started  = $order->get_meta( '_wbi_picking_started_at' );
            $finished = $order->get_meta( '_wbi_picking_completed_at' );
            $user_id  = $order->get_meta( '_wbi_picking_user' );
            $user     = $user_id ? get_userdata( $user_id ) : null;

            $minutes = '—';
            if ( $started && $finished ) {
                $diff    = strtotime( $finished ) - strtotime( $started );
                $minutes = round( $diff / 60, 1 ) . ' min';
            }

            $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
            $total_items  = is_array( $picking_data ) ? array_sum( array_column( $picking_data, 'qty_required' ) ) : $order->get_item_count();

            $status_label = 'picked' === $status
                ? '<span style="color:green;">✅ Armado</span>'
                : '<span style="color:#2271b1;">📦 Despachado</span>';

            echo '<tr>';
            echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . intval( $oid ) . '</a></td>';
            echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '—' ) . '</td>';
            echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
            echo '<td>' . intval( $total_items ) . '</td>';
            echo '<td>' . esc_html( $minutes ) . '</td>';
            echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
            echo '<td>' . wp_kses_post( $status_label ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        $total_pages = (int) ceil( $total_count / $per_page );
        if ( $total_pages > 1 ) {
            $extra_args = array();
            if ( $date_from ) { $extra_args['date_from'] = $date_from; }
            if ( $date_to )   { $extra_args['date_to']   = $date_to; }
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links( array(
                'base'      => add_query_arg( array_merge( $extra_args, array( 'paged' => '%#%' ) ) ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo; Anterior',
                'next_text' => 'Siguiente &raquo;',
            ) );
            echo '</div></div>';
        }
    }

    // =========================================================================
    // Picking interface for a single order
    // =========================================================================

    private function render_picking_interface( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<div class="wrap"><p>❌ Pedido no encontrado.</p></div>';
            return;
        }

        $picking_status = $order->get_meta( '_wbi_picking_status' );
        $picking_data   = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        $picking_user   = (int) $order->get_meta( '_wbi_picking_user' );

        // Show warning if another user is currently picking this order
        if ( 'picking' === $picking_status && $picking_user && $picking_user !== get_current_user_id() ) {
            $other_user = get_userdata( $picking_user );
            $other_name = $other_user ? $other_user->display_name : '#' . $picking_user;
            echo '<div class="notice notice-warning" style="margin:10px 0;"><p>'
                . sprintf(
                    /* translators: %s: display name of the user currently picking the order */
                    esc_html__( '⚠️ Atención: Este pedido ya está siendo armado por %s.', 'wbi-suite' ),
                    '<strong>' . esc_html( $other_name ) . '</strong>'
                )
                . '</p></div>';
        }

        // If not yet started, build initial picking data from order items
        if ( ! $picking_status || 'picking' !== $picking_status || ! is_array( $picking_data ) ) {
            $picking_data = array();
            foreach ( $order->get_items() as $item ) {
                $product_id   = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $lookup_id    = $variation_id ?: $product_id;
                $barcode      = get_post_meta( $lookup_id, '_wbi_barcode', true );

                $picking_data[] = array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'item_id'      => $item->get_id(),
                    'barcode'      => $barcode,
                    'name'         => $item->get_name(),
                    'qty_required' => $item->get_quantity(),
                    'qty_scanned'  => 0,
                    'scanned_at'   => array(),
                );
            }
        }

        $total_req = array_sum( array_column( $picking_data, 'qty_required' ) );
        $total_scn = array_sum( array_column( $picking_data, 'qty_scanned' ) );
        $pct       = $total_req > 0 ? round( $total_scn / $total_req * 100 ) : 0;
        $nonce     = wp_create_nonce( 'wbi_picking_nonce' );
        ?>
        <div class="wrap">
            <h1>📦 Armado de Pedido #<?php echo intval( $order_id ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-picking' ) ); ?>" class="button" style="font-size:13px;vertical-align:middle;margin-left:10px;">
                    ← Volver
                </a>
            </h1>

            <!-- Order summary -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:15px 20px;display:flex;gap:30px;flex-wrap:wrap;margin-bottom:16px;">
                <div><strong>Cliente:</strong> <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></div>
                <div><strong>Fecha:</strong> <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '—' ); ?></div>
                <div><strong>Total:</strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></div>
                <div><strong>Items:</strong> <?php echo intval( $total_req ); ?></div>
            </div>

            <!-- Progress bar -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:15px 20px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span><strong>Progreso:</strong> <span id="wbi-progress-text"><?php echo intval( $total_scn ); ?> de <?php echo intval( $total_req ); ?> items escaneados</span></span>
                    <span><strong id="wbi-pct"><?php echo intval( $pct ); ?>%</strong></span>
                </div>
                <div style="background:#f0f0f1;border-radius:3px;height:24px;">
                    <div id="wbi-progress-bar" style="background:#00a32a;height:100%;border-radius:3px;width:<?php echo intval( $pct ); ?>%;transition:width 0.3s;"></div>
                </div>
            </div>

            <!-- Scanner input -->
            <div style="background:#fff;border:1px solid #2271b1;border-radius:4px;padding:20px;margin-bottom:16px;max-width:600px;">
                <h3 style="margin-top:0;">🔍 Escáner</h3>
                <div style="display:flex;gap:10px;">
                    <input type="text" id="wbi-scan-input"
                           placeholder="Escanea código de barra o QR..."
                           style="font-size:20px;font-family:monospace;padding:10px;flex:1;border:2px solid #2271b1;"
                           autofocus autocomplete="off" />
                    <button id="wbi-scan-btn" class="button button-primary" style="font-size:16px;padding:8px 16px;">
                        Escanear
                    </button>
                </div>
                <div id="wbi-scan-msg" style="margin-top:10px;min-height:24px;font-size:14px;"></div>
            </div>

            <!-- Items table -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:15px 20px;margin-bottom:16px;">
                <h3 style="margin-top:0;">Items del Pedido</h3>
                <div class="wbi-table-responsive">
                <table class="widefat striped" id="wbi-items-table">
                    <thead>
                        <tr>
                            <th style="width:72px;">Imagen</th>
                            <th>Producto</th>
                            <th>Código de Barra</th>
                            <th>Cant. Requerida</th>
                            <th>Cant. Escaneada</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $picking_data as $idx => $item ) :
                            $scanned      = intval( $item['qty_scanned'] );
                            $required     = intval( $item['qty_required'] );
                            $item_id      = intval( $item['item_id'] );
                            $manual_status = $order->get_meta( '_wbi_picking_item_' . $item_id . '_status' );
                            $manual_notes  = $order->get_meta( '_wbi_picking_item_' . $item_id . '_notes' );
                            if ( $manual_status === 'picked' ) {
                                $item_status = 'complete';
                            } elseif ( $manual_status === 'missing' || $manual_status === 'replaced' ) {
                                $item_status = 'resolved';
                            } elseif ( $scanned === 0 ) {
                                $item_status = 'pending';
                            } elseif ( $scanned >= $required ) {
                                $item_status = 'complete';
                            } else {
                                $item_status = 'partial';
                            }
                            $status_labels = array(
                                'pending'  => 'Pendiente',
                                'partial'  => 'Parcial',
                                'complete' => 'Completo',
                                'resolved' => 'Resuelto',
                            );
                        ?>
                        <tr id="wbi-item-row-<?php echo intval( $idx ); ?>"
                            data-barcode="<?php echo esc_attr( $item['barcode'] ); ?>"
                            data-item-id="<?php echo $item_id; ?>"
                            data-product-id="<?php echo intval( $item['product_id'] ); ?>"
                            data-variation-id="<?php echo intval( $item['variation_id'] ); ?>"
                            data-idx="<?php echo intval( $idx ); ?>"
                            style="transition:background 0.3s;">
                            <?php
                            // Product image: prefer variation image, fall back to parent
                            $img_lookup = $item['variation_id'] ?: $item['product_id'];
                            $img_prod   = wc_get_product( $img_lookup );
                            if ( $img_prod ) {
                                $thumb = $img_prod->get_image( 'thumbnail', array( 'style' => 'width:60px;height:60px;object-fit:contain;border-radius:3px;' ) );
                                if ( ! $thumb || false === strpos( $thumb, 'src=' ) ) {
                                    // Variation had no image, try parent
                                    $parent_prod = wc_get_product( $item['product_id'] );
                                    $thumb = $parent_prod ? $parent_prod->get_image( 'thumbnail', array( 'style' => 'width:60px;height:60px;object-fit:contain;border-radius:3px;' ) ) : '';
                                }
                            } else {
                                $thumb = '';
                            }
                            ?>
                            <td style="text-align:center;vertical-align:middle;"><?php echo $thumb ? wp_kses_post( $thumb ) : '<span style="color:#aaa;font-size:20px;">🖼️</span>'; ?></td>
                            <td><?php echo esc_html( $item['name'] ); ?></td>
                            <td>
                                <?php if ( $item['barcode'] ) : ?>
                                    <code><?php echo esc_html( $item['barcode'] ); ?></code>
                                <?php else : ?>
                                    <span style="color:#aaa;">Sin código</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo intval( $required ); ?></td>
                            <td id="wbi-scanned-<?php echo intval( $idx ); ?>"><?php echo intval( $scanned ); ?></td>
                            <td id="wbi-status-<?php echo intval( $idx ); ?>"><?php echo esc_html( $status_labels[ $item_status ] ); ?></td>
                            <td>
                                <?php $is_resolved = in_array( $manual_status, array( 'picked', 'missing', 'replaced' ), true ); ?>
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <button class="button button-small wbi-mark-picked"
                                                data-idx="<?php echo intval( $idx ); ?>"
                                                data-item-id="<?php echo $item_id; ?>"
                                                <?php echo $is_resolved ? 'disabled' : ''; ?>>
                                            ✅ Agarrado
                                        </button>
                                        <button class="button button-small wbi-mark-missing"
                                                data-idx="<?php echo intval( $idx ); ?>"
                                                data-item-id="<?php echo $item_id; ?>"
                                                <?php echo $is_resolved ? 'disabled' : ''; ?>>
                                            ❌ Faltante
                                        </button>
                                        <button class="button button-small wbi-edit-qty-btn"
                                                data-idx="<?php echo intval( $idx ); ?>"
                                                data-item-id="<?php echo $item_id; ?>"
                                                data-current-qty="<?php echo intval( $required ); ?>"
                                                data-name="<?php echo esc_attr( $item['name'] ); ?>"
                                                title="Editar cantidad">
                                            ✏️ Editar
                                        </button>
                                        <button class="button button-small wbi-remove-item-btn"
                                                data-idx="<?php echo intval( $idx ); ?>"
                                                data-item-id="<?php echo $item_id; ?>"
                                                data-name="<?php echo esc_attr( $item['name'] ); ?>"
                                                title="Eliminar ítem"
                                                style="color:#d63638;border-color:#d63638;">
                                            🗑️ Eliminar
                                        </button>
                                    </div>
                                    <!-- Missing/Replace form (hidden by default) -->
                                    <div class="wbi-missing-form" id="wbi-missing-form-<?php echo intval( $idx ); ?>" style="display:none; border:1px solid #ccd0d4; padding:10px; border-radius:4px; background:#fafafa;">
                                        <label style="display:block;margin-bottom:6px;font-weight:bold;">Tipo de faltante:</label>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="radio" name="wbi_missing_type_<?php echo intval( $idx ); ?>" value="missing" checked> Producto faltante
                                        </label>
                                        <label style="display:block;margin-bottom:8px;">
                                            <input type="radio" name="wbi_missing_type_<?php echo intval( $idx ); ?>" value="replaced"> Reemplazar por otro
                                        </label>
                                        <div class="wbi-replacement-field" id="wbi-replacement-<?php echo intval( $idx ); ?>" style="display:none;margin-bottom:8px;">
                                            <input type="text" class="regular-text" placeholder="SKU o nombre del reemplazo" id="wbi-replacement-val-<?php echo intval( $idx ); ?>">
                                        </div>
                                        <textarea class="large-text" rows="2" placeholder="Observaciones..." id="wbi-item-notes-<?php echo intval( $idx ); ?>"><?php echo esc_textarea( $manual_notes ); ?></textarea>
                                        <div style="margin-top:8px;display:flex;gap:6px;">
                                            <button class="button button-primary button-small wbi-confirm-missing"
                                                    data-idx="<?php echo intval( $idx ); ?>"
                                                    data-item-id="<?php echo $item_id; ?>">
                                                Confirmar
                                            </button>
                                            <button class="button button-small wbi-cancel-missing" data-idx="<?php echo intval( $idx ); ?>">
                                                Cancelar
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Notes field -->
                                    <input type="text" class="regular-text wbi-item-note-quick" placeholder="Notas..."
                                           data-item-id="<?php echo $item_id; ?>"
                                           value="<?php echo esc_attr( $manual_notes ); ?>"
                                           style="font-size:11px;max-width:180px;">
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Add item button -->
            <div style="margin-bottom:16px;">
                <button id="wbi-add-item-btn" class="button" style="font-size:14px;">➕ Agregar Producto</button>
            </div>

            <!-- Edit qty modal -->
            <div id="wbi-edit-qty-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:24px;border-radius:6px;max-width:420px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,0.3);">
                    <h3 style="margin-top:0;" id="wbi-edit-qty-title">Editar cantidad</h3>
                    <input type="hidden" id="wbi-edit-qty-idx">
                    <input type="hidden" id="wbi-edit-qty-item-id">
                    <div style="margin-bottom:12px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Nueva cantidad:</label>
                        <input type="number" id="wbi-edit-qty-value" min="1" style="width:100%;font-size:16px;padding:6px;">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Motivo del cambio <span style="color:#d63638;">*</span></label>
                        <textarea id="wbi-edit-qty-reason" rows="3" style="width:100%;padding:6px;" placeholder="Explicá por qué se modifica la cantidad..."></textarea>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button id="wbi-edit-qty-cancel" class="button">Cancelar</button>
                        <button id="wbi-edit-qty-confirm" class="button button-primary">Confirmar cambio</button>
                    </div>
                </div>
            </div>

            <!-- Remove item modal -->
            <div id="wbi-remove-item-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:24px;border-radius:6px;max-width:420px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,0.3);">
                    <h3 style="margin-top:0;" id="wbi-remove-item-title">Eliminar ítem</h3>
                    <input type="hidden" id="wbi-remove-item-idx">
                    <input type="hidden" id="wbi-remove-item-id">
                    <div style="margin-bottom:16px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Motivo de la eliminación <span style="color:#d63638;">*</span></label>
                        <textarea id="wbi-remove-item-reason" rows="3" style="width:100%;padding:6px;" placeholder="Explicá por qué se elimina el producto..."></textarea>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button id="wbi-remove-item-cancel" class="button">Cancelar</button>
                        <button id="wbi-remove-item-confirm" class="button button-primary" style="background:#d63638;border-color:#d63638;">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Add item modal -->
            <div id="wbi-add-item-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:24px;border-radius:6px;max-width:480px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,0.3);">
                    <h3 style="margin-top:0;">Agregar Producto al Pedido</h3>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Código de barra / QR / SKU:</label>
                        <input type="text" id="wbi-add-item-code" style="width:100%;font-size:16px;padding:6px;" placeholder="Escanea o escribe el código..." autofocus autocomplete="off">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Cantidad:</label>
                        <input type="number" id="wbi-add-item-qty" min="1" value="1" style="width:100%;font-size:16px;padding:6px;">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;margin-bottom:4px;font-weight:bold;">Motivo <span style="color:#d63638;">*</span></label>
                        <textarea id="wbi-add-item-reason" rows="3" style="width:100%;padding:6px;" placeholder="Explicá por qué se agrega este producto..."></textarea>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button id="wbi-add-item-cancel" class="button">Cancelar</button>
                        <button id="wbi-add-item-confirm" class="button button-primary">Agregar</button>
                    </div>
                </div>
            </div>

            <!-- Order notes -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:15px 20px;margin-bottom:16px;">
                <h3 style="margin-top:0;">Observaciones del Pedido</h3>
                <textarea id="wbi-order-notes" class="large-text" rows="3" placeholder="Observaciones generales del armado..."><?php echo esc_textarea( $order->get_meta( '_wbi_picking_order_notes' ) ); ?></textarea>
                <button id="wbi-save-order-notes" class="button" style="margin-top:6px;">Guardar Observaciones</button>
                <span id="wbi-notes-saved" style="display:none;color:#00a32a;margin-left:8px;">✅ Guardado</span>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                <button id="wbi-complete-btn" class="button button-primary"
                        style="font-size:16px;padding:10px 24px;<?php echo $pct < 100 ? 'display:none;' : ''; ?>">
                    ✅ Pedido Completo
                </button>
                <button id="wbi-reset-btn" class="button"
                        style="font-size:14px;color:#d63638;border-color:#d63638;">
                    Reiniciar Picking
                </button>
            </div>
        </div>

        <script>
        (function() {
            var ORDER_ID    = <?php echo intval( $order_id ); ?>;
            var TOTAL_REQ   = <?php echo intval( $total_req ); ?>;
            var ajaxurl     = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce       = '<?php echo esc_js( $nonce ); ?>';
            var alreadyStarted = <?php echo ( 'picking' === $picking_status ) ? 'true' : 'false'; ?>;

            var scanInput    = document.getElementById('wbi-scan-input');
            var scanBtn      = document.getElementById('wbi-scan-btn');
            var scanMsg      = document.getElementById('wbi-scan-msg');
            var progressBar  = document.getElementById('wbi-progress-bar');
            var progressText = document.getElementById('wbi-progress-text');
            var pctEl        = document.getElementById('wbi-pct');
            var completeBtn  = document.getElementById('wbi-complete-btn');
            var resetBtn     = document.getElementById('wbi-reset-btn');

            var totalScanned = <?php echo intval( $total_scn ); ?>;

            // --- Audio ---
            function wbiBeep(freq, duration) {
                try {
                    var ctx  = new (window.AudioContext || window.webkitAudioContext)();
                    var osc  = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = freq;
                    gain.gain.value     = 0.3;
                    osc.onended = function() { ctx.close(); };
                    osc.start();
                    osc.stop(ctx.currentTime + duration / 1000);
                } catch(e) {}
            }

            // --- Start picking if not yet started ---
            if ( ! alreadyStarted ) {
                var startData = new FormData();
                startData.append('action', 'wbi_picking_start');
                startData.append('nonce', nonce);
                startData.append('order_id', ORDER_ID);
                fetch(ajaxurl, { method:'POST', body:startData });
            }

            // --- Scan handler ---
            function doScan() {
                var code = scanInput.value.trim();
                if ( ! code ) return;
                scanInput.disabled = true;
                scanBtn.disabled   = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_scan');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);
                data.append('barcode', code);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        scanInput.disabled = false;
                        scanBtn.disabled   = false;
                        scanInput.value    = '';
                        scanInput.focus();

                        if ( res.success ) {
                            var d   = res.data;
                            var idx = d.matched_index;

                            // Update row
                            document.getElementById('wbi-scanned-' + idx).textContent = d.scanned_item.qty_scanned;
                            var statusEl = document.getElementById('wbi-status-' + idx);
                            if ( d.scanned_item.qty_scanned >= d.scanned_item.qty_required ) {
                                statusEl.textContent = 'Completo';
                            } else {
                                statusEl.textContent = 'Parcial';
                            }

                            // Flash row green
                            var row = document.getElementById('wbi-item-row-' + idx);
                            row.style.background = '#d1fae5';
                            setTimeout(function(){ row.style.background = ''; }, 800);

                            // Update progress
                            totalScanned = d.total_scanned;
                            var pct = TOTAL_REQ > 0 ? Math.round(totalScanned / TOTAL_REQ * 100) : 0;
                            progressBar.style.width  = pct + '%';
                            progressText.textContent = totalScanned + ' de ' + TOTAL_REQ + ' items escaneados';
                            pctEl.textContent        = pct + '%';

                            scanMsg.style.color   = '#00a32a';
                            scanMsg.textContent   = '✅ ' + d.scanned_item.name + ' (' + d.scanned_item.qty_scanned + '/' + d.scanned_item.qty_required + ')';

                            wbiBeep(880, 100);

                            if ( d.all_complete ) {
                                completeBtn.style.display = '';
                                completeBtn.scrollIntoView({ behavior:'smooth', block:'center' });
                            }
                        } else {
                            // Flash input red
                            scanInput.style.borderColor = '#d63638';
                            setTimeout(function(){ scanInput.style.borderColor = '#2271b1'; }, 1000);

                            var errMsg = typeof res.data === 'object' ? res.data.message : res.data;
                            scanMsg.style.color  = '#d63638';
                            scanMsg.textContent  = '❌ ' + errMsg;

                            wbiBeep(220, 300);
                        }
                    })
                    .catch(function() {
                        scanInput.disabled = false;
                        scanBtn.disabled   = false;
                        scanInput.focus();
                    });
            }

            scanBtn.addEventListener('click', doScan);
            scanInput.addEventListener('keydown', function(e) {
                if ( e.key === 'Enter' ) { e.preventDefault(); doScan(); }
            });

            // --- Manual picking: Agarrado ---
            document.querySelectorAll('.wbi-mark-picked').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx    = this.dataset.idx;
                    var itemId = this.dataset.itemId;
                    var btnEl  = this;
                    btnEl.disabled = true;

                    var data = new FormData();
                    data.append('action', 'wbi_picking_mark_item');
                    data.append('nonce', nonce);
                    data.append('order_id', ORDER_ID);
                    data.append('item_id', itemId);
                    data.append('status', 'picked');
                    data.append('notes', document.querySelector('.wbi-item-note-quick[data-item-id="' + itemId + '"]').value);

                    fetch(ajaxurl, { method:'POST', body:data })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if ( res.success ) {
                                var row = document.getElementById('wbi-item-row-' + idx);
                                row.style.background = '#d1fae5';
                                document.getElementById('wbi-status-' + idx).textContent = 'Completo';
                                // Disable both action buttons
                                row.querySelectorAll('.wbi-mark-picked, .wbi-mark-missing').forEach(function(b) { b.disabled = true; });
                                wbiBeep(880, 100);
                                checkAllResolved();
                            }
                        });
                });
            });

            // --- Manual picking: Faltante (show form) ---
            document.querySelectorAll('.wbi-mark-missing').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx = this.dataset.idx;
                    var form = document.getElementById('wbi-missing-form-' + idx);
                    form.style.display = form.style.display === 'none' ? 'block' : 'none';
                });
            });

            // --- Radio toggle for replacement field ---
            document.querySelectorAll('[name^="wbi_missing_type_"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var idx = this.name.replace('wbi_missing_type_', '');
                    var replDiv = document.getElementById('wbi-replacement-' + idx);
                    replDiv.style.display = this.value === 'replaced' ? 'block' : 'none';
                });
            });

            // --- Cancel missing form ---
            document.querySelectorAll('.wbi-cancel-missing').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx = this.dataset.idx;
                    document.getElementById('wbi-missing-form-' + idx).style.display = 'none';
                });
            });

            // --- Confirm missing/replaced ---
            document.querySelectorAll('.wbi-confirm-missing').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx    = this.dataset.idx;
                    var itemId = this.dataset.itemId;
                    var type   = document.querySelector('[name="wbi_missing_type_' + idx + '"]:checked').value;
                    var replacement = type === 'replaced' ? document.getElementById('wbi-replacement-val-' + idx).value : '';
                    var notes = document.getElementById('wbi-item-notes-' + idx).value;

                    var data = new FormData();
                    data.append('action', 'wbi_picking_mark_item');
                    data.append('nonce', nonce);
                    data.append('order_id', ORDER_ID);
                    data.append('item_id', itemId);
                    data.append('status', type);
                    data.append('replacement', replacement);
                    data.append('notes', notes);

                    fetch(ajaxurl, { method:'POST', body:data })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if ( res.success ) {
                                document.getElementById('wbi-missing-form-' + idx).style.display = 'none';
                                var row = document.getElementById('wbi-item-row-' + idx);
                                row.style.background = '#fef3cd';
                                document.getElementById('wbi-status-' + idx).textContent = 'Resuelto';
                                row.querySelectorAll('.wbi-mark-picked, .wbi-mark-missing').forEach(function(b) { b.disabled = true; });
                                checkAllResolved();
                            }
                        });
                });
            });

            // --- Save order notes ---
            var saveNotesBtn = document.getElementById('wbi-save-order-notes');
            if ( saveNotesBtn ) {
                saveNotesBtn.addEventListener('click', function() {
                    var notes = document.getElementById('wbi-order-notes').value;
                    var data  = new FormData();
                    data.append('action', 'wbi_picking_order_notes');
                    data.append('nonce', nonce);
                    data.append('order_id', ORDER_ID);
                    data.append('notes', notes);

                    fetch(ajaxurl, { method:'POST', body:data })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if ( res.success ) {
                                var saved = document.getElementById('wbi-notes-saved');
                                saved.style.display = 'inline';
                                setTimeout(function(){ saved.style.display = 'none'; }, 2000);
                            }
                        });
                });
            }

            // --- Check if all items resolved ---
            function checkAllResolved() {
                var rows  = document.querySelectorAll('#wbi-items-table tbody tr');
                var allDone = true;
                rows.forEach(function(row) {
                    var statusEl = row.querySelector('[id^="wbi-status-"]');
                    if ( statusEl ) {
                        var s = statusEl.textContent.trim();
                        if ( s !== 'Completo' && s !== 'Resuelto' ) allDone = false;
                    }
                });
                if ( allDone && rows.length > 0 ) {
                    completeBtn.style.display = '';
                    completeBtn.scrollIntoView({ behavior:'smooth', block:'center' });
                }
            }

            // --- Complete picking ---
            completeBtn.addEventListener('click', function() {
                if ( ! confirm('¿Confirmar el armado completo de este pedido?') ) return;
                completeBtn.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_complete');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if ( res.success ) {
                            window.location.href = res.data.redirect;
                        } else {
                            alert('Error: ' + res.data);
                            completeBtn.disabled = false;
                        }
                    });
            });

            // --- Reset picking ---
            resetBtn.addEventListener('click', function() {
                if ( ! confirm('¿Reiniciar el armado? Se perderán todos los escaneos registrados.') ) return;
                resetBtn.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_reset');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if ( res.success ) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + res.data);
                            resetBtn.disabled = false;
                        }
                    });
            });

            // ---- Helper: show/hide modal ----
            function showModal(id) {
                var m = document.getElementById(id);
                if (m) { m.style.display = 'flex'; }
            }
            function hideModal(id) {
                var m = document.getElementById(id);
                if (m) { m.style.display = 'none'; }
            }

            // ---- Edit qty ----
            document.querySelectorAll('.wbi-edit-qty-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('wbi-edit-qty-idx').value     = this.dataset.idx;
                    document.getElementById('wbi-edit-qty-item-id').value = this.dataset.itemId;
                    document.getElementById('wbi-edit-qty-value').value   = this.dataset.currentQty;
                    document.getElementById('wbi-edit-qty-reason').value  = '';
                    document.getElementById('wbi-edit-qty-title').textContent = 'Editar cantidad: ' + this.dataset.name;
                    showModal('wbi-edit-qty-modal');
                    setTimeout(function(){ document.getElementById('wbi-edit-qty-value').focus(); }, 50);
                });
            });
            document.getElementById('wbi-edit-qty-cancel').addEventListener('click', function() {
                hideModal('wbi-edit-qty-modal');
            });
            document.getElementById('wbi-edit-qty-confirm').addEventListener('click', function() {
                var idx    = document.getElementById('wbi-edit-qty-idx').value;
                var itemId = document.getElementById('wbi-edit-qty-item-id').value;
                var qty    = parseInt(document.getElementById('wbi-edit-qty-value').value, 10);
                var reason = document.getElementById('wbi-edit-qty-reason').value.trim();

                if ( ! qty || qty < 1 ) { alert('La cantidad debe ser mayor a 0.'); return; }
                if ( ! reason ) { alert('El motivo es obligatorio.'); document.getElementById('wbi-edit-qty-reason').focus(); return; }

                var btn = this;
                btn.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_edit_qty');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);
                data.append('item_id', itemId);
                data.append('new_qty', qty);
                data.append('reason', reason);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if ( res.success ) {
                            hideModal('wbi-edit-qty-modal');
                            // Update required qty display
                            var row = document.getElementById('wbi-item-row-' + idx);
                            if (row) {
                                var cells = row.querySelectorAll('td');
                                // required qty cell is 3rd td (index 2 after image and name and barcode = index 3)
                                var qtyCell = row.querySelector('[data-req-cell]');
                                if (!qtyCell) {
                                    // Find it by content — just reload to ensure consistency
                                }
                                row.style.background = '#fef9c3';
                                setTimeout(function(){ row.style.background = ''; }, 1200);
                            }
                            TOTAL_REQ = res.data.total_required;
                            totalScanned = res.data.total_scanned;
                            var pct = TOTAL_REQ > 0 ? Math.round(totalScanned / TOTAL_REQ * 100) : 0;
                            progressBar.style.width  = pct + '%';
                            progressText.textContent = totalScanned + ' de ' + TOTAL_REQ + ' items escaneados';
                            pctEl.textContent        = pct + '%';
                            // Reload page to refresh qty cells accurately
                            window.location.reload();
                        } else {
                            var errMsg = typeof res.data === 'object' ? res.data.message : res.data;
                            alert('Error: ' + errMsg);
                        }
                    });
            });

            // ---- Remove item ----
            document.querySelectorAll('.wbi-remove-item-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('wbi-remove-item-idx').value = this.dataset.idx;
                    document.getElementById('wbi-remove-item-id').value  = this.dataset.itemId;
                    document.getElementById('wbi-remove-item-reason').value = '';
                    document.getElementById('wbi-remove-item-title').textContent = 'Eliminar: ' + this.dataset.name;
                    showModal('wbi-remove-item-modal');
                    setTimeout(function(){ document.getElementById('wbi-remove-item-reason').focus(); }, 50);
                });
            });
            document.getElementById('wbi-remove-item-cancel').addEventListener('click', function() {
                hideModal('wbi-remove-item-modal');
            });
            document.getElementById('wbi-remove-item-confirm').addEventListener('click', function() {
                var itemId = document.getElementById('wbi-remove-item-id').value;
                var reason = document.getElementById('wbi-remove-item-reason').value.trim();

                if ( ! reason ) { alert('El motivo es obligatorio.'); document.getElementById('wbi-remove-item-reason').focus(); return; }

                var btn = this;
                btn.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_remove_item');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);
                data.append('item_id', itemId);
                data.append('reason', reason);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if ( res.success ) {
                            hideModal('wbi-remove-item-modal');
                            window.location.reload();
                        } else {
                            var errMsg = typeof res.data === 'object' ? res.data.message : res.data;
                            alert('Error: ' + errMsg);
                        }
                    });
            });

            // ---- Add item ----
            document.getElementById('wbi-add-item-btn').addEventListener('click', function() {
                document.getElementById('wbi-add-item-code').value   = '';
                document.getElementById('wbi-add-item-qty').value    = '1';
                document.getElementById('wbi-add-item-reason').value = '';
                showModal('wbi-add-item-modal');
                setTimeout(function(){ document.getElementById('wbi-add-item-code').focus(); }, 50);
            });
            document.getElementById('wbi-add-item-cancel').addEventListener('click', function() {
                hideModal('wbi-add-item-modal');
            });
            document.getElementById('wbi-add-item-confirm').addEventListener('click', function() {
                var code   = document.getElementById('wbi-add-item-code').value.trim();
                var qty    = parseInt(document.getElementById('wbi-add-item-qty').value, 10);
                var reason = document.getElementById('wbi-add-item-reason').value.trim();

                if ( ! code ) { alert('Ingresá un código.'); document.getElementById('wbi-add-item-code').focus(); return; }
                if ( ! qty || qty < 1 ) { alert('La cantidad debe ser mayor a 0.'); return; }
                if ( ! reason ) { alert('El motivo es obligatorio.'); document.getElementById('wbi-add-item-reason').focus(); return; }

                var btn = this;
                btn.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_picking_add_item');
                data.append('nonce', nonce);
                data.append('order_id', ORDER_ID);
                data.append('code', code);
                data.append('qty', qty);
                data.append('reason', reason);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if ( res.success ) {
                            hideModal('wbi-add-item-modal');
                            window.location.reload();
                        } else {
                            var errMsg = typeof res.data === 'object' ? res.data.message : res.data;
                            alert('Error: ' + errMsg);
                        }
                    });
            });

            // Close modals on backdrop click
            ['wbi-edit-qty-modal','wbi-remove-item-modal','wbi-add-item-modal'].forEach(function(id) {
                var m = document.getElementById(id);
                if (m) {
                    m.addEventListener('click', function(e) {
                        if (e.target === m) { m.style.display = 'none'; }
                    });
                }
            });

        })();
        </script>
        <?php
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    public function ajax_start_picking() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        // Concurrency check: prevent two users from picking the same order simultaneously
        $existing_status = $order->get_meta( '_wbi_picking_status' );
        $existing_user   = $order->get_meta( '_wbi_picking_user' );
        if ( 'picking' === $existing_status && $existing_user && (int) $existing_user !== get_current_user_id() ) {
            $user_data = get_userdata( (int) $existing_user );
            $name      = $user_data ? $user_data->display_name : '#' . $existing_user;
            wp_send_json_error( 'Este pedido ya está siendo armado por ' . esc_html( $name ) );
        }

        $items = array();
        foreach ( $order->get_items() as $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $lookup_id    = $variation_id ?: $product_id;
            $barcode      = get_post_meta( $lookup_id, '_wbi_barcode', true );

            $items[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'item_id'      => $item->get_id(),
                'barcode'      => $barcode,
                'name'         => $item->get_name(),
                'qty_required' => $item->get_quantity(),
                'qty_scanned'  => 0,
                'scanned_at'   => array(),
            );
        }

        $order->update_meta_data( '_wbi_picking_status',     'picking' );
        $order->update_meta_data( '_wbi_picking_data',       wp_json_encode( $items ) );
        $order->update_meta_data( '_wbi_picking_started_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_wbi_picking_user',       get_current_user_id() );
        // Reset audit log on each new picking session
        $order->update_meta_data( '_wbi_picking_log', wp_json_encode( array() ) );
        $order->save();

        $order->add_order_note( '📦 Armado iniciado por ' . wp_get_current_user()->display_name );

        wp_send_json_success( array( 'items' => $items ) );
    }

    public function ajax_scan_item() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $barcode  = isset( $_POST['barcode'] )  ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';

        if ( ! $order_id || empty( $barcode ) ) wp_send_json_error( 'Datos incompletos' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        if ( ! is_array( $picking_data ) ) wp_send_json_error( 'No hay datos de picking' );

        $found            = false;
        $already_complete = false;
        $matched_index    = -1;

        // --- First pass: try QR token decode ---
        $qr_product_id   = 0;
        $qr_variation_id = 0;
        if ( class_exists( 'WBI_Product_QR_Module' ) ) {
            $qr_result = WBI_Product_QR_Module::parse_token( $barcode );
            if ( ! is_wp_error( $qr_result ) ) {
                $qr_product_id   = $qr_result['product_id'];
                $qr_variation_id = $qr_result['variation_id'];
            }
        }

        if ( $qr_product_id ) {
            foreach ( $picking_data as $idx => &$item ) {
                $pid_match = ( (int) $item['product_id'] === $qr_product_id );
                $vid_match = $qr_variation_id
                    ? ( (int) $item['variation_id'] === $qr_variation_id )
                    : ( 0 === (int) $item['variation_id'] );
                if ( $pid_match && $vid_match ) {
                    $found         = true;
                    $matched_index = $idx;
                    if ( $item['qty_scanned'] >= $item['qty_required'] ) {
                        $already_complete = true;
                    } else {
                        $item['qty_scanned']++;
                        $item['scanned_at'][] = current_time( 'mysql' );
                    }
                    break;
                }
            }
            unset( $item );
        }

        // --- Second pass: barcode string match ---
        if ( ! $found ) {
            foreach ( $picking_data as $idx => &$item ) {
                if ( isset( $item['barcode'] ) && $item['barcode'] === $barcode ) {
                    $found         = true;
                    $matched_index = $idx;
                    if ( $item['qty_scanned'] >= $item['qty_required'] ) {
                        $already_complete = true;
                    } else {
                        $item['qty_scanned']++;
                        $item['scanned_at'][] = current_time( 'mysql' );
                    }
                    break;
                }
            }
            unset( $item );
        }

        if ( ! $found ) {
            // Third pass: search by product SKU as fallback
            foreach ( $picking_data as $idx => &$item ) {
                $lookup_id = $item['variation_id'] ?: $item['product_id'];
                $sku       = get_post_meta( $lookup_id, '_sku', true );
                if ( $sku && $sku === $barcode ) {
                    $found         = true;
                    $matched_index = $idx;
                    if ( $item['qty_scanned'] >= $item['qty_required'] ) {
                        $already_complete = true;
                    } else {
                        $item['qty_scanned']++;
                        $item['scanned_at'][] = current_time( 'mysql' );
                    }
                    break;
                }
            }
            unset( $item );
        }

        if ( ! $found ) {
            wp_send_json_error( array(
                'message' => 'Este producto no pertenece a este pedido',
                'barcode' => $barcode,
                'type'    => 'not_found',
            ) );
        }

        if ( $already_complete ) {
            wp_send_json_error( array(
                'message' => 'Ya se escanearon todos los items de: ' . $picking_data[ $matched_index ]['name'],
                'barcode' => $barcode,
                'type'    => 'already_complete',
            ) );
        }

        $order->update_meta_data( '_wbi_picking_data', wp_json_encode( $picking_data ) );
        $order->save();

        $total_required = array_sum( array_column( $picking_data, 'qty_required' ) );
        $total_scanned  = array_sum( array_column( $picking_data, 'qty_scanned' ) );
        $all_complete   = ( $total_scanned >= $total_required );

        wp_send_json_success( array(
            'items'          => $picking_data,
            'scanned_item'   => $picking_data[ $matched_index ],
            'matched_index'  => $matched_index,
            'total_required' => $total_required,
            'total_scanned'  => $total_scanned,
            'all_complete'   => $all_complete,
        ) );
    }

    public function ajax_complete_picking() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $started = $order->get_meta( '_wbi_picking_started_at' );
        $now     = current_time( 'mysql' );
        $minutes = $started ? round( ( strtotime( $now ) - strtotime( $started ) ) / 60, 1 ) : 0;

        $order->update_meta_data( '_wbi_picking_status',       'picked' );
        $order->update_meta_data( '_wbi_picking_completed_at', $now );
        $order->save();

        // Automatically transition WooCommerce order to completed
        $order->update_status( 'completed', __( 'Armado finalizado por picking.', 'wbi-suite' ) );

        $user = wp_get_current_user();
        $order->add_order_note( '✅ Armado completado por ' . $user->display_name . ' — Tiempo: ' . $minutes . ' min' );

        wp_send_json_success( array(
            'message'  => 'Armado completado en ' . $minutes . ' minutos',
            'redirect' => admin_url( 'admin.php?page=wbi-picking&completed=1' ),
        ) );
    }

    public function ajax_reset_picking() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $order->delete_meta_data( '_wbi_picking_status' );
        $order->delete_meta_data( '_wbi_picking_data' );
        $order->delete_meta_data( '_wbi_picking_started_at' );
        $order->delete_meta_data( '_wbi_picking_completed_at' );
        $order->delete_meta_data( '_wbi_picking_user' );
        $order->save();

        $order->add_order_note( '🔄 Armado reiniciado por ' . wp_get_current_user()->display_name );

        wp_send_json_success( array( 'message' => 'Picking reiniciado' ) );
    }

    // =========================================================================
    // Picking column in orders list
    // =========================================================================

    public function add_picking_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $val ) {
            $new[ $key ] = $val;
            if ( 'order_status' === $key ) {
                $new['wbi_picking'] = '📦 Armado';
            }
        }
        return $new;
    }

    public function render_picking_column( $column, $post_id ) {
        if ( 'wbi_picking' !== $column ) return;
        $order  = wc_get_order( $post_id );
        $status = $order ? $order->get_meta( '_wbi_picking_status' ) : '';
        $this->render_picking_status_badge( $status );
    }

    // =========================================================================
    // Metabox in order detail
    // =========================================================================

    public function add_picking_metabox() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'wbi_picking_box',
                '📦 Estado de Armado — wooErp',
                array( $this, 'render_picking_metabox' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_picking_metabox( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            $order_id = $post_or_order->ID;
        } else {
            $order_id = $post_or_order->get_id();
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $status    = $order->get_meta( '_wbi_picking_status' );
        $started   = $order->get_meta( '_wbi_picking_started_at' );
        $completed = $order->get_meta( '_wbi_picking_completed_at' );
        $user_id   = $order->get_meta( '_wbi_picking_user' );

        $status_labels = array(
            ''        => '⏳ Pendiente de armado',
            'picking' => '🔄 En proceso de armado',
            'picked'  => '✅ Armado completo',
            'packed'  => '📦 Despachado',
        );

        $label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status_labels[''];
        echo '<p><strong>Estado:</strong> ' . esc_html( $label ) . '</p>';

        if ( $started ) {
            echo '<p><small>Iniciado: ' . esc_html( $started ) . '</small></p>';
        }
        if ( $completed ) {
            echo '<p><small>Completado: ' . esc_html( $completed ) . '</small></p>';
        }
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            echo '<p><small>Operador: ' . esc_html( $user ? $user->display_name : '#' . $user_id ) . '</small></p>';
        }

        // Picking progress
        $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        if ( is_array( $picking_data ) && ! empty( $picking_data ) ) {
            $total_req = array_sum( array_column( $picking_data, 'qty_required' ) );
            $total_scn = array_sum( array_column( $picking_data, 'qty_scanned' ) );
            $pct       = $total_req > 0 ? round( $total_scn / $total_req * 100 ) : 0;
            echo '<div style="background:#f0f0f1;border-radius:3px;height:20px;margin:8px 0;">';
            echo '<div style="background:#00a32a;height:100%;border-radius:3px;width:' . intval( $pct ) . '%;transition:width 0.3s;"></div>';
            echo '</div>';
            echo '<p style="text-align:center;"><strong>' . intval( $total_scn ) . '/' . intval( $total_req ) . '</strong> items (' . intval( $pct ) . '%)</p>';
        }

        if ( ! $status || 'picking' === $status ) {
            $picking_url = admin_url( 'admin.php?page=wbi-picking&order_id=' . $order_id );
            echo '<p><a href="' . esc_url( $picking_url ) . '" class="button button-primary" style="width:100%;text-align:center;">';
            echo 'picking' === $status ? 'Continuar Armado' : 'Iniciar Armado';
            echo '</a></p>';
        }

        // Picking audit log
        $picking_log = json_decode( $order->get_meta( '_wbi_picking_log' ), true );
        if ( is_array( $picking_log ) && ! empty( $picking_log ) ) {
            $action_labels = array(
                'edit_qty'    => '✏️ Edición de cantidad',
                'remove_item' => '🗑️ Eliminación de ítem',
                'add_item'    => '➕ Agregado de ítem',
            );
            echo '<hr style="margin:12px 0;">';
            echo '<p style="font-weight:bold;margin-bottom:6px;">📋 Historial de cambios</p>';
            echo '<div style="max-height:200px;overflow-y:auto;font-size:12px;">';
            foreach ( array_reverse( $picking_log ) as $entry ) {
                $action_label = isset( $action_labels[ $entry['action'] ] ) ? $action_labels[ $entry['action'] ] : esc_html( $entry['action'] );
                echo '<div style="border-bottom:1px solid #eee;padding:6px 0;">';
                echo '<div><strong>' . esc_html( $action_label ) . '</strong></div>';
                echo '<div>' . esc_html( $entry['item_name'] ) . '</div>';
                if ( isset( $entry['qty_before'], $entry['qty_after'] ) && 'remove_item' !== $entry['action'] && 'add_item' !== $entry['action'] ) {
                    echo '<div>' . intval( $entry['qty_before'] ) . ' → ' . intval( $entry['qty_after'] ) . '</div>';
                } elseif ( 'add_item' === $entry['action'] ) {
                    echo '<div>Cantidad: ' . intval( $entry['qty_after'] ) . '</div>';
                }
                echo '<div style="color:#646970;">Motivo: ' . esc_html( $entry['reason'] ) . '</div>';
                echo '<div style="color:#646970;">' . esc_html( $entry['user_name'] ) . ' — ' . esc_html( $entry['timestamp'] ) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
    }

    // =========================================================================
    // Helper: check if current user can perform picking actions
    //
    // This is the OPERATIONAL permission check: can the user actually pick items?
    // Both managers (manage_woocommerce) and warehouse staff (wbi_armador role)
    // can perform picking operations regardless of module-level settings.
    // =========================================================================

    private function current_user_can_pick() {
        $user = wp_get_current_user();
        return current_user_can( 'manage_woocommerce' ) || in_array( 'wbi_armador', (array) $user->roles, true );
    }

    // =========================================================================
    // AJAX: Mark item as picked/missing/replaced
    // =========================================================================

    public function ajax_mark_item() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id    = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $item_id     = isset( $_POST['item_id'] )  ? intval( $_POST['item_id'] )  : 0;
        $status      = isset( $_POST['status'] )   ? sanitize_key( $_POST['status'] ) : '';
        $replacement = isset( $_POST['replacement'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement'] ) ) : '';
        $notes       = isset( $_POST['notes'] )    ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( ! $order_id || ! $item_id ) wp_send_json_error( 'Datos incompletos' );
        if ( ! in_array( $status, array( 'picked', 'missing', 'replaced' ), true ) ) wp_send_json_error( 'Estado inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $order->update_meta_data( '_wbi_picking_item_' . $item_id . '_status',      $status );
        $order->update_meta_data( '_wbi_picking_item_' . $item_id . '_replacement', $replacement );
        $order->update_meta_data( '_wbi_picking_item_' . $item_id . '_notes',       $notes );
        $order->save();

        wp_send_json_success( array( 'status' => $status ) );
    }

    // =========================================================================
    // AJAX: Save order picking notes
    // =========================================================================

    public function ajax_save_order_notes() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $notes    = isset( $_POST['notes'] )    ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $order->update_meta_data( '_wbi_picking_order_notes', $notes );
        $order->save();
        wp_send_json_success( array( 'saved' => true ) );
    }

    // =========================================================================
    // AJAX: Edit item quantity with audit log
    // =========================================================================

    public function ajax_edit_qty() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $item_id  = isset( $_POST['item_id'] )  ? intval( $_POST['item_id'] )  : 0;
        $new_qty  = isset( $_POST['new_qty'] )  ? intval( $_POST['new_qty'] )  : 0;
        $reason   = isset( $_POST['reason'] )   ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( ! $order_id || ! $item_id ) wp_send_json_error( 'Datos incompletos' );
        if ( $new_qty < 1 ) wp_send_json_error( 'La cantidad debe ser mayor a 0' );
        if ( empty( $reason ) ) wp_send_json_error( 'El motivo es obligatorio' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        // Update the WooCommerce order item quantity
        $qty_before = 0;
        $item_name  = '';
        foreach ( $order->get_items() as $wc_item ) {
            if ( (int) $wc_item->get_id() === $item_id ) {
                $qty_before = (int) $wc_item->get_quantity();
                $item_name  = $wc_item->get_name();
                $wc_item->set_quantity( $new_qty );
                $wc_item->save();
                break;
            }
        }

        $order->calculate_totals();
        $order->save();

        // Update picking data
        $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        if ( is_array( $picking_data ) ) {
            foreach ( $picking_data as &$pitem ) {
                if ( (int) $pitem['item_id'] === $item_id ) {
                    $pitem['qty_required'] = $new_qty;
                    // Clamp qty_scanned to new required
                    if ( $pitem['qty_scanned'] > $new_qty ) {
                        $pitem['qty_scanned'] = $new_qty;
                    }
                    break;
                }
            }
            unset( $pitem );
            $order->update_meta_data( '_wbi_picking_data', wp_json_encode( $picking_data ) );
            $order->save();
        }

        // Audit log
        $current_user = wp_get_current_user();
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'user_id'   => get_current_user_id(),
            'user_name' => $current_user->display_name,
            'action'    => 'edit_qty',
            'item_id'   => $item_id,
            'item_name' => $item_name,
            'qty_before' => $qty_before,
            'qty_after'  => $new_qty,
            'reason'    => $reason,
        );
        $this->append_picking_log( $order, $log_entry );

        $order->add_order_note(
            sprintf(
                '✏️ Picking: cantidad de "%s" modificada de %d a %d por %s. Motivo: %s',
                $item_name,
                $qty_before,
                $new_qty,
                $current_user->display_name,
                $reason
            )
        );

        $picking_data_final = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        $total_required     = is_array( $picking_data_final ) ? array_sum( array_column( $picking_data_final, 'qty_required' ) ) : 0;
        $total_scanned      = is_array( $picking_data_final ) ? array_sum( array_column( $picking_data_final, 'qty_scanned' ) )  : 0;

        wp_send_json_success( array(
            'message'        => 'Cantidad actualizada',
            'total_required' => $total_required,
            'total_scanned'  => $total_scanned,
        ) );
    }

    // =========================================================================
    // AJAX: Remove item with audit log
    // =========================================================================

    public function ajax_remove_item() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $item_id  = isset( $_POST['item_id'] )  ? intval( $_POST['item_id'] )  : 0;
        $reason   = isset( $_POST['reason'] )   ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( ! $order_id || ! $item_id ) wp_send_json_error( 'Datos incompletos' );
        if ( empty( $reason ) ) wp_send_json_error( 'El motivo es obligatorio' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $item_name = '';
        $item_qty  = 0;
        foreach ( $order->get_items() as $wc_item ) {
            if ( (int) $wc_item->get_id() === $item_id ) {
                $item_name = $wc_item->get_name();
                $item_qty  = (int) $wc_item->get_quantity();
                break;
            }
        }

        if ( ! $item_name ) wp_send_json_error( 'Ítem no encontrado en el pedido' );

        $order->remove_item( $item_id );
        $order->calculate_totals();
        $order->save();

        // Update picking data
        $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        if ( is_array( $picking_data ) ) {
            $picking_data = array_values( array_filter( $picking_data, function( $pitem ) use ( $item_id ) {
                return (int) $pitem['item_id'] !== $item_id;
            } ) );
            $order->update_meta_data( '_wbi_picking_data', wp_json_encode( $picking_data ) );
            $order->save();
        }

        // Audit log
        $current_user = wp_get_current_user();
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'user_id'   => get_current_user_id(),
            'user_name' => $current_user->display_name,
            'action'    => 'remove_item',
            'item_id'   => $item_id,
            'item_name' => $item_name,
            'qty_before' => $item_qty,
            'qty_after'  => 0,
            'reason'    => $reason,
        );
        $this->append_picking_log( $order, $log_entry );

        $order->add_order_note(
            sprintf(
                '🗑️ Picking: producto "%s" eliminado del pedido por %s. Motivo: %s',
                $item_name,
                $current_user->display_name,
                $reason
            )
        );

        wp_send_json_success( array( 'message' => 'Producto eliminado' ) );
    }

    // =========================================================================
    // AJAX: Add item with audit log
    // =========================================================================

    public function ajax_add_item() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! $this->current_user_can_pick() ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $code     = isset( $_POST['code'] )     ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $qty      = isset( $_POST['qty'] )      ? intval( $_POST['qty'] )      : 1;
        $reason   = isset( $_POST['reason'] )   ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( ! $order_id || empty( $code ) ) wp_send_json_error( 'Datos incompletos' );
        if ( $qty < 1 ) wp_send_json_error( 'La cantidad debe ser mayor a 0' );
        if ( empty( $reason ) ) wp_send_json_error( 'El motivo es obligatorio' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        // Resolve product from QR token, barcode, or SKU
        $product_id   = 0;
        $variation_id = 0;
        $product      = null;

        // Try QR token first
        if ( class_exists( 'WBI_Product_QR_Module' ) ) {
            $qr_result = WBI_Product_QR_Module::parse_token( $code );
            if ( ! is_wp_error( $qr_result ) ) {
                $product_id   = $qr_result['product_id'];
                $variation_id = $qr_result['variation_id'];
                $product      = wc_get_product( $variation_id ?: $product_id );
            }
        }

        // Try barcode meta
        if ( ! $product ) {
            global $wpdb;
            $found_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wbi_barcode' AND meta_value = %s LIMIT 1",
                $code
            ) );
            if ( $found_id ) {
                $p = wc_get_product( (int) $found_id );
                if ( $p ) {
                    $product = $p;
                    if ( 'product_variation' === $p->get_type() || $p instanceof WC_Product_Variation ) {
                        $variation_id = (int) $found_id;
                        $product_id   = (int) $p->get_parent_id();
                    } else {
                        $product_id = (int) $found_id;
                    }
                }
            }
        }

        // Try SKU
        if ( ! $product ) {
            $product_id_by_sku = wc_get_product_id_by_sku( $code );
            if ( $product_id_by_sku ) {
                $product = wc_get_product( $product_id_by_sku );
                if ( $product ) {
                    if ( 'product_variation' === $product->get_type() ) {
                        $variation_id = $product_id_by_sku;
                        $product_id   = (int) $product->get_parent_id();
                    } else {
                        $product_id = $product_id_by_sku;
                    }
                }
            }
        }

        if ( ! $product ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado para el código: ' . $code ) );
        }

        // Add to WooCommerce order
        $parent_product = wc_get_product( $product_id );
        if ( $variation_id ) {
            $variation_obj = wc_get_product( $variation_id );
            $variation_data = array();
            if ( $variation_obj ) {
                foreach ( $variation_obj->get_variation_attributes() as $attr_key => $attr_val ) {
                    $variation_data[ $attr_key ] = $attr_val;
                }
            }
            $new_item_id = $order->add_product( $variation_obj ?: $parent_product, $qty, array( 'variation' => $variation_data ) );
        } else {
            $new_item_id = $order->add_product( $parent_product, $qty );
        }

        if ( ! $new_item_id ) {
            wp_send_json_error( 'No se pudo agregar el producto al pedido' );
        }

        $order->calculate_totals();
        $order->save();

        // Append to picking data
        $barcode      = get_post_meta( $variation_id ?: $product_id, '_wbi_barcode', true );
        $picking_data = json_decode( $order->get_meta( '_wbi_picking_data' ), true );
        if ( is_array( $picking_data ) ) {
            $picking_data[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'item_id'      => $new_item_id,
                'barcode'      => $barcode,
                'name'         => $product->get_name(),
                'qty_required' => $qty,
                'qty_scanned'  => 0,
                'scanned_at'   => array(),
            );
            $order->update_meta_data( '_wbi_picking_data', wp_json_encode( $picking_data ) );
            $order->save();
        }

        // Audit log
        $current_user = wp_get_current_user();
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'user_id'   => get_current_user_id(),
            'user_name' => $current_user->display_name,
            'action'    => 'add_item',
            'item_id'   => $new_item_id,
            'item_name' => $product->get_name(),
            'qty_before' => 0,
            'qty_after'  => $qty,
            'reason'    => $reason,
        );
        $this->append_picking_log( $order, $log_entry );

        $order->add_order_note(
            sprintf(
                '➕ Picking: producto "%s" (x%d) agregado al pedido por %s. Motivo: %s',
                $product->get_name(),
                $qty,
                $current_user->display_name,
                $reason
            )
        );

        wp_send_json_success( array( 'message' => 'Producto agregado', 'item_name' => $product->get_name() ) );
    }

    // =========================================================================
    // Helper: append an entry to the picking audit log
    // =========================================================================

    private function append_picking_log( $order, array $entry ) {
        $log = json_decode( $order->get_meta( '_wbi_picking_log' ), true );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = $entry;
        $order->update_meta_data( '_wbi_picking_log', wp_json_encode( $log ) );
        $order->save();
    }

    public function render_armador_panel() {
        global $wpdb;

        $user = wp_get_current_user();
        $is_armador  = in_array( 'wbi_armador', (array) $user->roles, true );
        $is_manager  = current_user_can( 'manage_woocommerce' );

        if ( ! $is_armador && ! $this->user_has_picking_access() ) {
            wp_die( esc_html__( 'No tenés permisos para acceder al Panel de Armado.', 'wbi-suite' ) );
        }

        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

        // Count total and paginated display — HPOS-compatible direct $wpdb queries
        $hpos       = $this->is_hpos_enabled();
        $ot         = $this->get_orders_table_name();
        $mt         = $this->get_orders_meta_table_name();
        $id_col     = $hpos ? 'o.id'               : 'p.ID';
        $alias      = $hpos ? 'o'                  : 'p';
        $type_col   = $hpos ? 'o.type'             : 'p.post_type';
        $status_col = $hpos ? 'o.status'           : 'p.post_status';
        $date_col   = $hpos ? 'o.date_created_gmt' : 'p.post_date';
        $meta_fk    = $hpos ? 'order_id'           : 'post_id';
        $offset     = ( $paged - 1 ) * $per_page;

        // Exclude orders where _wbi_picking_status = 'picked'
        $base_where = "FROM {$ot} {$alias}
             WHERE {$type_col} = 'shop_order'
               AND {$status_col} IN ('wc-processing', 'wc-on-hold')
               AND {$id_col} NOT IN (
                   SELECT {$meta_fk} FROM {$mt}
                   WHERE meta_key = '_wbi_picking_status'
                     AND meta_value = 'picked'
               )";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT {$id_col}) {$base_where}" );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $display_orders = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT {$id_col} {$base_where} ORDER BY {$date_col} DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );
        ?>
        <div class="wrap">
            <h1>Panel de Armado <span style="background:#d63638;color:#fff;border-radius:12px;padding:2px 10px;font-size:14px;margin-left:8px;"><?php echo intval( $total ); ?></span></h1>
            <p>Pedidos pendientes de armado. Solo se muestran los datos necesarios para preparar los pedidos.</p>

            <?php if ( empty( $display_orders ) ) : ?>
                <div class="notice notice-success inline"><p>No hay pedidos pendientes de armado.</p></div>
            <?php else :
                $from = $offset + 1;
                $to   = min( $offset + $per_page, $total );
                echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' pedidos.</p>';
            ?>
                <div class="wbi-table-responsive">
                <table class="widefat striped" style="margin-top:16px;">
                    <thead>
                        <tr>
                            <th>#Pedido</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Estado Armado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $display_orders as $order_id ) :
                        $order = wc_get_order( $order_id );
                        if ( ! $order ) : continue; endif;
                        $picking_status = $order->get_meta( '_wbi_picking_status' );
                    ?>
                        <tr>
                            <td>
                                <strong>#<?php echo intval( $order_id ); ?></strong>
                            </td>
                            <td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '—' ); ?></td>
                            <td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
                            <td><?php echo intval( $order->get_item_count() ); ?></td>
                            <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                            <td><?php $this->render_picking_status_badge( $picking_status ); ?></td>
                            <td>
                                <?php if ( $is_manager ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-picking&order_id=' . $order_id ) ); ?>"
                                       class="button button-primary button-small">
                                        Abrir picking completo
                                    </a>
                                <?php else : ?>
                                    <button class="button button-small wbi-armador-toggle"
                                            data-order="<?php echo intval( $order_id ); ?>">
                                        Ver items
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ( ! $is_manager ) : ?>
                        <tr class="wbi-armador-detail" id="wbi-detail-<?php echo intval( $order_id ); ?>" style="display:none;">
                            <td colspan="7" style="padding:0;">
                                <div style="padding:12px 20px;background:#f9f9f9;border-top:1px solid #e0e0e0;">
                                    <div class="wbi-table-responsive">
                                    <table class="widefat striped" style="margin:0;">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>SKU</th>
                                                <th>Cantidad</th>
                                                <th>Código de Barra</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ( $order->get_items() as $item ) :
                                            $product      = $item->get_product();
                                            $sku          = $product ? esc_html( $product->get_sku() ) : '—';
                                            $product_id   = $item->get_product_id();
                                            $variation_id = $item->get_variation_id();
                                            $lookup_id    = $variation_id ?: $product_id;
                                            $barcode      = get_post_meta( $lookup_id, '_wbi_barcode', true );
                                            $item_id      = $item->get_id();
                                            $item_status  = $order->get_meta( '_wbi_picking_item_' . $item_id . '_status' );
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html( $item->get_name() ); ?></td>
                                                <td><?php echo esc_html( $sku ); ?></td>
                                                <td><?php echo intval( $item->get_quantity() ); ?></td>
                                                <td><?php echo $barcode ? '<code>' . esc_html( $barcode ) . '</code>' : '<span style="color:#aaa;">—</span>'; ?></td>
                                                <td>
                                                    <?php if ( 'picked' === $item_status ) : ?>
                                                        <span style="color:#00a32a;font-weight:bold;">✅ Agarrado</span>
                                                    <?php elseif ( 'missing' === $item_status ) : ?>
                                                        <span style="color:#d63638;font-weight:bold;">❌ Faltante</span>
                                                    <?php elseif ( 'replaced' === $item_status ) : ?>
                                                        <span style="color:#dba617;font-weight:bold;">Reemplazado</span>
                                                    <?php else : ?>
                                                        <span style="color:#646970;">Pendiente</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <script>
                (function() {
                    document.querySelectorAll('.wbi-armador-toggle').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var orderId = this.dataset.order;
                            var detail  = document.getElementById('wbi-detail-' + orderId);
                            if ( detail ) {
                                var visible = detail.style.display !== 'none';
                                detail.style.display = visible ? 'none' : 'table-row';
                                this.textContent     = visible ? 'Ver items' : 'Ocultar items';
                            }
                        });
                    });
                })();
                </script>

                <?php if ( $total > $per_page ) :
                    $pagination = paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => ceil( $total / $per_page ),
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    if ( $pagination ) : ?>
                        <div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;"><?php echo $pagination; ?></div></div>
                    <?php endif;
                endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Helper: render picking status badge
    // =========================================================================

    private function render_picking_status_badge( $status ) {
        switch ( $status ) {
            case 'picking':
                echo '<span style="color:orange;font-weight:bold;">🔄 En proceso</span>';
                break;
            case 'picked':
                echo '<span style="color:green;font-weight:bold;">✅ Armado</span>';
                break;
            case 'packed':
                echo '<span style="color:#2271b1;font-weight:bold;">📦 Despachado</span>';
                break;
            default:
                echo '<span style="color:#999;">⏳ Pendiente</span>';
        }
    }

    // =========================================================================
    // Helper: HPOS column render (receives WC_Order object)
    // =========================================================================

    public function render_picking_column_hpos( $column, $order ) {
        if ( 'wbi_picking' !== $column ) return;
        $status = $order->get_meta( '_wbi_picking_status' );
        $this->render_picking_status_badge( $status );
    }

    // =========================================================================
    // Helper: check if current user has access to the picking module via settings
    //
    // This is the MODULE-LEVEL permission check: replicates the logic of
    // WBI_Suite_Loader::user_can_access_module('picking') for use within this
    // module. It reads the 'wbi_permissions_picking' setting from the plugin
    // configuration page (Settings > Permisos por Módulo).
    //
    // Note: this is intentionally separate from current_user_can_pick(), which
    // controls who can perform operational picking actions (including the
    // wbi_armador role that always has access regardless of module settings).
    // =========================================================================

    private function user_has_picking_access() {
        $user     = wp_get_current_user();
        $opts     = get_option( 'wbi_modules_settings', array() );
        $perm_key = 'wbi_permissions_picking';
        $allowed  = ( isset( $opts[ $perm_key ] ) && ! empty( $opts[ $perm_key ] ) )
            ? (array) $opts[ $perm_key ]
            : array( 'administrator' );
        return (bool) array_intersect( (array) $user->roles, $allowed );
    }

    // =========================================================================
    // HPOS helpers — detect High-Performance Order Storage and return correct
    // table names for direct $wpdb queries.
    // =========================================================================

    private function is_hpos_enabled() {
        if ( null === $this->hpos_enabled ) {
            $this->hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return $this->hpos_enabled;
    }

    private function get_orders_table_name() {
        global $wpdb;
        return $this->is_hpos_enabled() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
    }

    private function get_orders_meta_table_name() {
        global $wpdb;
        return $this->is_hpos_enabled() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
    }
}
