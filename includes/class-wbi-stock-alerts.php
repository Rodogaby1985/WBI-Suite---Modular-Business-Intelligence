<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Stock_Alerts {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
        add_action( 'admin_notices', array( $this, 'show_stock_notice' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_wbi_stock_export', array( $this, 'handle_stock_export' ) );
    }

    public function register_settings() {
        add_settings_field(
            'wbi_stock_threshold',
            'Umbral Alerta de Stock (unidades)',
            array( $this, 'threshold_field' ),
            'wbi-settings',
            'wbi_main_section'
        );
    }

    public function threshold_field() {
        $opts = get_option( 'wbi_modules_settings' );
        $val  = isset( $opts['wbi_stock_threshold'] ) ? intval( $opts['wbi_stock_threshold'] ) : 5;
        echo '<input type="number" name="wbi_modules_settings[wbi_stock_threshold]" value="' . esc_attr( $val ) . '" min="0" style="width:80px;"> unidades';
    }

    public function get_threshold() {
        $opts = get_option( 'wbi_modules_settings' );
        return isset( $opts['wbi_stock_threshold'] ) ? intval( $opts['wbi_stock_threshold'] ) : 5;
    }

    public function show_stock_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $screen = get_current_screen();
        if ( ! $screen ) return;

        // Show on WooCommerce pages and WBI pages
        $is_wbi        = strpos( $screen->id, 'wbi' ) !== false;
        $is_woocommerce = strpos( $screen->id, 'woocommerce' ) !== false || strpos( $screen->parent_base, 'woocommerce' ) !== false;

        if ( ! $is_wbi && ! $is_woocommerce ) return;

        $threshold   = $this->get_threshold();
        $cache_key   = 'wbi_stock_notice_' . intval( $threshold );
        $count       = get_transient( $cache_key );

        if ( false === $count ) {
            $products = $this->engine->get_low_stock_products( $threshold );
            $count    = is_array( $products ) ? count( $products ) : 0;
            set_transient( $cache_key, $count, 600 ); // 10 minutes
        }

        if ( $count > 0 ) {
            $url = admin_url( 'admin.php?page=wbi-stock-alerts' );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '⚠️ <strong>' . intval( $count ) . ' producto(s)</strong> con stock bajo (≤' . intval( $threshold ) . ' unidades). ';
            echo '<a href="' . esc_url( $url ) . '">Ver alertas de stock</a>';
            echo '</p></div>';
        }
    }

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Alertas Stock',
            '<span class="dashicons dashicons-warning" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Alertas Stock',
            'manage_options',
            'wbi-stock-alerts',
            array( $this, 'render' )
        );
    }

    public function render() {
        $threshold = $this->get_threshold();
        $products  = $this->engine->get_low_stock_products( $threshold );

        $export_url = add_query_arg( array(
            'action'   => 'wbi_stock_export',
            '_wpnonce' => wp_create_nonce( 'wbi_stock_export' ),
        ), admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap">
            <h1>Alertas de Stock Bajo</h1>
            <p>Productos con stock ≤ <strong><?php echo esc_html( $threshold ); ?></strong> unidades. Configure el umbral en <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-settings' ) ); ?>">wooErp Config</a>.</p>

            <p><a href="<?php echo esc_url( $export_url ); ?>" class="button">Exportar CSV</a></p>

            <?php if ( empty( $products ) ) : ?>
                <div class="notice notice-success inline"><p>✅ No hay productos con stock bajo.</p></div>
            <?php else : ?>
                <table class="widefat striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Stock Actual</th>
                            <th>SKU</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $products as $p ) : ?>
                            <tr>
                                <td><?php echo esc_html( $p->post_title ); ?></td>
                                <td><strong style="color:#d63638;"><?php echo intval( $p->stock ); ?></strong></td>
                                <td><?php echo esc_html( $p->sku ?: '-' ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" class="button button-small">
                                        Editar Producto
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_stock_export() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_stock_export' ) ) wp_die( 'Nonce inválido' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos' );

        $threshold = $this->get_threshold();
        $products  = $this->engine->get_low_stock_products( $threshold );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wbi-stock-alerts-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'product_id', 'name', 'sku', 'stock', 'threshold' ) );
        foreach ( $products as $p ) {
            fputcsv( $out, array( $p->ID, $p->post_title, $p->sku ?: '', intval( $p->stock ), $threshold ) );
        }
        fclose( $out );
        exit;
    }
}
