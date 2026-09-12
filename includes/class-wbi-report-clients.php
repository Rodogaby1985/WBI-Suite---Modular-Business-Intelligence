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
        $tab = WBI_Admin_Query_Helper::get_enum( $_GET, 'tab', array( 'ranking', 'active', 'zones' ), 'ranking' );
        $date_range = WBI_Admin_Query_Helper::normalize_date_range_with_meta(
            $_GET,
            'start',
            'end',
            WBI_Admin_Query_Helper::get_site_date_ymd( 'first day of january' ),
            WBI_Admin_Query_Helper::get_site_date_ymd()
        );
        $start = $date_range['from'];
        $end   = $date_range['to'];
        $default_statuses = array('wc-completed', 'wc-processing');
        $statuses = WBI_Admin_Query_Helper::get_string_array( $_GET, 'statuses', array_keys( array(
            'wc-completed' => true,
            'wc-processing' => true,
            'wc-on-hold' => true,
            'wc-pending' => true,
        ) ) );
        if ( empty( $statuses ) ) {
            $statuses = $default_statuses;
        }

        $all_statuses = array(
            'wc-completed'  => '✅ Completado',
            'wc-processing' => '🔄 En proceso',
            'wc-on-hold'    => '⏸ En espera',
            'wc-pending'    => '⏳ Pendiente',
        );

        // Determinar tipo de exportación
        $city = WBI_Admin_Query_Helper::get_string( $_GET, 'city', '' );
        if ( $tab === 'zones' && $city !== '' ) {
            $export_type = 'clients_zone_detail';
        } elseif ( $tab == 'active' ) {
            $export_type = 'clients_active';
        } else {
            $export_type = 'clients_ranking';
        }
        $export_url = WBI_Admin_Query_Helper::build_url(
            admin_url( 'admin-post.php' ),
            array(
                'action'      => 'wbi_export_dynamic',
                'report_type' => $export_type,
                'start'       => $start,
                'end'         => $end,
                'statuses'    => $statuses,
                'city'        => ( $tab === 'zones' && $city !== '' ) ? $city : null,
                '_wpnonce'    => wp_create_nonce( 'wbi_export_dynamic' ),
            )
        );
        $reset_url = admin_url( 'admin.php?page=wbi-clients-report' );
        $tabs      = array(
            'ranking' => array( 'label' => 'Rankings de facturación', 'url' => add_query_arg( array( 'page' => 'wbi-clients-report', 'tab' => 'ranking' ), admin_url( 'admin.php' ) ) ),
            'active'  => array( 'label' => 'Clientes activos', 'url' => add_query_arg( array( 'page' => 'wbi-clients-report', 'tab' => 'active' ), admin_url( 'admin.php' ) ) ),
            'zones'   => array( 'label' => 'Nuevos por zona', 'url' => add_query_arg( array( 'page' => 'wbi-clients-report', 'tab' => 'zones' ), admin_url( 'admin.php' ) ) ),
        );
        $back_link = array();

        if ( 'zones' === $tab && '' !== $city ) {
            $back_link = array(
                'url'   => add_query_arg(
                    array( 'page' => 'wbi-clients-report', 'tab' => 'zones' ),
                    admin_url( 'admin.php' )
                ),
                'label' => 'Volver al listado de zonas',
            );
        }

        ?>
        <?php WBI_Admin_Shell::open_page(); ?>
            <?php if ( $date_range['has_error'] ) : ?>
                <?php WBI_Admin_Shell::render_notice( esc_html__( 'El rango de fechas enviado no es válido o estaba invertido. Se aplicó el rango por defecto.', 'wbi-suite' ), 'warning' ); ?>
            <?php endif; ?>
            <?php
            WBI_Admin_Shell::render_header(
                array(
                    'title'       => 'Análisis de clientes',
                    'description' => 'Estructura administrativa unificada para rankings, actividad reciente y altas por zona.',
                    'back_link'   => $back_link,
                    'actions'     => array(
                        array(
                            'url'        => $export_url,
                            'label'      => 'Exportar CSV',
                            'class'      => 'wbi-btn wbi-btn-primary',
                            'aria_label' => 'Exportar la vista actual del análisis de clientes a CSV',
                        ),
                        array(
                            'url'   => $reset_url,
                            'label' => 'Restablecer',
                            'class' => 'wbi-btn',
                        ),
                    ),
                )
            );
            WBI_Admin_Shell::render_tabs( $tabs, $tab, array( 'label' => 'Secciones del análisis de clientes' ) );
            ?>

            <!-- FILTRO DE FECHAS: SOLO PARA RANKING -->
            <?php if( $tab == 'ranking' ): ?>
            <form method="get" class="wbi-filter-panel">
                <div class="wbi-filter-grid">
                    <input type="hidden" name="page" value="wbi-clients-report">
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-clients-start">Desde</label>
                        <input id="wbi-clients-start" type="date" name="start" value="<?php echo esc_attr( $start ); ?>">
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-clients-end">Hasta</label>
                        <input id="wbi-clients-end" type="date" name="end" value="<?php echo esc_attr( $end ); ?>">
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-clients-statuses">Estados del pedido</label>
                        <select id="wbi-clients-statuses" name="statuses[]" multiple size="4" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                            <?php foreach ( $all_statuses as $val => $label ) : ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php echo in_array($val, $statuses, true) ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <div class="wbi-filter-actions">
                            <button class="wbi-btn wbi-btn-primary" type="submit">Filtrar</button>
                            <a class="wbi-btn" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wbi-clients-report', 'tab' => 'ranking' ), admin_url( 'admin.php' ) ) ); ?>">Limpiar</a>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <section class="wbi-card">
                <?php
                if($tab=='ranking'){
                    $top = $this->engine->get_clients_ranking('revenue', $start, $end, $statuses);
                    echo '<h3 class="wbi-card-title">Top clientes (' . esc_html( $start ) . ' al ' . esc_html( $end ) . ')</h3>';

                    if ( $top ) {
                        $chart_top = array_slice( $top, 0, 10 );
                        $c_labels  = wp_json_encode( array_map( function( $c ) { return $c->display_name; }, $chart_top ) );
                        $c_data    = wp_json_encode( array_map( function( $c ) { return (float) $c->total_val; }, $chart_top ) );
                        echo '<div class="wbi-chart-container"><canvas id="wbiClientsChart" role="img" aria-label="Gráfico de dona del top de clientes por facturación"></canvas></div>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiClientsChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"doughnut", data:{labels:' . $c_labels . ', datasets:[{data:' . $c_data . ', backgroundColor:["#2271b1","#00a32a","#dba617","#d63638","#8c3130","#72aee6","#68de7c","#ffb900","#50575e","#a16696"], borderWidth:2, borderColor:"#fff"}]}, options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:"right"}}}});
                        })();
                        </script>';
                    }

                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th data-align="right">Total gastado</th><th data-align="right">Cantidad de pedidos</th></tr></thead><tbody>';
                    if($top) foreach($top as $c) echo "<tr><td><strong>" . esc_html($c->display_name) . "</strong></td><td>" . esc_html($c->user_email) . "</td><td data-align='right'>".wc_price($c->total_val)."</td><td data-align='right'>" . intval($c->count_val) . "</td></tr>";
                    else echo "<tr><td colspan=4>No hay datos.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                    
                } elseif($tab=='active'){
                    $active = $this->engine->get_active_customers_list();
                    echo '<h3 class="wbi-card-title">Clientes activos (últimos 60 días)</h3>';
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th>Última compra</th></tr></thead><tbody>';
                    if($active) foreach($active as $a) echo "<tr><td>" . esc_html($a->display_name) . "</td><td>" . esc_html($a->user_email) . "</td><td>".esc_html( mysql2date( 'd/m/Y', $a->last_buy ) )."</td></tr>";
                    else echo "<tr><td colspan=3>Sin actividad.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='zones'){
                    if ( $city !== '' ) {
                        // Detail view: show users for the selected city
                        $customers = $this->engine->get_customers_by_city( $city );
                        $count = $customers ? count( $customers ) : 0;
                        echo '<h3 class="wbi-card-title">Usuarios registrados en ' . esc_html( $city ) . ' (' . intval( $count ) . ')</h3>';
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Nombre</th><th>Email</th><th>Fecha de registro</th><th>Ciudad</th></tr></thead><tbody>';
                        if ( $customers ) {
                            foreach ( $customers as $u ) {
                                echo '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( get_date_from_gmt( $u->user_registered, 'd/m/Y' ) ) . '</td><td>' . esc_html( $u->city ) . '</td></tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4">Sin datos.</td></tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    } else {
                        // Summary view: show zones table with clickable counts
                        $zones = $this->engine->get_new_customers_zones();
                        echo '<h3 class="wbi-card-title">Nuevos registros (últimos 60 días)</h3>';
                        echo '<div class="wbi-table-responsive">'; 
                        echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Ciudad</th><th data-align="right">Nuevos registros</th></tr></thead><tbody>';
                        if ( $zones ) {
                            foreach ( $zones as $z ) {
                                if ( $z->city ) {
                                    $zone_url = esc_url( add_query_arg(
                                        array( 'page' => 'wbi-clients-report', 'tab' => 'zones', 'city' => $z->city ),
                                        admin_url( 'admin.php' )
                                    ) );
                                    echo '<tr><td>' . esc_html( $z->city ) . '</td><td data-align="right"><a href="' . $zone_url . '"><strong>' . intval( $z->count ) . '</strong></a></td></tr>';
                                } else {
                                    echo '<tr><td>Desconocido</td><td data-align="right">' . intval( $z->count ) . '</td></tr>';
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
            </section>
        <?php WBI_Admin_Shell::close_page(); ?>
        <?php
    }
}