<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Sales {

    private $engine;

    public function __construct() {
        $this->engine = new WBI_Metrics_Engine();
        // CORRECCIÓN: Prioridad 100 para asegurar que el padre existe
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
    }

    public function register() {
        add_submenu_page( 
            'wbi-dashboard-view', 
            'Detalle Ventas', 
            'Análisis Ventas', 
            'manage_options', 
            'wbi-sales-report', 
            array( $this, 'render' ) 
        );
    }

    public function render() {
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'period';
        // Fechas por defecto: Mes actual
        $start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01'); 
        $end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📊 Análisis Profundo de Ventas</h1>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-sales-report&tab=period" class="nav-tab <?php echo $tab=='period'?'nav-tab-active':'';?>">Por Tiempo</a>
                <a href="?page=wbi-sales-report&tab=source" class="nav-tab <?php echo $tab=='source'?'nav-tab-active':'';?>">Por Origen</a>
                <a href="?page=wbi-sales-report&tab=cat" class="nav-tab <?php echo $tab=='cat'?'nav-tab-active':'';?>">Por Categoría</a>
                <a href="?page=wbi-sales-report&tab=collection" class="nav-tab <?php echo $tab=='collection'?'nav-tab-active':'';?>">Por Colección</a>
            </nav>
            
            <div style="background:#fff; padding:15px; border:1px solid #c3c4c7; border-top:none; margin-bottom:20px;">
                <form method="get" style="display:flex; align-items:center; gap:10px;">
                    <input type="hidden" name="page" value="wbi-sales-report">
                    <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                    
                    <label>Desde:</label> 
                    <input type="date" name="start" value="<?php echo $start; ?>"> 
                    
                    <label>Hasta:</label> 
                    <input type="date" name="end" value="<?php echo $end; ?>">
                    
                    <button class="button button-primary">Filtrar Resultados</button>
                </form>
            </div>

            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
                <?php 
                if($tab=='period') {
                    $data = $this->engine->get_sales_by_period('day', $start, $end);
                    if ($data) {
                        echo '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Cant. Pedidos</th><th>Total Facturado</th></tr></thead><tbody>';
                        foreach($data as $r) echo "<tr><td>{$r->period}</td><td>{$r->orders}</td><td><strong>".wc_price($r->total)."</strong></td></tr>";
                        echo '</tbody></table>';
                    } else { echo '<p>Sin datos en este periodo.</p>'; }
                    
                } elseif($tab=='source') {
                    $data = $this->engine->get_sales_by_source($start, $end);
                    echo '<table class="widefat striped"><thead><tr><th>Origen</th><th>Cant. Pedidos</th><th>Total Facturado</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>".ucfirst($r->source?:'Web/Directo')."</td><td>{$r->count}</td><td><strong>".wc_price($r->total)."</strong></td></tr>";
                    else echo "<tr><td colspan=3>No se ha definido origen para las ventas de este periodo.</td></tr>";
                    echo '</tbody></table>';
                    
                } elseif($tab=='cat') {
                    $data = $this->engine->get_sales_by_taxonomy('product_cat', $start, $end);
                    echo '<table class="widefat striped"><thead><tr><th>Categoría</th><th>Unidades Vendidas</th><th>Total Generado ($)</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>{$r->name}</td><td>{$r->qty}</td><td><strong>".wc_price($r->total)."</strong></td></tr>";
                    else echo "<tr><td colspan=3>Sin datos.</td></tr>";
                    echo '</tbody></table>';
                    
                } elseif($tab=='collection') {
                    // Nota: Asegúrate de que el slug de tu taxonomía sea 'coleccion' o cámbialo aquí
                    $data = $this->engine->get_sales_by_taxonomy('coleccion', $start, $end);
                    echo '<table class="widefat striped"><thead><tr><th>Colección</th><th>Unidades Vendidas</th><th>Total Generado ($)</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>{$r->name}</td><td>{$r->qty}</td><td><strong>".wc_price($r->total)."</strong></td></tr>";
                    else echo "<tr><td colspan=3>No se encontraron ventas asociadas a colecciones en este periodo (o la taxonomía 'coleccion' no existe).</td></tr>";
                    echo '</tbody></table>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}