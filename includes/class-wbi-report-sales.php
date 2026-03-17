<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Sales {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        // CORRECCIÓN: Prioridad 100 para asegurar que el padre existe
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register() {
        add_submenu_page( 
            'wbi-dashboard-view', 
            'Detalle Ventas', 
            '<span class="dashicons dashicons-chart-bar" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Análisis Ventas', 
            'manage_options', 
            'wbi-sales-report', 
            array( $this, 'render' ) 
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-sales-report' ) === false ) return;
        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
    }

    public function render() {
        $tab      = isset($_GET['tab'])      ? sanitize_text_field($_GET['tab'])      : 'period';
        $start    = isset($_GET['start'])    ? sanitize_text_field($_GET['start'])    : date('Y-m-01'); 
        $end      = isset($_GET['end'])      ? sanitize_text_field($_GET['end'])      : date('Y-m-d');
        $default_statuses = array('wc-completed', 'wc-processing');
        $statuses = isset($_GET['statuses']) ? array_map('sanitize_text_field', (array)$_GET['statuses']) : $default_statuses;

        $all_statuses = array(
            'wc-completed'  => '✅ Completado',
            'wc-processing' => '🔄 En proceso',
            'wc-on-hold'    => '⏸ En espera',
            'wc-pending'    => '⏳ Pendiente',
        );

        // Build statuses query string for export URLs
        $statuses_qs = implode('', array_map(function($s){ return '&statuses[]=' . rawurlencode($s); }, $statuses));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📊 Análisis Profundo de Ventas</h1>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-sales-report&tab=period" class="nav-tab <?php echo $tab=='period'?'nav-tab-active':'';?>">Por Tiempo</a>
                <a href="?page=wbi-sales-report&tab=source" class="nav-tab <?php echo $tab=='source'?'nav-tab-active':'';?>">Por Origen</a>
                <a href="?page=wbi-sales-report&tab=cat" class="nav-tab <?php echo $tab=='cat'?'nav-tab-active':'';?>">Por Categoría</a>
                <a href="?page=wbi-sales-report&tab=collection" class="nav-tab <?php echo $tab=='collection'?'nav-tab-active':'';?>">Por Colección</a>
                <a href="?page=wbi-sales-report&tab=province" class="nav-tab <?php echo $tab=='province'?'nav-tab-active':'';?>">Por Provincia</a>
            </nav>
            
            <div style="background:#fff; padding:15px; border:1px solid #c3c4c7; border-top:none; margin-bottom:20px;">
                <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="wbi-sales-report">
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    
                    <label>Desde:</label> 
                    <input type="date" name="start" value="<?php echo esc_attr($start); ?>"> 
                    
                    <label>Hasta:</label> 
                    <input type="date" name="end" value="<?php echo esc_attr($end); ?>">

                    <label style="font-weight:600;">Estados:</label>
                    <select name="statuses[]" multiple size="4" style="height:72px; min-width:140px;" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                        <?php foreach ( $all_statuses as $val => $label ) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php echo in_array($val, $statuses, true) ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button class="button button-primary">Filtrar Resultados</button>

                    <?php if ( $tab === 'period' ) : ?>
                        <a href="<?php echo esc_url(admin_url("admin-post.php?action=wbi_export_dynamic&report_type=sales_period&start={$start}&end={$end}{$statuses_qs}")); ?>" class="button">📥 Exportar CSV</a>
                    <?php elseif ( $tab === 'province' ) : ?>
                        <?php
                        $province_export = isset($_GET['province']) ? sanitize_text_field($_GET['province']) : '';
                        if ( $province_export !== '' ) {
                            $province_export_url = esc_url( admin_url( "admin-post.php?action=wbi_export_dynamic&report_type=sales_province_detail&start={$start}&end={$end}{$statuses_qs}&province=" . rawurlencode( $province_export ) ) );
                        } else {
                            $province_export_url = esc_url( admin_url( "admin-post.php?action=wbi_export_dynamic&report_type=sales_province&start={$start}&end={$end}{$statuses_qs}" ) );
                        }
                        ?>
                        <a href="<?php echo $province_export_url; ?>" class="button">📥 Exportar CSV</a>
                    <?php endif; ?>
                </form>
            </div>

            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
                <?php 
                if ( $tab === 'period' ) {
                    $data = $this->engine->get_sales_by_period('day', $start, $end, $statuses);
                    if ( $data ) {
                        $labels = wp_json_encode( array_map( function($r){ return $r->period; }, $data ) );
                        $totals = wp_json_encode( array_map( function($r){ return (float)$r->total; }, $data ) );
                        echo '<canvas id="wbiSalesPeriodChart" style="max-height:280px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiSalesPeriodChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $labels . ', datasets:[{label:"Facturación",data:' . $totals . ',backgroundColor:"rgba(34,113,177,0.7)",borderColor:"#2271b1",borderWidth:1}]}, options:{responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
                        })();
                        </script>';
                    }

                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Fecha</th><th>Cant. Pedidos</th><th>Total Facturado</th></tr></thead><tbody>';
                    if ( $data ) foreach($data as $r) echo "<tr><td>" . esc_html($r->period) . "</td><td>" . intval($r->orders) . "</td><td><strong>" . wc_price($r->total) . "</strong></td></tr>";
                    else echo '<tr><td colspan="3">Sin datos en este periodo.</td></tr>';
                    echo '</tbody></table>';
                    echo '</div>';
                    
                } elseif ( $tab === 'source' ) {
                    $data = $this->engine->get_sales_by_source($start, $end, $statuses);
                    if ( $data ) {
                        $src_labels = wp_json_encode( array_map( function($r){ return $r->source ?: 'Web/Directo'; }, $data ) );
                        $src_totals = wp_json_encode( array_map( function($r){ return (float)$r->total; }, $data ) );
                        echo '<canvas id="wbiSourceChart" style="max-height:260px; max-width:400px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiSourceChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"pie", data:{labels:' . $src_labels . ', datasets:[{data:' . $src_totals . ',backgroundColor:["#2271b1","#00a32a","#dba617","#d63638","#8c3130","#50575e"]}]}, options:{responsive:true, maintainAspectRatio:true, plugins:{legend:{position:"right"}}}});
                        })();
                        </script>';
                    }
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Origen</th><th>Cant. Pedidos</th><th>Total Facturado</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>" . esc_html(ucfirst($r->source?:'Web/Directo')) . "</td><td>" . intval($r->count) . "</td><td><strong>" . wc_price($r->total) . "</strong></td></tr>";
                    else echo "<tr><td colspan=3>No se ha definido origen para las ventas de este periodo.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                    
                } elseif ( $tab === 'cat' ) {
                    $data = $this->engine->get_sales_by_taxonomy('product_cat', $start, $end, $statuses);
                    if ( $data ) {
                        $cat_labels = wp_json_encode( array_map( function($r){ return $r->name; }, $data ) );
                        $cat_totals = wp_json_encode( array_map( function($r){ return (float)$r->total; }, $data ) );
                        echo '<canvas id="wbiCatChart" style="max-height:260px; max-width:400px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiCatChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"pie", data:{labels:' . $cat_labels . ', datasets:[{data:' . $cat_totals . ',backgroundColor:["#2271b1","#00a32a","#dba617","#d63638","#8c3130","#50575e","#72aee6","#68de7c"]}]}, options:{responsive:true, maintainAspectRatio:true, plugins:{legend:{position:"right"}}}});
                        })();
                        </script>';
                    }
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Categoría</th><th>Unidades Vendidas</th><th>Total Generado ($)</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>" . esc_html($r->name) . "</td><td>" . intval($r->qty) . "</td><td><strong>" . wc_price($r->total) . "</strong></td></tr>";
                    else echo "<tr><td colspan=3>Sin datos.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                    
                } elseif ( $tab === 'collection' ) {
                    $data = $this->engine->get_sales_by_taxonomy('coleccion', $start, $end, $statuses);
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Colección</th><th>Unidades Vendidas</th><th>Total Generado ($)</th></tr></thead><tbody>';
                    if($data) foreach($data as $r) echo "<tr><td>" . esc_html($r->name) . "</td><td>" . intval($r->qty) . "</td><td><strong>" . wc_price($r->total) . "</strong></td></tr>";
                    else echo "<tr><td colspan=3>No se encontraron ventas asociadas a colecciones en este periodo (o la taxonomía 'coleccion' no existe).</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';

                } elseif ( $tab === 'province' ) {
                    $province = isset($_GET['province']) ? sanitize_text_field($_GET['province']) : '';
                    if ( $province !== '' ) {
                        // Detail view: show orders for the selected province
                        $back_url = esc_url( add_query_arg(
                            array_merge(
                                array( 'page' => 'wbi-sales-report', 'tab' => 'province', 'start' => $start, 'end' => $end ),
                                array( 'statuses' => $statuses )
                            ),
                            admin_url( 'admin.php' )
                        ) );
                        echo '<p><a href="' . $back_url . '">← Volver al resumen por provincia</a></p>';
                        $orders = $this->engine->get_orders_by_province( $province, $start, $end, $statuses );
                        $count = $orders ? count( $orders ) : 0;
                        $prov_name = WBI_Metrics_Engine::get_province_name( $province ) ?: esc_html( $province );
                        echo '<h3>📋 Pedidos en: ' . esc_html( $prov_name ) . ' (' . intval( $count ) . ')</h3>';
                        $status_labels = array(
                            'wc-completed'  => '✅ Completado',
                            'wc-processing' => '🔄 En proceso',
                            'wc-on-hold'    => '⏸ En espera',
                            'wc-pending'    => '⏳ Pendiente',
                            'wc-cancelled'  => '❌ Cancelado',
                            'wc-failed'     => '🚫 Fallido',
                            'wc-refunded'   => '↩ Reembolsado',
                        );
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="widefat striped wbi-sortable"><thead><tr><th>#Pedido</th><th>Fecha</th><th>Cliente</th><th>Email</th><th>Total</th><th>Estado</th></tr></thead><tbody>';
                        if ( $orders ) {
                            foreach ( $orders as $o ) {
                                $order_edit_url = esc_url( admin_url( 'post.php?post=' . intval( $o->order_id ) . '&action=edit' ) );
                                $status_label = isset( $status_labels[ $o->post_status ] ) ? $status_labels[ $o->post_status ] : esc_html( $o->post_status );
                                $customer_name = trim( $o->first_name . ' ' . $o->last_name );
                                echo '<tr>';
                                echo '<td><a href="' . $order_edit_url . '">#' . intval( $o->order_id ) . '</a></td>';
                                echo '<td>' . esc_html( date( 'd/m/Y', strtotime( $o->post_date ) ) ) . '</td>';
                                echo '<td>' . esc_html( $customer_name ) . '</td>';
                                echo '<td>' . esc_html( $o->email ) . '</td>';
                                echo '<td><strong>' . wc_price( $o->total ) . '</strong></td>';
                                echo '<td>' . esc_html( $status_label ) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="6">Sin datos de pedidos para esta provincia.</td></tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    } else {
                        // Summary view: show chart + table with clickable order counts
                        $data = $this->engine->get_sales_by_province($start, $end, $statuses);
                        if ( $data ) {
                            $prov_labels = wp_json_encode( array_map( function($r){
                                return WBI_Metrics_Engine::get_province_name( $r->province ?: '' ) ?: 'Desconocida';
                            }, $data ) );
                            $prov_totals = wp_json_encode( array_map( function($r){ return (float)$r->total; }, $data ) );
                            echo '<canvas id="wbiProvinceChart" style="max-height:320px; margin-bottom:20px;"></canvas>';
                            echo '<script>
                            (function(){
                                var ctx = document.getElementById("wbiProvinceChart");
                                if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $prov_labels . ', datasets:[{label:"Facturación",data:' . $prov_totals . ',backgroundColor:"rgba(34,113,177,0.7)",borderColor:"#2271b1",borderWidth:1}]}, options:{indexAxis:"y", responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}}});
                            })();
                            </script>';
                        }
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Provincia</th><th>Cant. Pedidos</th><th>Total Facturado</th></tr></thead><tbody>';
                        if($data) foreach($data as $r) {
                            $prov_name = WBI_Metrics_Engine::get_province_name( $r->province ?: '' ) ?: 'Desconocida';
                            if ( $r->province ) {
                                $prov_url = esc_url( add_query_arg(
                                    array_merge(
                                        array( 'page' => 'wbi-sales-report', 'tab' => 'province', 'province' => $r->province, 'start' => $start, 'end' => $end ),
                                        array( 'statuses' => $statuses )
                                    ),
                                    admin_url( 'admin.php' )
                                ) );
                                echo '<tr><td>' . esc_html( $prov_name ) . '</td><td><a href="' . $prov_url . '"><strong>' . intval( $r->orders ) . '</strong></a></td><td><strong>' . wc_price( $r->total ) . '</strong></td></tr>';
                            } else {
                                echo '<tr><td>' . esc_html( $prov_name ) . '</td><td>' . intval( $r->orders ) . '</td><td><strong>' . wc_price( $r->total ) . '</strong></td></tr>';
                            }
                        } else echo "<tr><td colspan=3>Sin datos de provincia en este periodo.</td></tr>";
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }
}