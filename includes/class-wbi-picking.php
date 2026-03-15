<?php
/**
 * WBI Picking Module
 * Picking & Armado de Pedidos con escaneo de códigos de barra.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Picking_Module {

    public function __construct() {
        // Admin menu
        add_action( 'admin_menu', array( $this, 'register_pages' ), 100 );

        // Order meta box — picking status
        add_action( 'add_meta_boxes', array( $this, 'add_picking_metabox' ) );

        // Custom order status column in WooCommerce orders list
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_picking_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_picking_column' ), 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_wbi_picking_start',    array( $this, 'ajax_start_picking' ) );
        add_action( 'wp_ajax_wbi_picking_scan',     array( $this, 'ajax_scan_item' ) );
        add_action( 'wp_ajax_wbi_picking_complete', array( $this, 'ajax_complete_picking' ) );
        add_action( 'wp_ajax_wbi_picking_reset',    array( $this, 'ajax_reset_picking' ) );

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
            '📦 Picking & Armado',
            'manage_woocommerce',
            'wbi-picking',
            array( $this, 'render_picking_list' )
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
        $order_id   = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'pending';

        if ( $order_id ) {
            $this->render_picking_interface( $order_id );
            return;
        }
        ?>
        <div class="wrap">
            <h1>📦 Picking & Armado de Pedidos</h1>

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

        // Lightweight total count (no ORDER BY, no data fetching)
        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
               AND p.post_status = 'wc-processing'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_wbi_picking_status'
                     AND meta_value != ''
               )"
        );

        // Paginated query
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
               AND p.post_status = 'wc-processing'
               AND p.ID NOT IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_wbi_picking_status'
                     AND meta_value != ''
               )
             ORDER BY p.post_date ASC
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
        $per_page = 20;
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        // Total count for pagination
        $count_query = new WP_Query( array(
            'post_type'      => 'shop_order',
            'post_status'    => array( 'wc-processing', 'wc-on-hold' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => '_wbi_picking_status', 'value' => 'picking', 'compare' => '=' ),
            ),
        ) );
        $total_count = $count_query->found_posts;

        $orders = get_posts( array(
            'post_type'      => 'shop_order',
            'post_status'    => array( 'wc-processing', 'wc-on-hold' ),
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => '_wbi_picking_status', 'value' => 'picking', 'compare' => '=' ),
            ),
        ) );

        echo '<h2>🔄 Pedidos en Proceso</h2>';

        if ( empty( $orders ) ) {
            echo '<p>No hay pedidos en proceso de armado.</p>';
            return;
        }

        $offset = ( $paged - 1 ) * $per_page;
        $from   = $offset + 1;
        $to     = min( $offset + $per_page, $total_count );
        echo '<p style="margin-bottom:8px;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total_count ) . ' pedidos</p>';

        echo '<table class="widefat striped wbi-sortable"><thead><tr>
            <th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Progreso</th><th>Operador</th><th>Acción</th>
        </tr></thead><tbody>';

        foreach ( $orders as $oid ) {
            $order       = wc_get_order( $oid );
            if ( ! $order ) continue;
            $picking_data = json_decode( get_post_meta( $oid, '_wbi_picking_data', true ), true );
            $user_id      = get_post_meta( $oid, '_wbi_picking_user', true );
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
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '';
        $per_page  = 20;
        $paged     = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        $meta_query = array(
            array(
                'key'     => '_wbi_picking_status',
                'value'   => array( 'picked', 'packed' ),
                'compare' => 'IN',
            ),
        );

        $date_query = array();
        if ( $date_from ) {
            $date_query[] = array( 'after' => $date_from, 'inclusive' => true );
        }
        if ( $date_to ) {
            $date_query[] = array( 'before' => $date_to, 'inclusive' => true );
        }

        // Total count for pagination
        $count_query = new WP_Query( array(
            'post_type'      => 'shop_order',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
            'date_query'     => $date_query,
        ) );
        $total_count = $count_query->found_posts;

        $orders = get_posts( array(
            'post_type'      => 'shop_order',
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
            'date_query'     => $date_query,
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

        if ( empty( $orders ) ) {
            echo '<p>No hay pedidos completados' . ( $date_from || $date_to ? ' en el rango seleccionado' : '' ) . '.</p>';
            return;
        }

        $offset = ( $paged - 1 ) * $per_page;
        $from   = $offset + 1;
        $to     = min( $offset + $per_page, $total_count );
        echo '<p style="margin-bottom:8px;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total_count ) . ' pedidos</p>';

        echo '<table class="widefat striped wbi-sortable"><thead><tr>
            <th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Items</th>
            <th>Tiempo de Armado</th><th>Operador</th><th>Estado</th>
        </tr></thead><tbody>';

        foreach ( $orders as $oid ) {
            $order    = wc_get_order( $oid );
            if ( ! $order ) continue;
            $status   = get_post_meta( $oid, '_wbi_picking_status', true );
            $started  = get_post_meta( $oid, '_wbi_picking_started_at', true );
            $finished = get_post_meta( $oid, '_wbi_picking_completed_at', true );
            $user_id  = get_post_meta( $oid, '_wbi_picking_user', true );
            $user     = $user_id ? get_userdata( $user_id ) : null;

            $minutes = '—';
            if ( $started && $finished ) {
                $diff    = strtotime( $finished ) - strtotime( $started );
                $minutes = round( $diff / 60, 1 ) . ' min';
            }

            $picking_data = json_decode( get_post_meta( $oid, '_wbi_picking_data', true ), true );
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

        $picking_status = get_post_meta( $order_id, '_wbi_picking_status', true );
        $picking_data   = json_decode( get_post_meta( $order_id, '_wbi_picking_data', true ), true );

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
                           placeholder="Escanea el código de barra..."
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
                <h3 style="margin-top:0;">📋 Items del Pedido</h3>
                <table class="widefat striped" id="wbi-items-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Código de Barra</th>
                            <th>Cant. Requerida</th>
                            <th>Cant. Escaneada</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $picking_data as $idx => $item ) :
                            $scanned   = intval( $item['qty_scanned'] );
                            $required  = intval( $item['qty_required'] );
                            $item_status = $scanned === 0 ? 'pending' : ( $scanned >= $required ? 'complete' : 'partial' );
                            $status_icon = array(
                                'pending'  => '❌ Pendiente',
                                'partial'  => '🔄 Parcial',
                                'complete' => '✅ Completo',
                            );
                        ?>
                        <tr id="wbi-item-row-<?php echo intval( $idx ); ?>"
                            data-barcode="<?php echo esc_attr( $item['barcode'] ); ?>"
                            style="transition:background 0.3s;">
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
                            <td id="wbi-status-<?php echo intval( $idx ); ?>"><?php echo esc_html( $status_icon[ $item_status ] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                <button id="wbi-complete-btn" class="button button-primary"
                        style="font-size:16px;padding:10px 24px;<?php echo $pct < 100 ? 'display:none;' : ''; ?>">
                    ✅ Completar Armado
                </button>
                <button id="wbi-reset-btn" class="button"
                        style="font-size:14px;color:#d63638;border-color:#d63638;">
                    🔄 Reiniciar Picking
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
                                statusEl.textContent = '✅ Completo';
                            } else {
                                statusEl.textContent = '🔄 Parcial';
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
        })();
        </script>
        <?php
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    public function ajax_start_picking() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

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

        update_post_meta( $order_id, '_wbi_picking_status',     'picking' );
        update_post_meta( $order_id, '_wbi_picking_data',       wp_json_encode( $items ) );
        update_post_meta( $order_id, '_wbi_picking_started_at', current_time( 'mysql' ) );
        update_post_meta( $order_id, '_wbi_picking_user',       get_current_user_id() );

        $order->add_order_note( '📦 Armado iniciado por ' . wp_get_current_user()->display_name );

        wp_send_json_success( array( 'items' => $items ) );
    }

    public function ajax_scan_item() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $barcode  = isset( $_POST['barcode'] )  ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';

        if ( ! $order_id || empty( $barcode ) ) wp_send_json_error( 'Datos incompletos' );

        $picking_data = json_decode( get_post_meta( $order_id, '_wbi_picking_data', true ), true );
        if ( ! is_array( $picking_data ) ) wp_send_json_error( 'No hay datos de picking' );

        $found            = false;
        $already_complete = false;
        $matched_index    = -1;

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

        update_post_meta( $order_id, '_wbi_picking_data', wp_json_encode( $picking_data ) );

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
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Pedido no encontrado' );

        $started = get_post_meta( $order_id, '_wbi_picking_started_at', true );
        $now     = current_time( 'mysql' );
        $minutes = $started ? round( ( strtotime( $now ) - strtotime( $started ) ) / 60, 1 ) : 0;

        update_post_meta( $order_id, '_wbi_picking_status',       'picked' );
        update_post_meta( $order_id, '_wbi_picking_completed_at', $now );

        $user = wp_get_current_user();
        $order->add_order_note( '✅ Armado completado por ' . $user->display_name . ' — Tiempo: ' . $minutes . ' min' );

        wp_send_json_success( array(
            'message'  => 'Armado completado en ' . $minutes . ' minutos',
            'redirect' => admin_url( 'admin.php?page=wbi-picking&completed=1' ),
        ) );
    }

    public function ajax_reset_picking() {
        check_ajax_referer( 'wbi_picking_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) wp_send_json_error( 'ID inválido' );

        delete_post_meta( $order_id, '_wbi_picking_status' );
        delete_post_meta( $order_id, '_wbi_picking_data' );
        delete_post_meta( $order_id, '_wbi_picking_started_at' );
        delete_post_meta( $order_id, '_wbi_picking_completed_at' );
        delete_post_meta( $order_id, '_wbi_picking_user' );

        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note( '🔄 Armado reiniciado por ' . wp_get_current_user()->display_name );
        }

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
        $status = get_post_meta( $post_id, '_wbi_picking_status', true );
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
    // Metabox in order detail
    // =========================================================================

    public function add_picking_metabox() {
        add_meta_box(
            'wbi_picking_box',
            '📦 Estado de Armado — WBI',
            array( $this, 'render_picking_metabox' ),
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_picking_metabox( $post ) {
        $status    = get_post_meta( $post->ID, '_wbi_picking_status', true );
        $started   = get_post_meta( $post->ID, '_wbi_picking_started_at', true );
        $completed = get_post_meta( $post->ID, '_wbi_picking_completed_at', true );
        $user_id   = get_post_meta( $post->ID, '_wbi_picking_user', true );

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
        $picking_data = json_decode( get_post_meta( $post->ID, '_wbi_picking_data', true ), true );
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
            $picking_url = admin_url( 'admin.php?page=wbi-picking&order_id=' . $post->ID );
            echo '<p><a href="' . esc_url( $picking_url ) . '" class="button button-primary" style="width:100%;text-align:center;">';
            echo 'picking' === $status ? '🔄 Continuar Armado' : '▶ Iniciar Armado';
            echo '</a></p>';
        }
    }
}
