<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Costs_Module {

    public function __construct() {
        // Product meta field — simple products
        add_action( 'woocommerce_product_options_pricing', array( $this, 'add_cost_field_simple' ) );
        add_action( 'woocommerce_process_product_meta',    array( $this, 'save_cost_field_simple' ) );

        // Product meta field — variations
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_cost_field_variation' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation',             array( $this, 'save_cost_field_variation' ), 10, 2 );

        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ) );

        // Dashboard KPI integration
        add_action( 'wbi_dashboard_after_kpis', array( $this, 'dashboard_avg_margin_card' ) );

        // Invalidate avg-margin cache when a product is saved
        add_action( 'save_post_product', array( $this, 'invalidate_margin_cache' ) );
    }

    // -------------------------------------------------------------------------
    // Product field — simple product pricing tab
    // -------------------------------------------------------------------------

    public function add_cost_field_simple() {
        woocommerce_wp_text_input( array(
            'id'          => '_wbi_cost_price',
            'label'       => __( 'Costo (WBI)', 'wbi-suite' ) . ' (' . get_woocommerce_currency_symbol() . ')',
            'placeholder' => '0.00',
            'data_type'   => 'price',
            'description' => __( 'Costo de adquisición/producción para cálculo de margen.', 'wbi-suite' ),
            'desc_tip'    => true,
        ) );
    }

    public function save_cost_field_simple( $post_id ) {
        if ( ! current_user_can( 'edit_products' ) ) return;
        if ( isset( $_POST['_wbi_cost_price'] ) ) {
            $cost = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_wbi_cost_price'] ) ) );
            update_post_meta( $post_id, '_wbi_cost_price', $cost );
        }
    }

    // -------------------------------------------------------------------------
    // Product field — variation
    // -------------------------------------------------------------------------

    public function add_cost_field_variation( $loop, $variation_data, $variation ) {
        woocommerce_wp_text_input( array(
            'id'            => '_wbi_cost_price[' . $variation->ID . ']',
            'name'          => '_wbi_cost_price[' . $variation->ID . ']',
            'value'         => get_post_meta( $variation->ID, '_wbi_cost_price', true ),
            'label'         => __( 'Costo (WBI)', 'wbi-suite' ) . ' (' . get_woocommerce_currency_symbol() . ')',
            'placeholder'   => '0.00',
            'data_type'     => 'price',
            'wrapper_class' => 'form-row form-row-first',
        ) );
    }

    public function save_cost_field_variation( $variation_id, $i ) {
        if ( ! current_user_can( 'edit_products' ) ) return;
        if ( isset( $_POST['_wbi_cost_price'][ $variation_id ] ) ) {
            $cost = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_wbi_cost_price'][ $variation_id ] ) ) );
            update_post_meta( $variation_id, '_wbi_cost_price', $cost );
        }
    }

    // -------------------------------------------------------------------------
    // Margin calculation helper
    // -------------------------------------------------------------------------

    public static function calc_margin( $price, $cost ) {
        if ( $cost <= 0 || $price <= 0 ) return 0;
        return round( ( ( $price - $cost ) / $price ) * 100, 2 );
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Costos y Márgenes',
            '💰 Costos y Márgenes',
            'manage_woocommerce',
            'wbi-costs-report',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos' );
        }

        $engine      = WBI_Metrics_Engine::instance();
        $per_page    = 20;
        $paged       = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset      = ( $paged - 1 ) * $per_page;
        $category_id = isset( $_GET['category_id'] ) ? intval( $_GET['category_id'] ) : 0;
        $min_margin  = isset( $_GET['min_margin'] ) ? floatval( $_GET['min_margin'] ) : -999;
        $max_margin  = isset( $_GET['max_margin'] ) ? floatval( $_GET['max_margin'] ) : 999;

        $alert_threshold = floatval( get_option( 'wbi_margin_alert_threshold', 20 ) );

        $products    = $engine->get_products_with_costs( $per_page, $offset, $category_id, $min_margin, $max_margin );
        $total       = $engine->get_products_with_costs_count( $category_id, $min_margin, $max_margin );
        $total_pages = ceil( $total / $per_page );

        // Categories for dropdown
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

        $export_url = add_query_arg( array(
            'action'      => 'wbi_export_dynamic',
            'report_type' => 'costs_margins',
            '_wpnonce'    => wp_create_nonce( 'wbi_export' ),
        ), admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap">
            <h1>💰 Costos y Márgenes</h1>

            <!-- Margin alert threshold setting -->
            <div style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; padding:15px 20px; margin-bottom:20px; max-width:400px;">
                <form method="post" action="options.php" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <?php settings_fields( 'wbi_costs_group' ); ?>
                    <label><strong>⚠️ Umbral de Alerta de Margen (%):</strong></label>
                    <input type="number" name="wbi_margin_alert_threshold" value="<?php echo esc_attr( $alert_threshold ); ?>" min="0" max="100" step="0.1" style="width:80px;" />
                    <?php submit_button( 'Guardar', 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <!-- Filters -->
            <div class="wbi-filter-bar" style="background:#fff; padding:15px 20px; border:1px solid #c3c4c7; border-left:4px solid #2271b1; margin-bottom:20px;">
                <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="wbi-costs-report">
                    <label>Categoría:</label>
                    <select name="category_id">
                        <option value="0">— Todas —</option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $category_id, $cat->term_id ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Margen mín (%):</label>
                    <input type="number" name="min_margin" value="<?php echo esc_attr( $min_margin === -999 ? '' : $min_margin ); ?>" step="0.1" style="width:70px;">
                    <label>Margen máx (%):</label>
                    <input type="number" name="max_margin" value="<?php echo esc_attr( $max_margin === 999 ? '' : $max_margin ); ?>" step="0.1" style="width:70px;">
                    <button class="button button-primary">Filtrar</button>
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button">📥 Exportar CSV</a>
                </form>
            </div>

            <p style="color:#555;">Total de productos: <strong><?php echo intval( $total ); ?></strong></p>

            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Precio Venta</th>
                        <th>Costo</th>
                        <th>Margen %</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $products ) ) : ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;">No se encontraron productos con datos de costo.</td></tr>
                <?php else : ?>
                    <?php foreach ( $products as $row ) :
                        $margin = self::calc_margin( floatval( $row->price ), floatval( $row->cost ) );
                        if ( $margin < 0 ) {
                            $estado = '🔴 Negativo';
                            $color  = '#d63638';
                        } elseif ( $margin < $alert_threshold ) {
                            $estado = '🟡 Bajo';
                            $color  = '#dba617';
                        } else {
                            $estado = '🟢 OK';
                            $color  = '#00a32a';
                        }
                        $edit_url = get_edit_post_link( $row->ID );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row->post_title ); ?></a></td>
                        <td><?php echo esc_html( $row->sku ?: '—' ); ?></td>
                        <td><?php echo wc_price( $row->price ); ?></td>
                        <td><?php echo wc_price( $row->cost ); ?></td>
                        <td><?php echo esc_html( number_format( $margin, 2 ) ); ?>%</td>
                        <td style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;"><?php echo esc_html( $estado ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
            endif; ?>
        </div>
        <?php
        // Handle the alert threshold save manually since it's a direct option
        if ( isset( $_POST['wbi_margin_alert_threshold'] ) && check_admin_referer( 'wbi_costs_group-options' ) && current_user_can( 'manage_options' ) ) {
            update_option( 'wbi_margin_alert_threshold', floatval( sanitize_text_field( wp_unslash( $_POST['wbi_margin_alert_threshold'] ) ) ) );
        }
    }

    // -------------------------------------------------------------------------
    // Dashboard KPI card
    // -------------------------------------------------------------------------

    public function dashboard_avg_margin_card() {
        $avg = get_transient( 'wbi_avg_margin' );
        if ( false === $avg ) {
            $avg = WBI_Metrics_Engine::instance()->get_avg_margin();
            set_transient( 'wbi_avg_margin', $avg, 10 * MINUTE_IN_SECONDS );
        }
        ?>
        <div class="wbi-card green" style="background:#fff; border:1px solid #c3c4c7; border-top:3px solid #00a32a; border-radius:3px; padding:20px;">
            <div style="font-size:28px; font-weight:700; color:#00a32a;"><?php echo esc_html( number_format( floatval( $avg ), 1 ) ); ?>%</div>
            <div style="color:#555; margin-top:4px;">💰 Margen Promedio</div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    public function invalidate_margin_cache() {
        delete_transient( 'wbi_avg_margin' );
    }
}
