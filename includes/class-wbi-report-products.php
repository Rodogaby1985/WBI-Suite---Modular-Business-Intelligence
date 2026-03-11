<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Products {

    private $engine;

    public function __construct() {
        $this->engine = new WBI_Metrics_Engine();
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
    }

    public function register() {
        add_submenu_page( 'wbi-dashboard-view', 'Productos & Stock', 'Productos & Stock', 'manage_options', 'wbi-products-report', array( $this, 'render' ) );
    }

    public function render() {
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'stock';
        // Fechas solo para tabs de historial
        $start = isset($_GET['start']) ? $_GET['start'] : date('Y-01-01');
        $end   = isset($_GET['end'])   ? $_GET['end']   : date('Y-m-d');

        // Mapeo para saber qué reporte pedir al exportador
        $export_map = [
            'stock'     => 'stock_real',
            'committed' => 'stock_committed',
            'dormant'   => 'stock_dormant',
            'best'      => 'best_sellers',
            'worst'     => 'worst_sellers'
        ];
        $export_type = $export_map[$tab];

        // URL de exportación
        $export_url = admin_url("admin-post.php?action=wbi_export_dynamic&report_type={$export_type}&start={$start}&end={$end}");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📦 Productos & Stock</h1>
            <a href="<?php echo $export_url; ?>" class="page-title-action">📥 Exportar esta Tabla a CSV</a>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-products-report&tab=stock" class="nav-tab <?php echo $tab=='stock'?'nav-tab-active':'';?>">Stock Real</a>
                <a href="?page=wbi-products-report&tab=committed" class="nav-tab <?php echo $tab=='committed'?'nav-tab-active':'';?>">Stock Comprometido</a>
                <a href="?page=wbi-products-report&tab=dormant" class="nav-tab <?php echo $tab=='dormant'?'nav-tab-active':'';?>">Stock Dormido (+90d)</a>
                <a href="?page=wbi-products-report&tab=best" class="nav-tab <?php echo $tab=='best'?'nav-tab-active':'';?>">Más Vendidos</a>
                <a href="?page=wbi-products-report&tab=worst" class="nav-tab <?php echo $tab=='worst'?'nav-tab-active':'';?>">Menos Vendidos</a>
            </nav>

            <!-- FILTRO DE FECHAS: SOLO PARA MÁS/MENOS VENDIDOS -->
            <?php if( $tab == 'best' || $tab == 'worst' ): ?>
            <div style="background:#fff; padding:15px; border:1px solid #c3c4c7; border-top:none; margin-bottom:15px; display:flex; align-items:center; gap:10px;">
                <form method="get">
                    <input type="hidden" name="page" value="wbi-products-report">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    <strong>📅 Filtrar Periodo:</strong> 
                    Desde <input type="date" name="start" value="<?php echo $start; ?>"> 
                    Hasta <input type="date" name="end" value="<?php echo $end; ?>">
                    <button class="button button-primary">Actualizar</button>
                </form>
            </div>
            <?php endif; ?>

            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-top:10px;">
                <?php
                if($tab=='stock'){
                    $data = $this->engine->get_realtime_stock();
                    echo '<p><i>Inventario físico actual en sistema.</i></p>';
                    echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Stock Actual</th></tr></thead><tbody>';
                    foreach($data as $d) echo "<tr><td>{$d->post_title}</td><td><span class='badge'>{$d->stock}</span></td></tr>";
                    echo '</tbody></table>';
                } elseif($tab=='committed'){
                    $data = $this->engine->get_committed_stock();
                    echo '<p><i>Productos reservados en pedidos pendientes de envío.</i></p>';
                    echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Cant.</th><th>Pedido</th></tr></thead><tbody>';
                    foreach($data as $d) echo "<tr><td>{$d->name}</td><td>{$d->qty}</td><td><a href='post.php?post={$d->order_id}&action=edit'>#{$d->order_id}</a></td></tr>";
                    echo '</tbody></table>';
                } elseif($tab=='dormant'){
                    $data = $this->engine->get_dormant_stock();
                    echo '<p style="color:red;"><i>Productos con stock positivo sin movimiento en 90 días.</i></p>';
                    echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Stock Inmovilizado</th><th>Último Mov.</th></tr></thead><tbody>';
                    foreach($data as $d) echo "<tr><td>{$d->post_title}</td><td>{$d->stock}</td><td>".date('d/m/Y', strtotime($d->post_modified))."</td></tr>";
                    echo '</tbody></table>';
                } elseif($tab=='best'){
                    $data = $this->engine->get_best_sellers($start, $end);
                    echo "<p>Ranking del <b>{$start}</b> al <b>{$end}</b>.</p>";
                    echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Unidades Vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>{$d->name}</td><td><strong>{$d->qty}</strong></td></tr>";
                    else echo "<tr><td colspan=2>Sin ventas en este periodo.</td></tr>";
                    echo '</tbody></table>';
                } elseif($tab=='worst'){
                    $data = $this->engine->get_least_sold($start, $end);
                    echo "<p>Productos con menor salida del <b>{$start}</b> al <b>{$end}</b> (pero con al menos 1 venta).</p>";
                    echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Unidades Vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>{$d->name}</td><td>{$d->qty}</td></tr>";
                    else echo "<tr><td colspan=2>Sin datos.</td></tr>";
                    echo '</tbody></table>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}