<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Dashboard_View {

    private $engine;
    private $allowed_statuses = array(
        'wc-completed',
        'wc-processing',
        'wc-on-hold',
        'wc-pending',
    );

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        // Prioridad 99 para ser el menú padre
        add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_page() {
        add_menu_page( 
            'WooErp', 
            'WooErp', 
            'manage_options', 
            'wbi-dashboard-view', 
            array( $this, 'render' ), 
            'dashicons-analytics', 
            2 
        );
        // Renombrar el primer submenu automático a "Dashboard"
        add_submenu_page(
            'wbi-dashboard-view',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wbi-dashboard-view',
            array( $this, 'render' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_wbi-dashboard-view' !== $hook ) return;

        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );

        wp_enqueue_style(
            'wbi-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin.css',
            array(),
            '8.0.0'
        );
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
        $filters_nonce = wp_create_nonce( 'wbi_dashboard_filters' );
        $filter_keys = array(
            'wbi_range',
            'wbi_start',
            'wbi_end',
            'wbi_compare',
            'wbi_prev_start',
            'wbi_prev_end',
            'statuses',
            'wbi_top_page',
            'wbi_least_page',
            'wbi_top_per_page',
            'wbi_least_per_page',
        );
        $has_filter_request = false;
        foreach ( $filter_keys as $filter_key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET[ $filter_key ] ) ) {
                $has_filter_request = true;
                break;
            }
        }
        $has_valid_filter_nonce = isset( $_GET['wbi_dashboard_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['wbi_dashboard_nonce'] ) ), 'wbi_dashboard_filters' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // --- 1. LÓGICA DE FECHAS (Restaurada) ---
        $range = ( $has_filter_request && ! $has_valid_filter_nonce ) ? '30d' : ( isset($_GET['wbi_range']) ? $this->sanitize_dashboard_query_arg( 'wbi_range', $_GET['wbi_range'] ) : '30d' );
        $end_date = date('Y-m-d'); 
        $start_date = date('Y-m-d', strtotime('-30 days'));

        if ( $has_filter_request && ! $has_valid_filter_nonce ) {
            $range = '30d';
        }

        if( $range === 'custom' && !empty($_GET['wbi_start']) && !empty($_GET['wbi_end']) && ( ! $has_filter_request || $has_valid_filter_nonce ) ) {
            $start_date = $this->sanitize_dashboard_query_arg( 'wbi_start', $_GET['wbi_start'] );
            $end_date = $this->sanitize_dashboard_query_arg( 'wbi_end', $_GET['wbi_end'] );
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
        $compare = ( $has_filter_request && ! $has_valid_filter_nonce ) ? 'none' : ( isset($_GET['wbi_compare']) ? $this->sanitize_dashboard_query_arg( 'wbi_compare', $_GET['wbi_compare'] ) : 'none' );
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
            } elseif ( $compare === 'custom_compare' && !empty($_GET['wbi_prev_start']) && !empty($_GET['wbi_prev_end']) && ( ! $has_filter_request || $has_valid_filter_nonce ) ) {
                $prev_start = $this->sanitize_dashboard_query_arg( 'wbi_prev_start', $_GET['wbi_prev_start'] );
                $prev_end   = $this->sanitize_dashboard_query_arg( 'wbi_prev_end', $_GET['wbi_prev_end'] );
            }
        }

        // --- 3. OBTENER DATOS ---
        $default_statuses = array( 'wc-completed', 'wc-processing' );
        $statuses = ( $has_filter_request && ! $has_valid_filter_nonce )
            ? $default_statuses
            : ( isset($_GET['statuses']) ? array_map( function ( $status ) {
                return $this->sanitize_dashboard_query_arg( 'statuses', $status );
            }, (array) $_GET['statuses'] ) : $default_statuses );
        $statuses = array_values( array_intersect( $statuses, $this->allowed_statuses ) );
        if ( empty( $statuses ) ) {
            $statuses = $default_statuses;
        }

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

        $top_per_page_allowed = array( 5, 10, 25 );
        $best_per_page = ( $has_filter_request && ! $has_valid_filter_nonce )
            ? 5
            : ( isset( $_GET['wbi_top_per_page'] ) ? (int) $this->sanitize_dashboard_query_arg( 'wbi_top_per_page', $_GET['wbi_top_per_page'] ) : 5 );
        if ( ! in_array( $best_per_page, $top_per_page_allowed, true ) ) {
            $best_per_page = 5;
        }
        $top_page = ( $has_filter_request && ! $has_valid_filter_nonce )
            ? 1
            : ( isset( $_GET['wbi_top_page'] ) ? (int) $this->sanitize_dashboard_query_arg( 'wbi_top_page', $_GET['wbi_top_page'] ) : 1 );
        $top_page = max( 1, $top_page );

        $best_sold = is_array( $best_sold ) ? $best_sold : array();
        $least_sold = is_array( $least_sold ) ? $least_sold : array();
        $best_total = count( $best_sold );
        $least_total = count( $least_sold );
        $best_has_rows = $best_total > 0;
        $best_total_pages = max( 1, (int) ceil( $best_total / $best_per_page ) );
        if ( $top_page > $best_total_pages ) {
            $top_page = $best_total_pages;
        }
        $top_offset = ( $top_page - 1 ) * $best_per_page;
        $best_sold_page = array_slice( $best_sold, $top_offset, $best_per_page );

        $least_per_page = ( $has_filter_request && ! $has_valid_filter_nonce )
            ? 5
            : ( isset( $_GET['wbi_least_per_page'] ) ? (int) $this->sanitize_dashboard_query_arg( 'wbi_least_per_page', $_GET['wbi_least_per_page'] ) : 5 );
        if ( ! in_array( $least_per_page, $top_per_page_allowed, true ) ) {
            $least_per_page = 5;
        }
        $least_page = ( $has_filter_request && ! $has_valid_filter_nonce )
            ? 1
            : ( isset( $_GET['wbi_least_page'] ) ? (int) $this->sanitize_dashboard_query_arg( 'wbi_least_page', $_GET['wbi_least_page'] ) : 1 );
        $least_page = max( 1, $least_page );
        $least_has_rows = $least_total > 0;
        $least_total_pages = max( 1, (int) ceil( $least_total / $least_per_page ) );
        if ( $least_page > $least_total_pages ) {
            $least_page = $least_total_pages;
        }
        $least_offset = ( $least_page - 1 ) * $least_per_page;
        $least_sold_page = array_slice( $least_sold, $least_offset, $least_per_page );

        $allowed_query_fields = array(
            'page',
            'wbi_range',
            'wbi_start',
            'wbi_end',
            'wbi_compare',
            'wbi_prev_start',
            'wbi_prev_end',
            'statuses',
            'wbi_dashboard_nonce',
            'wbi_top_per_page',
            'wbi_least_per_page',
            'wbi_top_page',
            'wbi_least_page',
        );

        // Period data for chart
        $period_data = $this->engine->get_sales_by_period('day', $start_date, $end_date, $statuses);

        // Monthly revenue trend (current year)
        $year_start = date('Y-01-01');
        $year_end   = date('Y-12-31');
        $monthly_data = $this->engine->get_sales_by_period('month', $year_start, $year_end, $statuses);
        $monthly_labels = array();
        $monthly_totals = array();
        foreach ( $monthly_data as $row ) {
            $monthly_labels[] = $row->period;
            $monthly_totals[] = (float) $row->total;
        }
        $monthly_labels_json = wp_json_encode( $monthly_labels );
        $monthly_totals_json = wp_json_encode( $monthly_totals );

        // Top 5 products chart data
        $top5_names = array();
        $top5_qtys  = array();
        if ( ! empty( $best_sold ) ) {
            foreach ( array_slice( $best_sold, 0, 5 ) as $p ) {
                $top5_names[] = $p->name;
                $top5_qtys[]  = intval( $p->qty );
            }
        }
        $top5_names_json = wp_json_encode( $top5_names );
        $top5_qtys_json  = wp_json_encode( $top5_qtys );

        // Sales by source (only if data module is active)
        $options = get_option( 'wbi_modules_settings' );
        $has_source_module = ! empty( $options['wbi_enable_data'] );
        $source_labels_json = wp_json_encode( array() );
        $source_totals_json = wp_json_encode( array() );
        if ( $has_source_module ) {
            $source_data = $this->engine->get_sales_by_source( $start_date, $end_date, $statuses );
            $src_labels  = array();
            $src_totals  = array();
            foreach ( $source_data as $row ) {
                $src_labels[] = $row->source ?: 'Sin origen';
                $src_totals[] = (float) $row->total;
            }
            $source_labels_json = wp_json_encode( $src_labels );
            $source_totals_json = wp_json_encode( $src_totals );
        }

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
            <div class="wbi-page">
                <header class="wbi-page-header wbi-header">
                    <div>
                        <h1 class="wbi-page-title">BI Dashboard Ejecutivo</h1>
                        <p class="wbi-page-description">Resumen operativo y comercial con filtros por período, estado y comparación.</p>
                    </div>
                    <div class="wbi-page-actions">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-dashboard-view' ) ); ?>" class="wbi-btn wbi-btn-link">Restablecer filtros</a>
                    </div>
                </header>
                
                <!-- BARRA DE FILTROS VISUAL -->
                <form method="get" class="wbi-filter-panel">
                    <input type="hidden" name="page" value="wbi-dashboard-view" />
                    <input type="hidden" name="wbi_dashboard_nonce" value="<?php echo esc_attr( $filters_nonce ); ?>" />
                    <div class="wbi-filter-grid">
                        <div class="wbi-filter-field wbi-col-2">
                            <label for="wbi_range">Período de análisis</label>
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
                        </div>
                        <div class="wbi-filter-field wbi-col-4<?php echo ( $range === 'custom' ) ? '' : ' wbi-is-hidden'; ?>" id="wbi_custom_dates">
                            <label>Rango personalizado</label>
                            <div class="wbi-date-inputs">
                                <label for="wbi_start" class="wbi-visually-hidden">Fecha de inicio</label>
                                <input id="wbi_start" type="date" name="wbi_start" value="<?php echo esc_attr($start_date); ?>" <?php disabled( $range !== 'custom' ); ?>>
                                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
                                <label for="wbi_end" class="wbi-visually-hidden">Fecha de fin</label>
                                <input id="wbi_end" type="date" name="wbi_end" value="<?php echo esc_attr($end_date); ?>" <?php disabled( $range !== 'custom' ); ?>>
                            </div>
                        </div>
                        <div class="wbi-filter-field wbi-col-3">
                            <label for="wbi_compare">Comparar con</label>
                            <select name="wbi_compare" id="wbi_compare" onchange="toggleCompareDates(this.value)">
                    <option value="none" <?php selected($compare, 'none'); ?>>Sin comparación</option>
                    <option value="prev_period" <?php selected($compare, 'prev_period'); ?>>Período Anterior</option>
                    <option value="prev_year" <?php selected($compare, 'prev_year'); ?>>Mismo Período Año Anterior</option>
                    <option value="custom_compare" <?php selected($compare, 'custom_compare'); ?>>Fechas Personalizadas...</option>
                            </select>
                        </div>
                        <div class="wbi-filter-field wbi-col-3<?php echo ( $compare === 'custom_compare' ) ? '' : ' wbi-is-hidden'; ?>" id="wbi_compare_dates">
                            <label>Fechas de comparación</label>
                            <div class="wbi-date-inputs">
                                <label for="wbi_prev_start" class="wbi-visually-hidden">Fecha de inicio comparación</label>
                                <input id="wbi_prev_start" type="date" name="wbi_prev_start" value="<?php echo esc_attr($prev_start); ?>" <?php disabled( $compare !== 'custom_compare' ); ?>>
                                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
                                <label for="wbi_prev_end" class="wbi-visually-hidden">Fecha de fin comparación</label>
                                <input id="wbi_prev_end" type="date" name="wbi_prev_end" value="<?php echo esc_attr($prev_end); ?>" <?php disabled( $compare !== 'custom_compare' ); ?>>
                            </div>
                        </div>
                        <div class="wbi-filter-field wbi-col-3">
                            <label for="wbi_statuses">Estados del pedido</label>
                            <select id="wbi_statuses" name="statuses[]" multiple size="4" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                    <option value="wc-completed"  <?php echo in_array('wc-completed',  $statuses, true) ? 'selected' : ''; ?>>✅ Completado</option>
                    <option value="wc-processing" <?php echo in_array('wc-processing', $statuses, true) ? 'selected' : ''; ?>>🔄 En proceso</option>
                    <option value="wc-on-hold"    <?php echo in_array('wc-on-hold',    $statuses, true) ? 'selected' : ''; ?>>⏸ En espera</option>
                    <option value="wc-pending"    <?php echo in_array('wc-pending',    $statuses, true) ? 'selected' : ''; ?>>⏳ Pendiente</option>
                            </select>
                        </div>
                        <div class="wbi-filter-field wbi-col-3">
                            <div class="wbi-filter-actions">
                                <button type="submit" class="wbi-btn wbi-btn-primary">Aplicar filtros</button>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-dashboard-view' ) ); ?>" class="wbi-btn">Limpiar</a>
                            </div>
                        </div>
                    </div>
                </form>

            <script>
                function toggleCustomDates(val) {
                    var customDates = document.getElementById('wbi_custom_dates');
                    var isCustom = (val === 'custom');
                    customDates.classList.toggle('wbi-is-hidden', !isCustom);
                    customDates.querySelectorAll('input').forEach(function(input) {
                        input.disabled = !isCustom;
                    });
                }
                function toggleCompareDates(val) {
                    var compareDates = document.getElementById('wbi_compare_dates');
                    var isCompareCustom = (val === 'custom_compare');
                    compareDates.classList.toggle('wbi-is-hidden', !isCompareCustom);
                    compareDates.querySelectorAll('input').forEach(function(input) {
                        input.disabled = !isCompareCustom;
                    });
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
            <h2 class="wbi-section-title">📊 Gráficos Interactivos</h2>
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <h3 class="wbi-card-title">Tendencia Mensual (<?php echo esc_html( date('Y') ); ?>)</h3>
                    <div class="wbi-chart-container">
                        <canvas id="wbiMonthlyChart"></canvas>
                    </div>
                </div>
                <div class="wbi-card">
                    <h3 class="wbi-card-title">Distribución de Pedidos por Estado</h3>
                    <div class="wbi-chart-container wbi-chart-container-centered">
                        <canvas id="wbiStatusChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <h3 class="wbi-card-title">Top Productos (<?php echo esc_html( date('d/m', strtotime($start_date)) . ' - ' . date('d/m', strtotime($end_date)) ); ?>)</h3>
                    <div class="wbi-chart-container">
                        <canvas id="wbiTopProductsChart"></canvas>
                    </div>
                </div>
                <?php if ( $has_source_module ) : ?>
                <div class="wbi-card">
                    <h3 class="wbi-card-title">Ventas por Origen</h3>
                    <div class="wbi-chart-container wbi-chart-container-centered">
                        <canvas id="wbiSourceChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                <div class="wbi-card">
                    <h3 class="wbi-card-title">Facturación por Día</h3>
                    <div class="wbi-chart-container">
                        <canvas id="wbiRevenueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 4: PRODUCTOS TOP/BOTTOM -->
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <div class="wbi-card-header">
                        <h3 class="wbi-card-title">Productos Más Vendidos</h3>
                    </div>
                    <div class="wbi-table-responsive">
                    <table class="wbi-table wbi-sortable">
                        <thead><tr><th>Producto</th><th data-align="right">Cant.</th></tr></thead>
                        <tbody>
                            <?php if ( ! empty( $best_sold_page ) ) : foreach ( $best_sold_page as $p ) : ?>
                                <tr>
                                    <td><?php echo esc_html($p->name); ?></td>
                                    <td data-align="right"><strong><?php echo (int) $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr><td colspan="2">Sin ventas en este periodo</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php
                    $best_pagination_base = add_query_arg(
                        $this->get_allowed_query_args( $allowed_query_fields, array( 'wbi_top_page' ) ),
                        admin_url( 'admin.php' )
                    );
                    $best_pagination_links = paginate_links(
                        array(
                            'base'      => esc_url_raw( add_query_arg( 'wbi_top_page', '%#%', $best_pagination_base ) ),
                            'format'    => '',
                            'current'   => $top_page,
                            'total'     => $best_total_pages,
                            'prev_text' => 'Anterior',
                            'next_text' => 'Siguiente',
                            'type'      => 'plain',
                        )
                    );
                    ?>
                    <div class="wbi-pagination">
                        <form method="get" class="wbi-filter-actions">
                            <?php $this->render_hidden_query_fields( $allowed_query_fields, array( 'wbi_top_per_page', 'wbi_top_page', 'wbi_least_page' ) ); ?>
                            <input type="hidden" name="wbi_dashboard_nonce" value="<?php echo esc_attr( $filters_nonce ); ?>" />
                            <label for="wbi_top_per_page_best">Filas por página</label>
                            <select id="wbi_top_per_page_best" name="wbi_top_per_page" onchange="this.form.submit()">
                                <?php foreach ( $top_per_page_allowed as $size ) : ?>
                                    <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $best_per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php if ( $best_has_rows ) : ?>
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php echo esc_html( sprintf( 'Página %1$d de %2$d', (int) $top_page, (int) $best_total_pages ) ); ?></span>
                                <?php if ( ! empty( $best_pagination_links ) ) : ?>
                                    <?php echo wp_kses_post( $best_pagination_links ); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wbi-card red">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title">Productos con Menos Movimiento</h3>
                            <p class="wbi-card-subtitle">Items con ventas bajas en el período seleccionado</p>
                        </div>
                    </div>
                    <div class="wbi-table-responsive">
                    <table class="wbi-table wbi-sortable">
                        <thead><tr><th>Producto</th><th data-align="right">Cant.</th></tr></thead>
                        <tbody>
                            <?php if ( ! empty( $least_sold_page ) ) : foreach ( $least_sold_page as $p ) : ?>
                                <tr>
                                    <td><?php echo esc_html($p->name); ?></td>
                                    <td data-align="right"><strong><?php echo (int) $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr><td colspan="2">Sin datos</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php
                    $least_pagination_base = add_query_arg(
                        $this->get_allowed_query_args( $allowed_query_fields, array( 'wbi_least_page' ) ),
                        admin_url( 'admin.php' )
                    );
                    $least_pagination_links = paginate_links(
                        array(
                            'base'      => esc_url_raw( add_query_arg( 'wbi_least_page', '%#%', $least_pagination_base ) ),
                            'format'    => '',
                            'current'   => $least_page,
                            'total'     => $least_total_pages,
                            'prev_text' => 'Anterior',
                            'next_text' => 'Siguiente',
                            'type'      => 'plain',
                        )
                    );
                    ?>
                    <div class="wbi-pagination">
                        <form method="get" class="wbi-filter-actions">
                            <?php $this->render_hidden_query_fields( $allowed_query_fields, array( 'wbi_least_per_page', 'wbi_least_page', 'wbi_top_page' ) ); ?>
                            <input type="hidden" name="wbi_dashboard_nonce" value="<?php echo esc_attr( $filters_nonce ); ?>" />
                            <label for="wbi_least_per_page">Filas por página</label>
                            <select id="wbi_least_per_page" name="wbi_least_per_page" onchange="this.form.submit()">
                                <?php foreach ( $top_per_page_allowed as $size ) : ?>
                                    <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $least_per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php if ( $least_has_rows ) : ?>
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php echo esc_html( sprintf( 'Página %1$d de %2$d', (int) $least_page, (int) $least_total_pages ) ); ?></span>
                                <?php if ( ! empty( $least_pagination_links ) ) : ?>
                                    <?php echo wp_kses_post( $least_pagination_links ); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                // Line chart: monthly revenue trend (current year)
                var monthlyCtx = document.getElementById('wbiMonthlyChart');
                if (monthlyCtx) {
                    new Chart(monthlyCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo $monthly_labels_json; ?>,
                            datasets: [{
                                label: 'Facturación Mensual',
                                data: <?php echo $monthly_totals_json; ?>,
                                backgroundColor: 'rgba(34, 113, 177, 0.15)',
                                borderColor: '#2271b1',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3,
                                pointBackgroundColor: '#2271b1'
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

                // Horizontal bar chart: top 5 products
                var topProdCtx = document.getElementById('wbiTopProductsChart');
                if (topProdCtx) {
                    new Chart(topProdCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo $top5_names_json; ?>,
                            datasets: [{
                                label: 'Unidades',
                                data: <?php echo $top5_qtys_json; ?>,
                                backgroundColor: 'rgba(0, 163, 42, 0.7)',
                                borderColor: '#00a32a',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: { legend: { display: false } },
                            scales: { x: { beginAtZero: true } }
                        }
                    });
                }

                <?php if ( $has_source_module ) : ?>
                // Pie chart: sales by source
                var sourceCtx = document.getElementById('wbiSourceChart');
                if (sourceCtx) {
                    new Chart(sourceCtx, {
                        type: 'pie',
                        data: {
                            labels: <?php echo $source_labels_json; ?>,
                            datasets: [{
                                data: <?php echo $source_totals_json; ?>,
                                backgroundColor: ['#2271b1','#00a32a','#dba617','#d63638','#8c3130','#72aee6','#68de7c','#ffb900'],
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
                <?php endif; ?>
            })();
            </script>

        </div>
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

    private function render_hidden_query_fields( $allowed_keys = array(), $exclude_keys = array() ) {
        $query_args = $this->get_allowed_query_args( $allowed_keys, $exclude_keys );
        foreach ( $query_args as $key => $value ) {
            if ( 'wbi_dashboard_nonce' === $key ) {
                continue;
            }
            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $item ) . '" />';
                }
            } else {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
            }
        }
    }

    private function get_allowed_query_args( $allowed_keys = array(), $exclude_keys = array() ) {
        $filter_keys = array(
            'wbi_range',
            'wbi_start',
            'wbi_end',
            'wbi_compare',
            'wbi_prev_start',
            'wbi_prev_end',
            'statuses',
            'wbi_top_page',
            'wbi_least_page',
            'wbi_top_per_page',
            'wbi_least_per_page',
        );
        $has_filter_request = false;
        foreach ( $filter_keys as $filter_key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET[ $filter_key ] ) ) {
                $has_filter_request = true;
                break;
            }
        }
        $has_valid_filter_nonce = isset( $_GET['wbi_dashboard_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['wbi_dashboard_nonce'] ) ), 'wbi_dashboard_filters' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $has_filter_request && ! $has_valid_filter_nonce ) {
            return array(
                'page'                => 'wbi-dashboard-view',
                'wbi_dashboard_nonce' => wp_create_nonce( 'wbi_dashboard_filters' ),
            );
        }

        $args = array();
        foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( 'wbi_dashboard_nonce' === $key ) {
                continue;
            }
            if ( ! in_array( $key, $allowed_keys, true ) || in_array( $key, $exclude_keys, true ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $args[ $key ] = array_map(
                    function ( $item ) use ( $key ) {
                        return $this->sanitize_dashboard_query_arg( $key, $item );
                    },
                    $value
                );
                $args[ $key ] = array_values( array_filter( $args[ $key ], static function ( $item ) {
                    return '' !== $item;
                } ) );
                if ( empty( $args[ $key ] ) ) {
                    unset( $args[ $key ] );
                }
            } else {
                $args[ $key ] = $this->sanitize_dashboard_query_arg( $key, $value );
                if ( '' === $args[ $key ] ) {
                    unset( $args[ $key ] );
                }
            }
        }
        if ( empty( $args['page'] ) ) {
            $args['page'] = 'wbi-dashboard-view';
        }
        $args['wbi_dashboard_nonce'] = wp_create_nonce( 'wbi_dashboard_filters' );
        return $args;
    }

    private function sanitize_dashboard_query_arg( $key, $value ) {
        $raw = wp_unslash( (string) $value );
        switch ( $key ) {
            case 'wbi_top_page':
            case 'wbi_least_page':
            case 'wbi_top_per_page':
            case 'wbi_least_per_page':
                return max( 1, absint( $raw ) );
            case 'wbi_start':
            case 'wbi_end':
            case 'wbi_prev_start':
            case 'wbi_prev_end':
                return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
            case 'statuses':
                $status = sanitize_key( $raw );
                return in_array( $status, $this->allowed_statuses, true ) ? $status : '';
            case 'page':
            case 'wbi_range':
            case 'wbi_compare':
                return sanitize_key( $raw );
            case 'wbi_dashboard_nonce':
                return sanitize_text_field( $raw );
            default:
                return sanitize_key( $raw );
        }
    }
}