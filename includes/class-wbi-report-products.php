<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Products {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register() {
        add_submenu_page( 'wbi-dashboard-view', 'Productos & Stock', '<span class="dashicons dashicons-archive" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Productos & Stock', 'manage_options', 'wbi-products-report', array( $this, 'render' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-products-report' ) === false ) return;
        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
    }

    public function render() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'stock';
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-01-01');
        $end   = isset($_GET['end'])   ? sanitize_text_field($_GET['end'])   : date('Y-m-d');
        $default_statuses = array('wc-completed', 'wc-processing');
        $statuses = isset($_GET['statuses']) ? array_map('sanitize_text_field', (array)$_GET['statuses']) : $default_statuses;

        $all_statuses = array(
            'wc-completed'  => '✅ Completado',
            'wc-processing' => '🔄 En proceso',
            'wc-on-hold'    => '⏸ En espera',
            'wc-pending'    => '⏳ Pendiente',
        );

        // Mapeo para saber qué reporte pedir al exportador
        $export_map = [
            'stock'     => 'stock_real',
            'committed' => 'stock_committed',
            'dormant'   => 'stock_dormant',
            'best'      => 'best_sellers',
            'worst'     => 'worst_sellers'
        ];
        $export_type = $export_map[$tab] ?? 'stock_real';
        $statuses_qs = implode('', array_map(function($s){ return '&statuses[]=' . rawurlencode($s); }, $statuses));
        $export_url = admin_url("admin-post.php?action=wbi_export_dynamic&report_type={$export_type}&start={$start}&end={$end}{$statuses_qs}");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📦 Productos & Stock</h1>
            <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">📥 Exportar esta Tabla a CSV</a>
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
            <div style="background:#fff; padding:15px; border:1px solid #c3c4c7; border-top:none; margin-bottom:15px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <form method="get">
                    <input type="hidden" name="page" value="wbi-products-report">
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    <strong>📅 Filtrar Periodo:</strong> 
                    Desde <input type="date" name="start" value="<?php echo esc_attr($start); ?>"> 
                    Hasta <input type="date" name="end" value="<?php echo esc_attr($end); ?>">

                    <strong style="margin-left:8px;">Estados:</strong>
                    <select name="statuses[]" multiple size="4" style="height:72px; min-width:140px;" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                        <?php foreach ( $all_statuses as $val => $label ) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php echo in_array($val, $statuses, true) ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">Actualizar</button>
                </form>
            </div>
            <?php endif; ?>

            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-top:10px;">
                <?php
                if($tab=='stock'){
                    $data = $this->engine->get_realtime_stock();
                    echo '<p><i>Inventario físico actual en sistema.</i></p>';
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Producto</th><th>Stock Actual</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->post_title) . "</td><td><span class='badge'>" . intval($d->stock) . "</span></td></tr>";
                    } else {
                        echo '<tr><td colspan="2">No hay productos con stock registrado.</td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif($tab=='committed'){
                    $data = $this->engine->get_committed_stock();
                    echo '<p><i>Productos reservados en pedidos pendientes de envío.</i></p>';
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Producto</th><th>Cant.</th><th>Pedido</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td>" . intval($d->qty) . "</td><td><a href='post.php?post=" . intval($d->order_id) . "&action=edit'>#" . intval($d->order_id) . "</a></td></tr>";
                    } else {
                        echo '<tr><td colspan="3">No hay stock comprometido actualmente.</td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif($tab=='dormant'){
                    $data = $this->engine->get_dormant_stock();
                    echo '<p style="color:red;"><i>Productos con stock positivo sin movimiento en 90 días.</i></p>';
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Producto</th><th>Stock Inmovilizado</th><th>Último Mov.</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->post_title) . "</td><td>" . intval($d->stock) . "</td><td>" . date('d/m/Y', strtotime($d->post_modified)) . "</td></tr>";
                    } else {
                        echo '<tr><td colspan="3">No hay productos con stock dormido.</td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif($tab=='best'){
                    $data = $this->engine->get_best_sellers($start, $end, $statuses);
                    echo "<p>Ranking del <b>" . esc_html($start) . "</b> al <b>" . esc_html($end) . "</b>.</p>";

                    if ( $data ) {
                        $prod_names = wp_json_encode( array_map( function($r){ return $r->name; }, $data ) );
                        $prod_qtys  = wp_json_encode( array_map( function($r){ return intval($r->qty); }, $data ) );
                        echo '<canvas id="wbiBestChart" style="max-height:320px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiBestChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $prod_names . ', datasets:[{label:"Unidades",data:' . $prod_qtys . ',backgroundColor:"rgba(0,163,42,0.7)",borderColor:"#00a32a",borderWidth:1}]}, options:{indexAxis:"y", responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}}});
                        })();
                        </script>';
                    }

                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Producto</th><th>Unidades Vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td><strong>" . intval($d->qty) . "</strong></td></tr>";
                    else echo "<tr><td colspan=2>Sin ventas en este periodo.</td></tr>";
                    echo '</tbody></table>';
                } elseif($tab=='worst'){
                    $data = $this->engine->get_least_sold($start, $end, $statuses);
                    echo "<p>Productos con menor salida del <b>" . esc_html($start) . "</b> al <b>" . esc_html($end) . "</b> (pero con al menos 1 venta).</p>";

                    if ( $data ) {
                        $prod_names = wp_json_encode( array_map( function($r){ return $r->name; }, $data ) );
                        $prod_qtys  = wp_json_encode( array_map( function($r){ return intval($r->qty); }, $data ) );
                        echo '<canvas id="wbiWorstChart" style="max-height:320px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiWorstChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $prod_names . ', datasets:[{label:"Unidades",data:' . $prod_qtys . ',backgroundColor:"rgba(214,54,56,0.7)",borderColor:"#d63638",borderWidth:1}]}, options:{indexAxis:"y", responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}}});
                        })();
                        </script>';
                    }

                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Producto</th><th>Unidades Vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td>" . intval($d->qty) . "</td></tr>";
                    else echo "<tr><td colspan=2>Sin datos.</td></tr>";
                    echo '</tbody></table>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}