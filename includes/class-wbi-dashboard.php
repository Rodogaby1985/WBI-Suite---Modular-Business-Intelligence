<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Dashboard_View {

    private $engine;

    public function __construct() {
        $this->engine = new WBI_Metrics_Engine();
        // Prioridad 99 para ser el menú padre
        add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_page() {
        add_menu_page( 
            'BI Métricas', 
            'BI Métricas', 
            'manage_options', 
            'wbi-dashboard-view', 
            array( $this, 'render' ), 
            'dashicons-chart-area', 
            2 
        );
        remove_submenu_page( 'wbi-dashboard-view', 'wbi-dashboard-view' );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_wbi-dashboard-view' !== $hook ) return;

        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
        
        wp_register_style( 'wbi-admin-css', false );
        wp_enqueue_style( 'wbi-admin-css' );
        // ESTILOS VISUALES COMPLETOS (RESTAURADOS)
        wp_add_inline_style( 'wbi-admin-css', "
            .wbi-wrap { max-width: 1250px; margin: 20px 20px 50px; }
            .wbi-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .wbi-section-title { font-size: 16px; margin: 35px 0 15px; border-bottom: 1px solid #ccc; padding-bottom: 8px; color:#2c3338; font-weight:600; }
            
            /* GRID SYSTEMS */
            .wbi-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
            .wbi-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 20px; }
            
            /* CARDS */
            .wbi-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 3px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .wbi-card.blue { border-top: 3px solid #2271b1; }
            .wbi-card.green { border-top: 3px solid #00a32a; }
            .wbi-card.red { border-top: 3px solid #d63638; }
            .wbi-card.orange { border-top: 3px solid #dba617; }
            
            /* TYPOGRAPHY */
            .wbi-number { font-size: 28px; font-weight: 700; color: #1d2327; margin: 10px 0 5px; line-height:1; }
            .wbi-label { text-transform: uppercase; font-size: 11px; color: #646970; font-weight: 600; letter-spacing: 0.5px; }
            .wbi-compare-value { font-size: 13px; color: #646970; margin-top: 4px; }
            .wbi-delta { font-weight: 700; }
            .wbi-delta.positive { color: #00a32a; }
            .wbi-delta.negative { color: #d63638; }
            
            /* TABLES */
            .wbi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .wbi-table th { text-align: left; border-bottom: 2px solid #f0f0f1; padding: 8px 4px; color: #50575e; font-size:11px; text-transform:uppercase; }
            .wbi-table td { border-bottom: 1px solid #f0f0f1; padding: 8px 4px; color: #3c434a; }
            .wbi-table tr:last-child td { border-bottom: none; }
            
            /* FILTERS BAR */
            .wbi-filter-bar { background: #fff; padding: 15px 20px; border: 1px solid #c3c4c7; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; border-radius: 3px; border-left: 4px solid #2271b1; }
            .wbi-date-inputs { display: inline-flex; gap: 10px; align-items: center; background: #f0f0f1; padding: 5px 10px; border-radius: 4px; }
            
            /* CHARTS */
            .wbi-chart-container { position: relative; max-height: 300px; }
        ");
    }

    /**
     * FUNCIÓN DE SEGURIDAD: Evita el error crítico si falta un dato
     */
    private function get_safe_count($data, $status) {
        if ( isset($data[$status]) && is_object($data[$status]) ) {
            return $data[$status]->count;
        }
        return 0;
    }

    public function render() {
        // --- 1. LÓGICA DE FECHAS (Restaurada) ---
        $range = isset($_GET['wbi_range']) ? sanitize_text_field($_GET['wbi_range']) : '30d';
        $end_date = date('Y-m-d'); 
        $start_date = date('Y-m-d', strtotime('-30 days'));

        if( $range === 'custom' && !empty($_GET['wbi_start']) && !empty($_GET['wbi_end']) ) {
            $start_date = sanitize_text_field($_GET['wbi_start']);
            $end_date = sanitize_text_field($_GET['wbi_end']);
        } else {
            switch($range) {
                case 'today': $start_date = date('Y-m-d'); break;
                case 'yesterday': $start_date = date('Y-m-d', strtotime('-1 day')); $end_date = $start_date; break;
                case '7d': $start_date = date('Y-m-d', strtotime('-7 days')); break;
                case 'this_month': $start_date = date('Y-m-01'); break;
                case 'last_month': $start_date = date('Y-m-01', strtotime('last month')); $end_date = date('Y-m-t', strtotime('last month')); break;
                case 'this_year': $start_date = date('Y-01-01'); break;
            }
        }

        // --- 2. COMPARACIÓN DE PERIODO ---
        $compare = isset($_GET['wbi_compare']) ? sanitize_text_field($_GET['wbi_compare']) : 'none';
        $prev_start = '';
        $prev_end   = '';

        if ( $compare !== 'none' ) {
            $current_diff = (int) round( ( strtotime($end_date) - strtotime($start_date) ) / DAY_IN_SECONDS );
            if ( $compare === 'prev_period' ) {
                $prev_end   = date('Y-m-d', strtotime($start_date) - DAY_IN_SECONDS);
                $prev_start = date('Y-m-d', strtotime($prev_end) - $current_diff * DAY_IN_SECONDS);
            } elseif ( $compare === 'prev_year' ) {
                $prev_start = date('Y-m-d', strtotime($start_date . ' -1 year'));
                $prev_end   = date('Y-m-d', strtotime($end_date . ' -1 year'));
            } elseif ( $compare === 'custom_compare' && !empty($_GET['wbi_prev_start']) && !empty($_GET['wbi_prev_end']) ) {
                $prev_start = sanitize_text_field($_GET['wbi_prev_start']);
                $prev_end   = sanitize_text_field($_GET['wbi_prev_end']);
            }
        }

        // --- 3. OBTENER DATOS ---
        $default_statuses = array('wc-completed', 'wc-processing');
        $statuses = isset($_GET['statuses']) ? array_map('sanitize_text_field', (array)$_GET['statuses']) : $default_statuses;

        $revenue = $this->engine->get_revenue($start_date, $end_date, $statuses) ?: 0;
        $units   = $this->engine->get_units_sold($start_date, $end_date, $statuses) ?: 0;
        
        $status_raw   = $this->engine->get_order_status_counts();
        $c_completed  = $this->get_safe_count($status_raw, 'wc-completed');
        $c_processing = $this->get_safe_count($status_raw, 'wc-processing');
        $c_hold       = $this->get_safe_count($status_raw, 'wc-on-hold');
        $c_cancelled  = $this->get_safe_count($status_raw, 'wc-cancelled');
        $c_failed     = $this->get_safe_count($status_raw, 'wc-failed');

        $least_sold = $this->engine->get_least_sold($start_date, $end_date, $statuses);
        $best_sold  = $this->engine->get_best_sellers($start_date, $end_date, $statuses);

        // Period data for chart
        $period_data = $this->engine->get_sales_by_period('day', $start_date, $end_date, $statuses);

        // Comparison data
        $prev_revenue = 0;
        $prev_units   = 0;
        if ( $compare !== 'none' && $prev_start && $prev_end ) {
            $prev_revenue = $this->engine->get_revenue($prev_start, $prev_end, $statuses) ?: 0;
            $prev_units   = $this->engine->get_units_sold($prev_start, $prev_end, $statuses) ?: 0;
        }

        // Build chart data arrays
        $chart_labels  = array();
        $chart_totals  = array();
        foreach ( $period_data as $row ) {
            $chart_labels[] = $row->period;
            $chart_totals[] = (float) $row->total;
        }
        $chart_labels_json = wp_json_encode( $chart_labels );
        $chart_totals_json = wp_json_encode( $chart_totals );

        // Doughnut chart data for order statuses
        $donut_labels = wp_json_encode( array('Completados','En Proceso','En Espera','Cancelados','Fallidos') );
        $donut_data   = wp_json_encode( array($c_completed, $c_processing, $c_hold, $c_cancelled, $c_failed) );

        ?>
        <div class="wrap wbi-wrap">
            
            <div class="wbi-header">
                <h1 style="margin:0;">BI Dashboard Ejecutivo</h1>
            </div>
            
            <!-- BARRA DE FILTROS VISUAL -->
            <form method="get" class="wbi-filter-bar">
                <input type="hidden" name="page" value="wbi-dashboard-view" />
                
                <label style="font-weight:600;">📅 Periodo Análisis:</label>
                <select name="wbi_range" id="wbi_range" onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php selected($range, 'today'); ?>>Hoy</option>
                    <option value="yesterday" <?php selected($range, 'yesterday'); ?>>Ayer</option>
                    <option value="7d" <?php selected($range, '7d'); ?>>7 Días</option>
                    <option value="30d" <?php selected($range, '30d'); ?>>30 Días</option>
                    <option value="this_month" <?php selected($range, 'this_month'); ?>>Este Mes</option>
                    <option value="last_month" <?php selected($range, 'last_month'); ?>>Mes Pasado</option>
                    <option value="this_year" <?php selected($range, 'this_year'); ?>>Este Año</option>
                    <option value="custom" <?php selected($range, 'custom'); ?>>Personalizado...</option>
                </select>

                <div id="wbi_custom_dates" class="wbi-date-inputs" style="display: <?php echo ($range === 'custom') ? 'inline-flex' : 'none'; ?>;">
                    <input type="date" name="wbi_start" value="<?php echo esc_attr($start_date); ?>">
                    <span class="dashicons dashicons-arrow-right-alt"></span>
                    <input type="date" name="wbi_end" value="<?php echo esc_attr($end_date); ?>">
                </div>

                <label style="font-weight:600; margin-left:10px;">🔄 Comparar con:</label>
                <select name="wbi_compare" id="wbi_compare" onchange="toggleCompareDates(this.value)">
                    <option value="none" <?php selected($compare, 'none'); ?>>Sin comparación</option>
                    <option value="prev_period" <?php selected($compare, 'prev_period'); ?>>Período Anterior</option>
                    <option value="prev_year" <?php selected($compare, 'prev_year'); ?>>Mismo Período Año Anterior</option>
                    <option value="custom_compare" <?php selected($compare, 'custom_compare'); ?>>Fechas Personalizadas...</option>
                </select>

                <div id="wbi_compare_dates" class="wbi-date-inputs" style="display: <?php echo ($compare === 'custom_compare') ? 'inline-flex' : 'none'; ?>;">
                    <input type="date" name="wbi_prev_start" value="<?php echo esc_attr($prev_start); ?>">
                    <span class="dashicons dashicons-arrow-right-alt"></span>
                    <input type="date" name="wbi_prev_end" value="<?php echo esc_attr($prev_end); ?>">
                </div>

                <label style="font-weight:600; margin-left:10px;">📦 Estados:</label>
                <select name="statuses[]" multiple size="4" style="height:72px; min-width:140px;" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                    <option value="wc-completed"  <?php echo in_array('wc-completed',  $statuses, true) ? 'selected' : ''; ?>>✅ Completado</option>
                    <option value="wc-processing" <?php echo in_array('wc-processing', $statuses, true) ? 'selected' : ''; ?>>🔄 En proceso</option>
                    <option value="wc-on-hold"    <?php echo in_array('wc-on-hold',    $statuses, true) ? 'selected' : ''; ?>>⏸ En espera</option>
                    <option value="wc-pending"    <?php echo in_array('wc-pending',    $statuses, true) ? 'selected' : ''; ?>>⏳ Pendiente</option>
                </select>

                <button type="submit" class="button button-primary">Aplicar Filtros</button>
            </form>

            <script>
                function toggleCustomDates(val) {
                    document.getElementById('wbi_custom_dates').style.display = (val === 'custom') ? 'inline-flex' : 'none';
                }
                function toggleCompareDates(val) {
                    document.getElementById('wbi_compare_dates').style.display = (val === 'custom_compare') ? 'inline-flex' : 'none';
                }
            </script>

            <!-- SECCIÓN 1: ESTADO GLOBAL PEDIDOS -->
            <h2 class="wbi-section-title">📦 Estado Global de Pedidos</h2>
            <div class="wbi-grid-4">
                <div class="wbi-card green">
                    <div class="wbi-label">Completados</div>
                    <div class="wbi-number"><?php echo $c_completed; ?></div>
                </div>
                <div class="wbi-card blue">
                    <div class="wbi-label">En Proceso (Armado)</div>
                    <div class="wbi-number"><?php echo $c_processing; ?></div>
                </div>
                <div class="wbi-card orange">
                    <div class="wbi-label">En Espera (Pago/Stock)</div>
                    <div class="wbi-number"><?php echo $c_hold; ?></div>
                </div>
                <div class="wbi-card red">
                    <div class="wbi-label">Cancelados/Fallidos</div>
                    <div class="wbi-number"><?php echo ($c_cancelled + $c_failed); ?></div>
                </div>
            </div>

            <!-- SECCIÓN 2: RENDIMIENTO ECONÓMICO -->
            <h2 class="wbi-section-title">📈 Rendimiento (<?php echo date('d/m', strtotime($start_date)) . ' - ' . date('d/m', strtotime($end_date)); ?>)</h2>
            <div class="wbi-grid-4">
                <div class="wbi-card blue">
                    <div class="wbi-label">Facturación Periodo</div>
                    <div class="wbi-number"><?php echo wc_price($revenue); ?></div>
                    <?php if ( $compare !== 'none' && $prev_start ) : 
                        $rev_delta = $this->calc_delta($revenue, $prev_revenue);
                    ?>
                    <div class="wbi-compare-value">
                        Anterior: <?php echo wc_price($prev_revenue); ?>
                        <?php echo $this->render_delta($rev_delta); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="wbi-card">
                    <div class="wbi-label">Unidades Vendidas</div>
                    <div class="wbi-number"><?php echo $units; ?></div>
                    <?php if ( $compare !== 'none' && $prev_start ) :
                        $units_delta = $this->calc_delta($units, $prev_units);
                    ?>
                    <div class="wbi-compare-value">
                        Anterior: <?php echo $prev_units; ?>
                        <?php echo $this->render_delta($units_delta); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECCIÓN 3: GRÁFICOS -->
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <h3 style="margin-top:0;">📊 Facturación por Día</h3>
                    <div class="wbi-chart-container">
                        <canvas id="wbiRevenueChart"></canvas>
                    </div>
                </div>
                <div class="wbi-card">
                    <h3 style="margin-top:0;">🥧 Distribución de Pedidos por Estado</h3>
                    <div class="wbi-chart-container" style="max-height:260px; display:flex; justify-content:center;">
                        <canvas id="wbiStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 4: PRODUCTOS TOP/BOTTOM -->
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <h3 style="margin-top:0;">🔥 Productos Más Vendidos</h3>
                    <table class="wbi-table wbi-sortable">
                        <thead><tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr></thead>
                        <tbody>
                            <?php if(!empty($best_sold) && is_array($best_sold)): foreach(array_slice($best_sold, 0, 5) as $p): ?>
                                <tr>
                                    <td><?php echo esc_html($p->name); ?></td>
                                    <td style="text-align:right;"><strong><?php echo $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else: echo "<tr><td colspan='2'>Sin ventas en este periodo</td></tr>"; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="wbi-card red">
                    <h3 style="margin-top:0;">⚠️ Productos con Menos Movimiento</h3>
                    <p style="font-size:12px; color:#666; margin-bottom:10px;">Bottom 5 (de los que tuvieron ventas)</p>
                    <table class="wbi-table wbi-sortable">
                        <thead><tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr></thead>
                        <tbody>
                            <?php if(!empty($least_sold) && is_array($least_sold)): foreach(array_slice($least_sold, 0, 5) as $p): ?>
                                <tr>
                                    <td><?php echo esc_html($p->name); ?></td>
                                    <td style="text-align:right;"><strong><?php echo $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else: echo "<tr><td colspan='2'>Sin datos</td></tr>"; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
            (function() {
                // Bar chart: revenue by day
                var revenueCtx = document.getElementById('wbiRevenueChart');
                if (revenueCtx) {
                    new Chart(revenueCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo $chart_labels_json; ?>,
                            datasets: [{
                                label: 'Facturación',
                                data: <?php echo $chart_totals_json; ?>,
                                backgroundColor: 'rgba(34, 113, 177, 0.7)',
                                borderColor: '#2271b1',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true } }
                        }
                    });
                }

                // Doughnut chart: order status
                var statusCtx = document.getElementById('wbiStatusChart');
                if (statusCtx) {
                    new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: <?php echo $donut_labels; ?>,
                            datasets: [{
                                data: <?php echo $donut_data; ?>,
                                backgroundColor: ['#00a32a','#2271b1','#dba617','#d63638','#8c3130'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                }
            })();
            </script>

        </div>
        <?php
    }

    private function calc_delta( $current, $previous ) {
        if ( (float) $previous === 0.0 ) return null;
        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }

    private function render_delta( $delta ) {
        if ( $delta === null ) return '';
        if ( $delta > 0 ) {
            return '<span class="wbi-delta positive">▲ ' . $delta . '%</span>';
        } elseif ( $delta < 0 ) {
            return '<span class="wbi-delta negative">▼ ' . abs($delta) . '%</span>';
        }
        return '<span class="wbi-delta">→ 0%</span>';
    }
}