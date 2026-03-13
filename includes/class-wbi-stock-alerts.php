<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Stock_Alerts {

    private $engine;

    public function __construct() {
        $this->engine = new WBI_Metrics_Engine();
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
        add_action( 'admin_notices', array( $this, 'show_stock_notice' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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

        $threshold = $this->get_threshold();
        $products  = $this->engine->get_low_stock_products( $threshold );

        if ( ! empty( $products ) ) {
            $count = count( $products );
            $url   = admin_url( 'admin.php?page=wbi-stock-alerts' );
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '⚠️ <strong>' . $count . ' producto(s)</strong> con stock bajo (≤' . $threshold . ' unidades). ';
            echo '<a href="' . esc_url( $url ) . '">Ver alertas de stock</a>';
            echo '</p></div>';
        }
    }

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            '⚠️ Alertas Stock',
            '⚠️ Alertas Stock',
            'manage_options',
            'wbi-stock-alerts',
            array( $this, 'render' )
        );
    }

    public function render() {
        $threshold = $this->get_threshold();
        $products  = $this->engine->get_low_stock_products( $threshold );
        ?>
        <div class="wrap">
            <h1>⚠️ Alertas de Stock Bajo</h1>
            <p>Productos con stock ≤ <strong><?php echo esc_html( $threshold ); ?></strong> unidades. Configure el umbral en <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-settings' ) ); ?>">WBI Config</a>.</p>

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
}
