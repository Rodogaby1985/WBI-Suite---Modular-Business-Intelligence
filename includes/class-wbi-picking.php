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
        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        // Get processing orders that have no picking status (meta does not exist or is empty)
        $pending_ids = wc_get_orders( array(
            'status'     => 'processing',
            'limit'      => -1,
            'return'     => 'ids',
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key'     => '_wbi_picking_status',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_wbi_picking_status',
                    'value'   => '',
                    'compare' => '=',
                ),
            ),
        ) );

        $total     = count( $pending_ids );
        $order_ids = array_slice( $pending_ids, $offset, $per_page );

        echo '<h2>⏳ Pedidos Pendientes de Armado <span style="background:#d63638;color:#fff;border-radius:12px;padding:2px 10px;font-size:14px;margin-left:8px;">' . intval( $total ) . '</span></h2>';

        if ( 0 === $total ) {
            echo '<p style="color:#00a32a;">✅ No hay pedidos pendientes de armado.</p>';
            return;
        }

        // Showing X–Y of Z
        $from = $offset + 1;
        $to   = min( $offset + $per_page, $total );
        echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' pedidos.</p>';

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

        // Pagination links
        if ( $total > $per_page ) {
            $pagination = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => ceil( $total / $per_page ),
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            if ( $pagination ) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;">' . $pagination . '</div></div>';
            }
        }
    }

    // ---- Tab: En Proceso ----------------------------------------------------

    private function render_tab_in_progress() {
        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

        $count_args = array(
            'status'     => array( 'processing', 'on-hold' ),
            'limit'      => -1,
            'return'     => 'ids',
            'meta_query' => array(
                array( 'key' => '_wbi_picking_status', 'value' => 'picking', 'compare' => '=' ),
            ),
        );
        $all_in_progress = wc_get_orders( $count_args );
        $total           = count( $all_in_progress );

        $args = array(
            'status'     => array( 'processing', 'on-hold' ),
            'limit'      => $per_page,
            'page'       => $paged,
            'return'     => 'ids',
            'meta_query' => array(
                array( 'key' => '_wbi_picking_status', 'value' => 'picking', 'compare' => '=' ),
            ),
        );
        $orders = wc_get_orders( $args );

        echo '<h2>🔄 Pedidos en Proceso</h2>';

        if ( 0 === $total ) {
            echo '<p>No hay pedidos en proceso de armado.</p>';
            return;
        }

        // Showing X–Y of Z
        $offset = ( $paged - 1 ) * $per_page;
        $from   = $offset + 1;
        $to     = min( $offset + $per_page, $total );
        echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' pedidos.</p>';

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

        // Pagination links
        if ( $total > $per_page ) {
            $pagination = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => ceil( $total / $per_page ),
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            if ( $pagination ) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;">' . $pagination . '</div></div>';
            }
        }
    }

    // ---- Tab: Completados ---------------------------------------------------

    private function render_tab_completed() {
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '';

        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

        // Build status list for wc_get_orders(): strip the 'wc-' prefix safely from each key.
        $all_statuses = array_map(
            function( $s ) { return 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s; },
            array_keys( wc_get_order_statuses() )
        );

        $query_args = array(
            'status'     => $all_statuses,
            'limit'      => -1,
            'return'     => 'ids',
            'meta_query' => array(
                array(
                    'key'     => '_wbi_picking_status',
                    'value'   => array( 'picked', 'packed' ),
                    'compare' => 'IN',
                ),
            ),
        );

        if ( $date_from && $date_to ) {
            $query_args['date_created'] = $date_from . '...' . $date_to;
        } elseif ( $date_from ) {
            $query_args['date_created'] = '>=' . $date_from;
        } elseif ( $date_to ) {
            $query_args['date_created'] = '<=' . $date_to;
        }

        $all_ids = wc_get_orders( $query_args );
        $total   = count( $all_ids );

        $offset  = ( $paged - 1 ) * $per_page;
        $orders  = array_slice( $all_ids, $offset, $per_page );

        echo '<h2>✅ Pedidos Completados</h2>';

        // Date filter form
        echo '<form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="wbi-picking">
            <input type="hidden" name="tab" value="completed">
            Desde: <input type="date" name="date_from" value="' . esc_attr( $date_from ) . '">
            Hasta: <input type="date" name="date_to" value="' . esc_attr( $date_to ) . '">
            <button type="submit" class="button">Filtrar</button>
        </form>';

        if ( 0 === $total ) {
            echo '<p>No hay pedidos completados' . ( $date_from || $date_to ? ' en el rango seleccionado' : '' ) . '.</p>';
            return;
        }

        // Showing X–Y of Z
        $offset = ( $paged - 1 ) * $per_page;
        $from   = $offset + 1;
        $to     = min( $offset + $per_page, $total );
        echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' pedidos.</p>';

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

        // Pagination links
        if ( $total > $per_page ) {
            $pagination = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => ceil( $total / $per_page ),
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) );
            if ( $pagination ) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;">' . $pagination . '</div></div>';
            }
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
                <h3 style="margin-top:0;">Items del Pedido</h3>
                <table class="widefat striped" id="wbi-items-table">
                    <thead>
                        <tr>
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
                '📦 Estado de Armado — WBI',
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
    // Armador Panel — simplified view for wbi_armador role
    // =========================================================================

    public function render_armador_panel() {
        $user = wp_get_current_user();
        $is_armador  = in_array( 'wbi_armador', (array) $user->roles, true );
        $is_manager  = current_user_can( 'manage_woocommerce' );

        if ( ! $is_armador && ! $this->user_has_picking_access() ) {
            wp_die( esc_html__( 'No tenés permisos para acceder al Panel de Armado.', 'wbi-suite' ) );
        }

        $orders = wc_get_orders( array(
            'status'   => array( 'processing', 'on-hold' ),
            'limit'    => 50,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ) );

        // Filter out fully-picked orders
        $display_orders = array();
        foreach ( $orders as $order ) {
            $picking_status = $order->get_meta( '_wbi_picking_status' );
            if ( 'picked' !== $picking_status ) {
                $display_orders[] = $order;
            }
        }
        ?>
        <div class="wrap">
            <h1>Panel de Armado</h1>
            <p>Pedidos pendientes de armado. Solo se muestran los datos necesarios para preparar los pedidos.</p>

            <?php if ( empty( $display_orders ) ) : ?>
                <div class="notice notice-success inline"><p>No hay pedidos pendientes de armado.</p></div>
            <?php else : ?>
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
                    <?php foreach ( $display_orders as $order ) :
                        $order_id       = $order->get_id();
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
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

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
}
