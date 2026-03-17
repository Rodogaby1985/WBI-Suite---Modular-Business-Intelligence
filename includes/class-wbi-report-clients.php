<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Clients {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register() {
        add_submenu_page( 'wbi-dashboard-view', 'Detalle Clientes', '<span class="dashicons dashicons-groups" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Análisis Clientes', 'manage_options', 'wbi-clients-report', array( $this, 'render' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-clients-report' ) === false ) return;
        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
    }

    public function render() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'ranking';
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

        // Determinar tipo de exportación
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        if ( $tab === 'zones' && $city !== '' ) {
            $export_type = 'clients_zone_detail';
        } elseif ( $tab == 'active' ) {
            $export_type = 'clients_active';
        } else {
            $export_type = 'clients_ranking';
        }
        $statuses_qs = implode('', array_map(function($s){ return '&statuses[]=' . rawurlencode($s); }, $statuses));
        $export_url = admin_url("admin-post.php?action=wbi_export_dynamic&report_type={$export_type}&start={$start}&end={$end}{$statuses_qs}");
        if ( $tab === 'zones' && $city !== '' ) {
            $export_url .= '&city=' . rawurlencode( $city );
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">👥 Análisis Profundo de Clientes</h1>
            <a href="<?php echo $export_url; ?>" class="page-title-action">📥 Exportar CSV</a>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-clients-report&tab=ranking" class="nav-tab <?php echo $tab=='ranking'?'nav-tab-active':'';?>">Rankings de Facturación</a>
                <a href="?page=wbi-clients-report&tab=active" class="nav-tab <?php echo $tab=='active'?'nav-tab-active':'';?>">Clientes Activos</a>
                <a href="?page=wbi-clients-report&tab=zones" class="nav-tab <?php echo $tab=='zones'?'nav-tab-active':'';?>">Nuevos por Zona</a>
            </nav>

            <!-- FILTRO DE FECHAS: SOLO PARA RANKING -->
            <?php if( $tab == 'ranking' ): ?>
            <div style="background:#fff; padding:15px; border:1px solid #c3c4c7; border-top:none; margin-bottom:15px;">
                <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="wbi-clients-report">
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    <strong>📅 Analizar Ranking del:</strong> 
                    <input type="date" name="start" value="<?php echo esc_attr($start); ?>"> 
                    al <input type="date" name="end" value="<?php echo esc_attr($end); ?>">

                    <strong style="margin-left:8px;">Estados:</strong>
                    <select name="statuses[]" multiple size="4" style="height:72px; min-width:140px;" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                        <?php foreach ( $all_statuses as $val => $label ) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php echo in_array($val, $statuses, true) ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">Filtrar</button>
                </form>
            </div>
            <?php endif; ?>

            <div style="background:#fff; padding:20px; margin-top:10px; border:1px solid #c3c4c7;">
                <?php
                if($tab=='ranking'){
                    $top = $this->engine->get_clients_ranking('revenue', $start, $end, $statuses);
                    echo "<h3>🏆 Top Clientes ({$start} al {$end})</h3>";

                    if ( $top ) {
                        $chart_top = array_slice( $top, 0, 10 );
                        $c_labels  = wp_json_encode( array_map( function( $c ) { return $c->display_name; }, $chart_top ) );
                        $c_data    = wp_json_encode( array_map( function( $c ) { return (float) $c->total_val; }, $chart_top ) );
                        echo '<canvas id="wbiClientsChart" style="max-height:300px; margin-bottom:20px;"></canvas>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiClientsChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"doughnut", data:{labels:' . $c_labels . ', datasets:[{data:' . $c_data . ', backgroundColor:["#2271b1","#00a32a","#dba617","#d63638","#8c3130","#72aee6","#68de7c","#ffb900","#50575e","#a16696"], borderWidth:2, borderColor:"#fff"}]}, options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:"right"}}}});
                        })();
                        </script>';
                    }

                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th>Total Gastado</th><th>Cant. Pedidos</th></tr></thead><tbody>';
                    if($top) foreach($top as $c) echo "<tr><td><strong>" . esc_html($c->display_name) . "</strong></td><td>" . esc_html($c->user_email) . "</td><td>".wc_price($c->total_val)."</td><td>" . intval($c->count_val) . "</td></tr>";
                    else echo "<tr><td colspan=4>No hay datos.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                    
                } elseif($tab=='active'){
                    $active = $this->engine->get_active_customers_list();
                    echo '<h3>✅ Clientes Activos (Últimos 60 días)</h3>';
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th>Última Compra</th></tr></thead><tbody>';
                    if($active) foreach($active as $a) echo "<tr><td>" . esc_html($a->display_name) . "</td><td>" . esc_html($a->user_email) . "</td><td>".date('d/m/Y', strtotime($a->last_buy))."</td></tr>";
                    else echo "<tr><td colspan=3>Sin actividad.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='zones'){
                    if ( $city !== '' ) {
                        // Detail view: show users for the selected city
                        $back_url = esc_url( add_query_arg(
                            array( 'page' => 'wbi-clients-report', 'tab' => 'zones' ),
                            admin_url( 'admin.php' )
                        ) );
                        echo '<p><a href="' . $back_url . '">← Volver al listado de zonas</a></p>';
                        $customers = $this->engine->get_customers_by_city( $city );
                        $count = $customers ? count( $customers ) : 0;
                        echo '<h3>👥 Detalle de usuarios en: ' . esc_html( $city ) . ' (' . intval( $count ) . ')</h3>';
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="widefat striped wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th>Fecha de Registro</th><th>Ciudad</th></tr></thead><tbody>';
                        if ( $customers ) {
                            foreach ( $customers as $u ) {
                                echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( date( 'd/m/Y', strtotime( $u->user_registered ) ) ) . '</td><td>' . esc_html( $u->city ) . '</td></tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4">Sin datos.</td></tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    } else {
                        // Summary view: show zones table with clickable counts
                        $zones = $this->engine->get_new_customers_zones();
                        echo '<h3>🗺️ Nuevos registros (Últimos 60 días)</h3>';
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="widefat striped wbi-sortable" style="max-width:500px;"><thead><tr><th>Ciudad</th><th>Nuevos Registros</th></tr></thead><tbody>';
                        if ( $zones ) {
                            foreach ( $zones as $z ) {
                                if ( $z->city ) {
                                    $zone_url = esc_url( add_query_arg(
                                        array( 'page' => 'wbi-clients-report', 'tab' => 'zones', 'city' => $z->city ),
                                        admin_url( 'admin.php' )
                                    ) );
                                    echo '<tr><td>' . esc_html( $z->city ) . '</td><td><a href="' . $zone_url . '"><strong>' . intval( $z->count ) . '</strong></a></td></tr>';
                                } else {
                                    echo '<tr><td>Desconocido</td><td>' . intval( $z->count ) . '</td></tr>';
                                }
                            }
                        } else {
                            echo '<tr><td colspan="2">Sin datos.</td></tr>';
                        }
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