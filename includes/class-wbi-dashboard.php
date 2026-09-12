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
    private $allowed_ranges = array( 'today', 'yesterday', '7d', '30d', 'this_month', 'last_month', 'this_year', 'custom' );
    private $allowed_comparisons = array( 'none', 'prev_period', 'prev_year', 'custom_compare' );

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
        $request_args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $notices      = array();

        $custom_range_requested   = isset( $request_args['wbi_range'] ) && 'custom' === WBI_Admin_Query_Helper::get_string( $request_args, 'wbi_range', '' );
        $custom_compare_requested = isset( $request_args['wbi_compare'] ) && 'custom_compare' === WBI_Admin_Query_Helper::get_string( $request_args, 'wbi_compare', '' );
        $custom_range_error       = false;
        $custom_compare_error     = false;
        $submitted_start          = isset( $request_args['wbi_start'] ) && is_scalar( $request_args['wbi_start'] ) ? sanitize_text_field( wp_unslash( (string) $request_args['wbi_start'] ) ) : '';
        $submitted_end            = isset( $request_args['wbi_end'] ) && is_scalar( $request_args['wbi_end'] ) ? sanitize_text_field( wp_unslash( (string) $request_args['wbi_end'] ) ) : '';
        $submitted_prev_start     = isset( $request_args['wbi_prev_start'] ) && is_scalar( $request_args['wbi_prev_start'] ) ? sanitize_text_field( wp_unslash( (string) $request_args['wbi_prev_start'] ) ) : '';
        $submitted_prev_end       = isset( $request_args['wbi_prev_end'] ) && is_scalar( $request_args['wbi_prev_end'] ) ? sanitize_text_field( wp_unslash( (string) $request_args['wbi_prev_end'] ) ) : '';

        // --- 1. LÓGICA DE FECHAS (Restaurada) ---
        $range      = WBI_Admin_Query_Helper::get_enum( $request_args, 'wbi_range', $this->allowed_ranges, '30d' );
        $end_date   = WBI_Admin_Query_Helper::get_site_date_ymd();
        $start_date = WBI_Admin_Query_Helper::get_site_date_ymd( '-30 days' );

        if ( 'custom' === $range ) {
            $custom_range = WBI_Admin_Query_Helper::normalize_date_range_with_meta( $request_args, 'wbi_start', 'wbi_end', $start_date, $end_date );
            if ( $custom_range['has_error'] ) {
                $custom_range_error = true;
                $range              = '30d';
                $notices[]          = array(
                    'type'    => 'warning',
                    'message' => __( 'El rango personalizado no es válido. Se aplicó el período de 30 días.', 'wbi-suite' ),
                );
            } else {
                $start_date = $custom_range['from'];
                $end_date   = $custom_range['to'];
            }
        }

        $today = new DateTimeImmutable( 'now', wp_timezone() );
        switch ( $range ) {
            case 'today':
                $start_date = $today->format( 'Y-m-d' );
                $end_date   = $start_date;
                break;
            case 'yesterday':
                $yesterday  = $today->modify( '-1 day' );
                $start_date = $yesterday->format( 'Y-m-d' );
                $end_date   = $start_date;
                break;
            case '7d':
                $start_date = $today->modify( '-6 days' )->format( 'Y-m-d' );
                $end_date   = $today->format( 'Y-m-d' );
                break;
            case 'this_month':
                $start_date = $today->modify( 'first day of this month' )->format( 'Y-m-d' );
                $end_date   = $today->format( 'Y-m-d' );
                break;
            case 'last_month':
                $last_month = $today->modify( 'first day of last month' );
                $start_date = $last_month->format( 'Y-m-d' );
                $end_date   = $last_month->modify( 'last day of this month' )->format( 'Y-m-d' );
                break;
            case 'this_year':
                $start_date = $today->modify( 'first day of january ' . $today->format( 'Y' ) )->format( 'Y-m-d' );
                $end_date   = $today->format( 'Y-m-d' );
                break;
        }

        // --- 2. COMPARACIÓN DE PERIODO ---
        $compare    = WBI_Admin_Query_Helper::get_enum( $request_args, 'wbi_compare', $this->allowed_comparisons, 'none' );
        $prev_start = '';
        $prev_end   = '';

        if ( 'none' !== $compare ) {
            $start_obj = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
            $end_obj   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, wp_timezone() );
            if ( false !== $start_obj && false !== $end_obj ) {
                $current_diff = (int) $start_obj->diff( $end_obj )->days;
                if ( 'prev_period' === $compare ) {
                    $prev_end_obj   = $start_obj->modify( '-1 day' );
                    $prev_start_obj = $prev_end_obj->modify( '-' . $current_diff . ' days' );
                    $prev_start     = $prev_start_obj->format( 'Y-m-d' );
                    $prev_end       = $prev_end_obj->format( 'Y-m-d' );
                } elseif ( 'prev_year' === $compare ) {
                    $prev_start = $start_obj->modify( '-1 year' )->format( 'Y-m-d' );
                    $prev_end   = $end_obj->modify( '-1 year' )->format( 'Y-m-d' );
                } elseif ( 'custom_compare' === $compare ) {
                    $custom_compare_range = WBI_Admin_Query_Helper::normalize_date_range_with_meta( $request_args, 'wbi_prev_start', 'wbi_prev_end', '', '' );
                    if ( $custom_compare_range['has_error'] || '' === $custom_compare_range['from'] || '' === $custom_compare_range['to'] ) {
                        $custom_compare_error = true;
                        $compare              = 'none';
                        $notices[]            = array(
                            'type'    => 'warning',
                            'message' => __( 'El período de comparación personalizado no es válido y fue desactivado.', 'wbi-suite' ),
                        );
                    } else {
                        $prev_start = $custom_compare_range['from'];
                        $prev_end   = $custom_compare_range['to'];
                    }
                }
            }
        }

        // --- 3. OBTENER DATOS ---
        $default_statuses = array( 'wc-completed', 'wc-processing' );
        $statuses         = WBI_Admin_Query_Helper::get_string_array( $request_args, 'statuses', $this->allowed_statuses );
        if ( empty( $statuses ) ) {
            $statuses = $default_statuses;
        }

        $revenue = $this->engine->get_revenue( $start_date, $end_date, $statuses ) ?: 0;
        $units   = $this->engine->get_units_sold( $start_date, $end_date, $statuses ) ?: 0;

        $status_raw   = $this->engine->get_order_status_counts();
        $c_completed  = $this->get_safe_count( $status_raw, 'wc-completed' );
        $c_processing = $this->get_safe_count( $status_raw, 'wc-processing' );
        $c_hold       = $this->get_safe_count( $status_raw, 'wc-on-hold' );
        $c_pending    = $this->get_safe_count( $status_raw, 'wc-pending' );
        $c_cancelled  = $this->get_safe_count( $status_raw, 'wc-cancelled' );
        $c_failed     = $this->get_safe_count( $status_raw, 'wc-failed' );

        $top_per_page_allowed = array( 5, 10, 25 );
        $best_per_page        = WBI_Admin_Query_Helper::get_absint( $request_args, 'wbi_top_per_page', 5 );
        if ( ! in_array( $best_per_page, $top_per_page_allowed, true ) ) {
            $best_per_page = 5;
        }
        $top_page    = max( 1, WBI_Admin_Query_Helper::get_absint( $request_args, 'wbi_top_page', 1 ) );
        $best_total  = $this->engine->count_best_sellers( $start_date, $end_date, $statuses );
        $least_total = $this->engine->count_least_sold( $start_date, $end_date, $statuses );

        $best_has_rows   = $best_total > 0;
        $best_total_pages = max( 1, (int) ceil( $best_total / $best_per_page ) );
        if ( $top_page > $best_total_pages ) {
            $top_page = $best_total_pages;
        }
        $top_offset      = ( $top_page - 1 ) * $best_per_page;
        $best_sold_page  = $this->engine->get_best_sellers( $start_date, $end_date, $statuses, $best_per_page, $top_offset );
        $best_sold_page  = is_array( $best_sold_page ) ? $best_sold_page : array();

        $least_per_page = WBI_Admin_Query_Helper::get_absint( $request_args, 'wbi_least_per_page', 5 );
        if ( ! in_array( $least_per_page, $top_per_page_allowed, true ) ) {
            $least_per_page = 5;
        }
        $least_page      = max( 1, WBI_Admin_Query_Helper::get_absint( $request_args, 'wbi_least_page', 1 ) );
        $least_has_rows  = $least_total > 0;
        $least_total_pages = max( 1, (int) ceil( $least_total / $least_per_page ) );
        if ( $least_page > $least_total_pages ) {
            $least_page = $least_total_pages;
        }
        $least_offset    = ( $least_page - 1 ) * $least_per_page;
        $least_sold_page = $this->engine->get_least_sold( $start_date, $end_date, $statuses, $least_per_page, $least_offset );
        $least_sold_page = is_array( $least_sold_page ) ? $least_sold_page : array();

        $allowed_query_fields = array(
            'page',
            'wbi_range',
            'wbi_start',
            'wbi_end',
            'wbi_compare',
            'wbi_prev_start',
            'wbi_prev_end',
            'statuses',
            'wbi_top_per_page',
            'wbi_least_per_page',
            'wbi_top_page',
            'wbi_least_page',
        );

        $normalized_query_args = array(
            'page'               => 'wbi-dashboard-view',
            'wbi_range'          => $range,
            'wbi_start'          => 'custom' === $range ? $start_date : '',
            'wbi_end'            => 'custom' === $range ? $end_date : '',
            'wbi_compare'        => $compare,
            'wbi_prev_start'     => 'custom_compare' === $compare ? $prev_start : '',
            'wbi_prev_end'       => 'custom_compare' === $compare ? $prev_end : '',
            'statuses'           => $statuses,
            'wbi_top_per_page'   => $best_per_page,
            'wbi_least_per_page' => $least_per_page,
            'wbi_top_page'       => $top_page,
            'wbi_least_page'     => $least_page,
        );

        $period_data = $this->engine->get_sales_by_period( 'day', $start_date, $end_date, $statuses );

        $current_year   = (int) $today->format( 'Y' );
        $year_start_obj = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%d-01-01', $current_year ), wp_timezone() );
        $year_end_obj   = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%d-12-31', $current_year ), wp_timezone() );
        $year_start     = $year_start_obj ? $year_start_obj->format( 'Y-m-d' ) : sprintf( '%d-01-01', $current_year );
        $year_end       = $year_end_obj ? $year_end_obj->format( 'Y-m-d' ) : sprintf( '%d-12-31', $current_year );
        $monthly_data = $this->engine->get_sales_by_period( 'month', $year_start, $year_end, $statuses );

        $daily_series   = $this->build_daily_chart_series( $period_data, $start_date, $end_date );
        $monthly_series = $this->build_monthly_chart_series( $monthly_data, (int) $today->format( 'Y' ) );

        $range_start_label = $this->format_display_date( $start_date );
        $range_end_label   = $this->format_display_date( $end_date );
        $comparison_label  = $this->get_comparison_label( $compare, $prev_start, $prev_end );
        $range_field_value = $custom_range_requested ? 'custom' : $range;
        $show_custom_range = 'custom' === $range_field_value;
        $compare_field_value = $custom_compare_requested ? 'custom_compare' : $compare;
        $show_custom_compare = 'custom_compare' === $compare_field_value;

        $top5_names = array();
        $top5_qtys  = array();
        $best_sold  = $this->engine->get_best_sellers( $start_date, $end_date, $statuses, 5, 0 );
        if ( ! empty( $best_sold ) ) {
            foreach ( $best_sold as $product ) {
                $top5_names[] = $product->name;
                $top5_qtys[]  = (int) $product->qty;
            }
        }

        $options           = get_option( 'wbi_modules_settings' );
        $has_source_module = ! empty( $options['wbi_enable_data'] );
        $source_labels     = array();
        $source_totals     = array();
        if ( $has_source_module ) {
            $source_data = $this->engine->get_sales_by_source( $start_date, $end_date, $statuses );
            foreach ( $source_data as $row ) {
                $source_labels[] = $row->source ? $row->source : __( 'Sin origen', 'wbi-suite' );
                $source_totals[] = (float) $row->total;
            }
        }

        $prev_revenue = 0;
        $prev_units   = 0;
        $revenue_delta = null;
        $units_delta   = null;
        if ( 'none' !== $compare && $prev_start && $prev_end ) {
            $prev_revenue = $this->engine->get_revenue( $prev_start, $prev_end, $statuses ) ?: 0;
            $prev_units   = $this->engine->get_units_sold( $prev_start, $prev_end, $statuses ) ?: 0;
            $revenue_delta = $this->calc_delta( $revenue, $prev_revenue );
            $units_delta   = $this->calc_delta( $units, $prev_units );
        }

        $status_chart_labels = array(
            __( 'Completados', 'wbi-suite' ),
            __( 'En proceso', 'wbi-suite' ),
            __( 'En espera', 'wbi-suite' ),
            __( 'Pendientes', 'wbi-suite' ),
            __( 'Cancelados', 'wbi-suite' ),
            __( 'Fallidos', 'wbi-suite' ),
        );
        $status_chart_values = array( $c_completed, $c_processing, $c_hold, $c_pending, $c_cancelled, $c_failed );

        $status_cards = array(
            array(
                'label'       => __( 'Completados', 'wbi-suite' ),
                'value'       => $c_completed,
                'accent'      => 'success',
                'description' => __( 'Pedidos entregados o finalizados correctamente.', 'wbi-suite' ),
            ),
            array(
                'label'       => __( 'En proceso', 'wbi-suite' ),
                'value'       => $c_processing,
                'accent'      => 'info',
                'description' => __( 'Pedidos que siguen en preparación o confirmación.', 'wbi-suite' ),
            ),
            array(
                'label'       => __( 'En espera', 'wbi-suite' ),
                'value'       => $c_hold,
                'accent'      => 'warning',
                'description' => __( 'Pedidos detenidos por pago, stock o validación.', 'wbi-suite' ),
            ),
            array(
                'label'       => __( 'Pendientes', 'wbi-suite' ),
                'value'       => $c_pending,
                'accent'      => 'primary',
                'description' => __( 'Pedidos creados que todavía no avanzaron al siguiente estado.', 'wbi-suite' ),
            ),
            array(
                'label'       => __( 'Cancelados o fallidos', 'wbi-suite' ),
                'value'       => $c_cancelled + $c_failed,
                'accent'      => 'danger',
                'description' => __( 'Pedidos que no avanzaron y requieren seguimiento.', 'wbi-suite' ),
            ),
        );

        $monthly_has_data = $this->has_meaningful_values( $monthly_series['values'] );
        $daily_has_data   = $this->has_meaningful_values( $daily_series['values'] );
        $status_has_data  = $this->has_meaningful_values( $status_chart_values );
        $top5_has_data    = $this->has_meaningful_values( $top5_qtys );
        $source_has_data  = $has_source_module && $this->has_meaningful_values( $source_totals );

        $monthly_labels_json      = wp_json_encode( $monthly_series['labels'] );
        $monthly_totals_json      = wp_json_encode( $monthly_series['values'] );
        $daily_labels_json        = wp_json_encode( $daily_series['labels'] );
        $daily_totals_json        = wp_json_encode( $daily_series['values'] );
        $status_labels_json       = wp_json_encode( $status_chart_labels );
        $status_values_json       = wp_json_encode( $status_chart_values );
        $top5_names_json          = wp_json_encode( $top5_names );
        $top5_qtys_json           = wp_json_encode( $top5_qtys );
        $source_labels_json       = wp_json_encode( $source_labels );
        $source_totals_json       = wp_json_encode( $source_totals );
        $status_chart_palette     = wp_json_encode( array( '#059669', '#0284c7', '#d97706', '#4f46e5', '#dc2626', '#8c3130' ) );
        $source_chart_palette     = wp_json_encode( array( '#4f46e5', '#059669', '#0284c7', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#475569' ) );
        $chart_locale_json        = wp_json_encode( str_replace( '_', '-', get_locale() ) );
        $chart_currency_json      = wp_json_encode( get_woocommerce_currency() );
        $chart_decimals_json      = wp_json_encode( wc_get_price_decimals() );

        $best_pagination_base = add_query_arg(
            $this->get_allowed_query_args( $normalized_query_args, $allowed_query_fields, array( 'wbi_top_page' ) ),
            admin_url( 'admin.php' )
        );
        $best_pagination_links = paginate_links(
            array(
                'base'      => esc_url_raw( add_query_arg( 'wbi_top_page', '%#%', $best_pagination_base ) ),
                'format'    => '',
                'current'   => $top_page,
                'total'     => $best_total_pages,
                'prev_text' => __( 'Anterior', 'wbi-suite' ),
                'next_text' => __( 'Siguiente', 'wbi-suite' ),
                'type'      => 'plain',
            )
        );

        $least_pagination_base = add_query_arg(
            $this->get_allowed_query_args( $normalized_query_args, $allowed_query_fields, array( 'wbi_least_page' ) ),
            admin_url( 'admin.php' )
        );
        $least_pagination_links = paginate_links(
            array(
                'base'      => esc_url_raw( add_query_arg( 'wbi_least_page', '%#%', $least_pagination_base ) ),
                'format'    => '',
                'current'   => $least_page,
                'total'     => $least_total_pages,
                'prev_text' => __( 'Anterior', 'wbi-suite' ),
                'next_text' => __( 'Siguiente', 'wbi-suite' ),
                'type'      => 'plain',
            )
        );

        WBI_Admin_Shell::open_page( 'wbi-dashboard-page' );
        foreach ( $notices as $notice ) {
            WBI_Admin_Shell::render_notice( $notice['message'], $notice['type'] );
        }

        WBI_Admin_Shell::render_header(
            array(
                'title'       => __( 'Dashboard ejecutivo', 'wbi-suite' ),
                'description' => __( 'Resumen comercial y operativo con filtros compartibles por período, estado y comparación.', 'wbi-suite' ),
                'actions'     => array(
                    array(
                        'url'   => admin_url( 'admin.php?page=wbi-dashboard-view' ),
                        'label' => __( 'Restablecer filtros', 'wbi-suite' ),
                        'class' => 'wbi-btn wbi-btn-link',
                    ),
                ),
            )
        );
        ?>

        <form method="get" class="wbi-filter-panel" aria-label="<?php echo esc_attr__( 'Filtros del dashboard ejecutivo', 'wbi-suite' ); ?>">
            <input type="hidden" name="page" value="wbi-dashboard-view" />
            <div class="wbi-filter-grid">
                <div class="wbi-filter-field wbi-col-2">
                    <label for="wbi_range"><?php esc_html_e( 'Período', 'wbi-suite' ); ?></label>
                    <select name="wbi_range" id="wbi_range" data-wbi-toggle-target="wbi_custom_dates" data-wbi-toggle-value="custom" <?php if ( $custom_range_requested && $custom_range_error ) : ?>aria-describedby="wbi_range_feedback"<?php endif; ?>>
                        <option value="today" <?php selected( $range_field_value, 'today' ); ?>><?php esc_html_e( 'Hoy', 'wbi-suite' ); ?></option>
                        <option value="yesterday" <?php selected( $range_field_value, 'yesterday' ); ?>><?php esc_html_e( 'Ayer', 'wbi-suite' ); ?></option>
                        <option value="7d" <?php selected( $range_field_value, '7d' ); ?>><?php esc_html_e( 'Últimos 7 días', 'wbi-suite' ); ?></option>
                        <option value="30d" <?php selected( $range_field_value, '30d' ); ?>><?php esc_html_e( 'Últimos 30 días', 'wbi-suite' ); ?></option>
                        <option value="this_month" <?php selected( $range_field_value, 'this_month' ); ?>><?php esc_html_e( 'Este mes', 'wbi-suite' ); ?></option>
                        <option value="last_month" <?php selected( $range_field_value, 'last_month' ); ?>><?php esc_html_e( 'Mes pasado', 'wbi-suite' ); ?></option>
                        <option value="this_year" <?php selected( $range_field_value, 'this_year' ); ?>><?php esc_html_e( 'Este año', 'wbi-suite' ); ?></option>
                        <option value="custom" <?php selected( $range_field_value, 'custom' ); ?>><?php esc_html_e( 'Rango personalizado', 'wbi-suite' ); ?></option>
                    </select>
                    <p class="wbi-field-help<?php echo $custom_range_requested && $custom_range_error ? ' wbi-field-help-error' : ''; ?>" id="wbi_range_feedback">
                        <?php
                        echo esc_html(
                            $custom_range_requested && $custom_range_error
                                ? __( 'Ingresá un rango válido con fecha inicial y final; si no, se vuelve al período de 30 días.', 'wbi-suite' )
                                : __( 'Elegí un rango predefinido o habilitá fechas personalizadas.', 'wbi-suite' )
                        );
                        ?>
                    </p>
                </div>

                <div class="wbi-filter-field wbi-col-4 wbi-dashboard-conditional-field<?php echo $show_custom_range ? '' : ' wbi-is-hidden'; ?>" id="wbi_custom_dates" aria-hidden="<?php echo $show_custom_range ? 'false' : 'true'; ?>">
                    <span class="wbi-filter-label"><?php esc_html_e( 'Fechas del período', 'wbi-suite' ); ?></span>
                    <div class="wbi-dashboard-date-grid">
                        <div class="wbi-dashboard-date-field">
                            <label for="wbi_start"><?php esc_html_e( 'Desde', 'wbi-suite' ); ?></label>
                            <input id="wbi_start" type="date" name="wbi_start" value="<?php echo esc_attr( $custom_range_requested ? $submitted_start : $start_date ); ?>" <?php disabled( ! $show_custom_range ); ?> />
                        </div>
                        <div class="wbi-dashboard-date-field">
                            <label for="wbi_end"><?php esc_html_e( 'Hasta', 'wbi-suite' ); ?></label>
                            <input id="wbi_end" type="date" name="wbi_end" value="<?php echo esc_attr( $custom_range_requested ? $submitted_end : $end_date ); ?>" <?php disabled( ! $show_custom_range ); ?> />
                        </div>
                    </div>
                </div>

                <div class="wbi-filter-field wbi-col-3">
                    <label for="wbi_compare"><?php esc_html_e( 'Comparación', 'wbi-suite' ); ?></label>
                    <select name="wbi_compare" id="wbi_compare" data-wbi-toggle-target="wbi_compare_dates" data-wbi-toggle-value="custom_compare" <?php if ( $custom_compare_requested && $custom_compare_error ) : ?>aria-describedby="wbi_compare_feedback"<?php endif; ?>>
                        <option value="none" <?php selected( $compare_field_value, 'none' ); ?>><?php esc_html_e( 'Sin comparación', 'wbi-suite' ); ?></option>
                        <option value="prev_period" <?php selected( $compare_field_value, 'prev_period' ); ?>><?php esc_html_e( 'Período anterior', 'wbi-suite' ); ?></option>
                        <option value="prev_year" <?php selected( $compare_field_value, 'prev_year' ); ?>><?php esc_html_e( 'Mismo período del año anterior', 'wbi-suite' ); ?></option>
                        <option value="custom_compare" <?php selected( $compare_field_value, 'custom_compare' ); ?>><?php esc_html_e( 'Comparación personalizada', 'wbi-suite' ); ?></option>
                    </select>
                    <p class="wbi-field-help<?php echo $custom_compare_requested && $custom_compare_error ? ' wbi-field-help-error' : ''; ?>" id="wbi_compare_feedback">
                        <?php
                        echo esc_html(
                            $custom_compare_requested && $custom_compare_error
                                ? __( 'La comparación personalizada necesita dos fechas válidas; si falla, se desactiva.', 'wbi-suite' )
                                : __( 'Compará el período actual con un período anterior o con un rango personalizado.', 'wbi-suite' )
                        );
                        ?>
                    </p>
                </div>

                <div class="wbi-filter-field wbi-col-3 wbi-dashboard-conditional-field<?php echo $show_custom_compare ? '' : ' wbi-is-hidden'; ?>" id="wbi_compare_dates" aria-hidden="<?php echo $show_custom_compare ? 'false' : 'true'; ?>">
                    <span class="wbi-filter-label"><?php esc_html_e( 'Fechas de comparación', 'wbi-suite' ); ?></span>
                    <div class="wbi-dashboard-date-grid">
                        <div class="wbi-dashboard-date-field">
                            <label for="wbi_prev_start"><?php esc_html_e( 'Desde', 'wbi-suite' ); ?></label>
                            <input id="wbi_prev_start" type="date" name="wbi_prev_start" value="<?php echo esc_attr( $custom_compare_requested ? $submitted_prev_start : $prev_start ); ?>" <?php disabled( ! $show_custom_compare ); ?> />
                        </div>
                        <div class="wbi-dashboard-date-field">
                            <label for="wbi_prev_end"><?php esc_html_e( 'Hasta', 'wbi-suite' ); ?></label>
                            <input id="wbi_prev_end" type="date" name="wbi_prev_end" value="<?php echo esc_attr( $custom_compare_requested ? $submitted_prev_end : $prev_end ); ?>" <?php disabled( ! $show_custom_compare ); ?> />
                        </div>
                    </div>
                </div>

                <div class="wbi-filter-field wbi-col-3">
                    <label for="wbi_statuses"><?php esc_html_e( 'Estados del pedido', 'wbi-suite' ); ?></label>
                    <select id="wbi_statuses" name="statuses[]" multiple size="4" aria-describedby="wbi_statuses_help">
                        <option value="wc-completed" <?php echo in_array( 'wc-completed', $statuses, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'Completado', 'wbi-suite' ); ?></option>
                        <option value="wc-processing" <?php echo in_array( 'wc-processing', $statuses, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'En proceso', 'wbi-suite' ); ?></option>
                        <option value="wc-on-hold" <?php echo in_array( 'wc-on-hold', $statuses, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'En espera', 'wbi-suite' ); ?></option>
                        <option value="wc-pending" <?php echo in_array( 'wc-pending', $statuses, true ) ? 'selected' : ''; ?>><?php esc_html_e( 'Pendiente', 'wbi-suite' ); ?></option>
                    </select>
                    <p class="wbi-field-help" id="wbi_statuses_help"><?php esc_html_e( 'Usá Ctrl o Cmd para seleccionar varios estados sin perder los demás filtros.', 'wbi-suite' ); ?></p>
                </div>

                <div class="wbi-filter-field wbi-col-3">
                    <div class="wbi-filter-actions wbi-dashboard-filter-actions">
                        <button type="submit" class="wbi-btn wbi-btn-primary"><?php esc_html_e( 'Aplicar filtros', 'wbi-suite' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-dashboard-view' ) ); ?>" class="wbi-btn"><?php esc_html_e( 'Limpiar', 'wbi-suite' ); ?></a>
                    </div>
                </div>
            </div>
        </form>

        <section aria-labelledby="wbi-dashboard-overview">
            <h2 class="wbi-section-title" id="wbi-dashboard-overview"><?php esc_html_e( 'Estado global de pedidos', 'wbi-suite' ); ?></h2>
            <div class="wbi-grid-4">
                <?php foreach ( $status_cards as $card ) : ?>
                    <article class="wbi-stat-card wbi-dashboard-stat-card wbi-dashboard-stat-card--<?php echo esc_attr( $card['accent'] ); ?>">
                        <p class="wbi-label"><?php echo esc_html( $card['label'] ); ?></p>
                        <div class="wbi-number"><?php echo esc_html( number_format_i18n( (int) $card['value'] ) ); ?></div>
                        <p class="wbi-compare-value"><?php echo esc_html( $card['description'] ); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section aria-labelledby="wbi-dashboard-kpis">
            <h2 class="wbi-section-title" id="wbi-dashboard-kpis"><?php echo esc_html( sprintf( __( 'Rendimiento del %1$s al %2$s', 'wbi-suite' ), $range_start_label, $range_end_label ) ); ?></h2>
            <div class="wbi-grid-2">
                <article class="wbi-stat-card wbi-dashboard-stat-card wbi-dashboard-stat-card--primary">
                    <p class="wbi-label"><?php esc_html_e( 'Facturación del período', 'wbi-suite' ); ?></p>
                    <div class="wbi-number"><?php echo wp_kses_post( wc_price( $revenue ) ); ?></div>
                    <p class="wbi-compare-value"><?php esc_html_e( 'Suma total de pedidos incluidos por los filtros actuales.', 'wbi-suite' ); ?></p>
                    <?php if ( 'none' !== $compare && $prev_start && $prev_end ) : ?>
                        <div class="wbi-compare-value wbi-dashboard-compare-row">
                            <span><?php echo esc_html( $comparison_label ); ?>:</span>
                            <strong><?php echo esc_html( $this->get_plain_price( $prev_revenue ) ); ?></strong>
                            <?php if ( null === $revenue_delta ) : ?>
                                <span class="wbi-field-help"><?php esc_html_e( 'Sin delta porcentual porque el período comparado fue 0.', 'wbi-suite' ); ?></span>
                            <?php else : ?>
                                <span aria-hidden="true">·</span>
                                <?php echo wp_kses_post( $this->render_delta( $revenue_delta ) ); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="wbi-stat-card wbi-dashboard-stat-card wbi-dashboard-stat-card--indigo">
                    <p class="wbi-label"><?php esc_html_e( 'Unidades vendidas', 'wbi-suite' ); ?></p>
                    <div class="wbi-number"><?php echo esc_html( number_format_i18n( (int) $units ) ); ?></div>
                    <p class="wbi-compare-value"><?php esc_html_e( 'Cantidad total de unidades vendidas dentro del rango filtrado.', 'wbi-suite' ); ?></p>
                    <?php if ( 'none' !== $compare && $prev_start && $prev_end ) : ?>
                        <div class="wbi-compare-value wbi-dashboard-compare-row">
                            <span><?php echo esc_html( $comparison_label ); ?>:</span>
                            <strong><?php echo esc_html( number_format_i18n( (int) $prev_units ) ); ?></strong>
                            <?php if ( null === $units_delta ) : ?>
                                <span class="wbi-field-help"><?php esc_html_e( 'Sin delta porcentual porque el período comparado fue 0.', 'wbi-suite' ); ?></span>
                            <?php else : ?>
                                <span aria-hidden="true">·</span>
                                <?php echo wp_kses_post( $this->render_delta( $units_delta ) ); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section aria-labelledby="wbi-dashboard-charts">
            <h2 class="wbi-section-title" id="wbi-dashboard-charts"><?php esc_html_e( 'Gráficos y resúmenes accesibles', 'wbi-suite' ); ?></h2>
            <div class="wbi-grid-2">
                <article class="wbi-card wbi-dashboard-chart-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php echo esc_html( sprintf( __( 'Tendencia mensual %s', 'wbi-suite' ), $today->format( 'Y' ) ) ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Distribuye la facturación del año actual mes por mes con meses sin ventas incluidos en cero.', 'wbi-suite' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $monthly_has_data ) : ?>
                        <p class="wbi-dashboard-chart-summary" id="wbi-monthly-chart-summary"><?php esc_html_e( 'El gráfico conserva los doce meses del año para evitar ejes engañosos cuando hay pocos puntos de datos.', 'wbi-suite' ); ?></p>
                        <div class="wbi-chart-container wbi-chart-container-tall">
                            <canvas id="wbiMonthlyChart" aria-describedby="wbi-monthly-chart-summary"></canvas>
                        </div>
                        <div class="wbi-state wbi-state-error wbi-dashboard-chart-error wbi-is-hidden"><?php esc_html_e( 'No se pudo cargar el gráfico interactivo. Revisá la tabla de datos.', 'wbi-suite' ); ?></div>
                        <details class="wbi-dashboard-chart-details">
                            <summary><?php esc_html_e( 'Ver tabla de datos mensual', 'wbi-suite' ); ?></summary>
                            <?php
                            $this->render_chart_table(
                                array( __( 'Mes', 'wbi-suite' ), __( 'Facturación', 'wbi-suite' ) ),
                                array_map(
                                    array( $this, 'format_chart_row' ),
                                    $monthly_series['table_labels'],
                                    $monthly_series['values'],
                                    array_fill( 0, count( $monthly_series['values'] ), 'currency' )
                                ),
                                array( 1 )
                            );
                            ?>
                        </details>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin datos para el gráfico.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'No hay facturación registrada durante el año actual para los estados seleccionados.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="wbi-card wbi-dashboard-chart-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Distribución de pedidos por estado', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Muestra el volumen acumulado por estado operativo con alternativa tabular accesible.', 'wbi-suite' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $status_has_data ) : ?>
                        <p class="wbi-dashboard-chart-summary" id="wbi-status-chart-summary"><?php esc_html_e( 'Cada segmento representa una cantidad de pedidos; la leyenda visible y la tabla asociada evitan depender solo del color.', 'wbi-suite' ); ?></p>
                        <div class="wbi-chart-container wbi-chart-container-compact">
                            <canvas id="wbiStatusChart" aria-describedby="wbi-status-chart-summary"></canvas>
                        </div>
                        <div class="wbi-state wbi-state-error wbi-dashboard-chart-error wbi-is-hidden"><?php esc_html_e( 'No se pudo cargar el gráfico interactivo. Revisá la tabla de estados.', 'wbi-suite' ); ?></div>
                        <details class="wbi-dashboard-chart-details">
                            <summary><?php esc_html_e( 'Ver tabla de estados', 'wbi-suite' ); ?></summary>
                            <?php
                            $this->render_chart_table(
                                array( __( 'Estado', 'wbi-suite' ), __( 'Pedidos', 'wbi-suite' ) ),
                                array_map(
                                    array( $this, 'format_chart_row' ),
                                    $status_chart_labels,
                                    $status_chart_values,
                                    array_fill( 0, count( $status_chart_values ), 'number' )
                                ),
                                array( 1 )
                            );
                            ?>
                        </details>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin pedidos para mostrar.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'No se encontraron pedidos acumulados en los estados representados.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="wbi-grid-2">
                <article class="wbi-card wbi-dashboard-chart-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Facturación diaria', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Serie diaria completa del período filtrado, incluyendo días sin ventas para mantener la escala estable.', 'wbi-suite' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $daily_has_data ) : ?>
                        <p class="wbi-dashboard-chart-summary" id="wbi-daily-chart-summary"><?php echo esc_html( sprintf( __( 'Período analizado: del %1$s al %2$s.', 'wbi-suite' ), $range_start_label, $range_end_label ) ); ?></p>
                        <div class="wbi-chart-container wbi-chart-container-tall">
                            <canvas id="wbiRevenueChart" aria-describedby="wbi-daily-chart-summary"></canvas>
                        </div>
                        <div class="wbi-state wbi-state-error wbi-dashboard-chart-error wbi-is-hidden"><?php esc_html_e( 'No se pudo cargar el gráfico interactivo. Revisá la tabla diaria.', 'wbi-suite' ); ?></div>
                        <details class="wbi-dashboard-chart-details">
                            <summary><?php esc_html_e( 'Ver tabla diaria', 'wbi-suite' ); ?></summary>
                            <?php
                            $this->render_chart_table(
                                array( __( 'Fecha', 'wbi-suite' ), __( 'Facturación', 'wbi-suite' ) ),
                                array_map(
                                    array( $this, 'format_chart_row' ),
                                    $daily_series['table_labels'],
                                    $daily_series['values'],
                                    array_fill( 0, count( $daily_series['values'] ), 'currency' )
                                ),
                                array( 1 )
                            );
                            ?>
                        </details>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin facturación para el período elegido.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'No hubo ventas que permitan construir una serie diaria con significado.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="wbi-card wbi-dashboard-chart-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Top productos', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Ranking resumido de unidades vendidas durante el período seleccionado.', 'wbi-suite' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $top5_has_data ) : ?>
                        <p class="wbi-dashboard-chart-summary" id="wbi-top-products-chart-summary"><?php esc_html_e( 'El gráfico horizontal limita la comparación a los cinco productos con mayor volumen para preservar la legibilidad.', 'wbi-suite' ); ?></p>
                        <div class="wbi-chart-container wbi-chart-container-tall">
                            <canvas id="wbiTopProductsChart" aria-describedby="wbi-top-products-chart-summary"></canvas>
                        </div>
                        <div class="wbi-state wbi-state-error wbi-dashboard-chart-error wbi-is-hidden"><?php esc_html_e( 'No se pudo cargar el gráfico interactivo. Revisá la tabla de productos.', 'wbi-suite' ); ?></div>
                        <details class="wbi-dashboard-chart-details">
                            <summary><?php esc_html_e( 'Ver tabla del top de productos', 'wbi-suite' ); ?></summary>
                            <?php
                            $this->render_chart_table(
                                array( __( 'Producto', 'wbi-suite' ), __( 'Unidades', 'wbi-suite' ) ),
                                array_map(
                                    array( $this, 'format_chart_row' ),
                                    $top5_names,
                                    $top5_qtys,
                                    array_fill( 0, count( $top5_qtys ), 'number' )
                                ),
                                array( 1 )
                            );
                            ?>
                        </details>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin productos vendidos.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'No hay unidades vendidas suficientes para construir el ranking visual.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="wbi-grid-2">
                <article class="wbi-card wbi-dashboard-chart-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Ventas por origen', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Disponible cuando el módulo de datos y trazabilidad de origen está habilitado.', 'wbi-suite' ); ?></p>
                        </div>
                    </div>
                    <?php if ( ! $has_source_module ) : ?>
                        <div class="wbi-state wbi-state-disabled">
                            <strong><?php esc_html_e( 'Módulo deshabilitado.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'Activá el módulo de datos para ver la distribución de ventas por origen sin cambiar el resto del dashboard.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php elseif ( $source_has_data ) : ?>
                        <p class="wbi-dashboard-chart-summary" id="wbi-source-chart-summary"><?php esc_html_e( 'Cada origen mantiene su total de ventas y cuenta con una tabla de respaldo para revisión manual o lectura asistida.', 'wbi-suite' ); ?></p>
                        <div class="wbi-chart-container wbi-chart-container-compact">
                            <canvas id="wbiSourceChart" aria-describedby="wbi-source-chart-summary"></canvas>
                        </div>
                        <div class="wbi-state wbi-state-error wbi-dashboard-chart-error wbi-is-hidden"><?php esc_html_e( 'No se pudo cargar el gráfico interactivo. Revisá la tabla por origen.', 'wbi-suite' ); ?></div>
                        <details class="wbi-dashboard-chart-details">
                            <summary><?php esc_html_e( 'Ver tabla por origen', 'wbi-suite' ); ?></summary>
                            <?php
                            $this->render_chart_table(
                                array( __( 'Origen', 'wbi-suite' ), __( 'Facturación', 'wbi-suite' ) ),
                                array_map(
                                    array( $this, 'format_chart_row' ),
                                    $source_labels,
                                    $source_totals,
                                    array_fill( 0, count( $source_totals ), 'currency' )
                                ),
                                array( 1 )
                            );
                            ?>
                        </details>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin origen de ventas disponible.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'No se encontraron ventas con metadatos de origen para el período filtrado.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section aria-labelledby="wbi-dashboard-rankings">
            <h2 class="wbi-section-title" id="wbi-dashboard-rankings"><?php esc_html_e( 'Ranking de productos', 'wbi-suite' ); ?></h2>
            <div class="wbi-grid-2">
                <article class="wbi-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Productos más vendidos', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Ranking SQL paginado con filtros compartidos e independiente del ranking inferior.', 'wbi-suite' ); ?></p>
                        </div>
                        <form method="get" class="wbi-filter-actions wbi-dashboard-inline-form">
                            <?php $this->render_hidden_query_fields( $normalized_query_args, $allowed_query_fields, array( 'wbi_top_per_page', 'wbi_top_page', 'wbi_least_page' ) ); ?>
                            <label for="wbi_top_per_page_best"><?php esc_html_e( 'Filas por página', 'wbi-suite' ); ?></label>
                            <select id="wbi_top_per_page_best" name="wbi_top_per_page" onchange="this.form.submit()">
                                <?php foreach ( $top_per_page_allowed as $size ) : ?>
                                    <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $best_per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if ( $best_has_rows ) : ?>
                        <div class="wbi-table-responsive">
                            <table class="wbi-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Producto', 'wbi-suite' ); ?></th>
                                        <th scope="col" data-align="right"><?php esc_html_e( 'Unidades', 'wbi-suite' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $best_sold_page as $product ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $product->name ); ?></td>
                                            <td data-align="right"><strong><?php echo esc_html( number_format_i18n( (int) $product->qty ) ); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php WBI_Admin_Shell::render_pagination( $best_pagination_links, sprintf( __( 'Página %1$d de %2$d · Total de productos: %3$d', 'wbi-suite' ), (int) $top_page, (int) $best_total_pages, (int) $best_total ) ); ?>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin ventas en este período.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'Ajustá el rango o los estados seleccionados para ver productos vendidos.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="wbi-card">
                    <div class="wbi-card-header">
                        <div>
                            <h3 class="wbi-card-title"><?php esc_html_e( 'Productos con menos movimiento', 'wbi-suite' ); ?></h3>
                            <p class="wbi-card-subtitle"><?php esc_html_e( 'Misma base de filtros y paginación independiente para detectar baja rotación.', 'wbi-suite' ); ?></p>
                        </div>
                        <form method="get" class="wbi-filter-actions wbi-dashboard-inline-form">
                            <?php $this->render_hidden_query_fields( $normalized_query_args, $allowed_query_fields, array( 'wbi_least_per_page', 'wbi_least_page', 'wbi_top_page' ) ); ?>
                            <label for="wbi_least_per_page"><?php esc_html_e( 'Filas por página', 'wbi-suite' ); ?></label>
                            <select id="wbi_least_per_page" name="wbi_least_per_page" onchange="this.form.submit()">
                                <?php foreach ( $top_per_page_allowed as $size ) : ?>
                                    <option value="<?php echo esc_attr( $size ); ?>" <?php selected( $least_per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if ( $least_has_rows ) : ?>
                        <div class="wbi-table-responsive">
                            <table class="wbi-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Producto', 'wbi-suite' ); ?></th>
                                        <th scope="col" data-align="right"><?php esc_html_e( 'Unidades', 'wbi-suite' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $least_sold_page as $product ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $product->name ); ?></td>
                                            <td data-align="right"><strong><?php echo esc_html( number_format_i18n( (int) $product->qty ) ); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php WBI_Admin_Shell::render_pagination( $least_pagination_links, sprintf( __( 'Página %1$d de %2$d · Total de productos: %3$d', 'wbi-suite' ), (int) $least_page, (int) $least_total_pages, (int) $least_total ) ); ?>
                    <?php else : ?>
                        <div class="wbi-state wbi-state-empty">
                            <strong><?php esc_html_e( 'Sin datos para este ranking.', 'wbi-suite' ); ?></strong>
                            <p><?php esc_html_e( 'Todavía no hay productos con movimiento suficiente dentro del rango elegido.', 'wbi-suite' ); ?></p>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <script>
        (function() {
            var toggleFields = document.querySelectorAll('[data-wbi-toggle-target]');
            toggleFields.forEach(function(field) {
                var targetId = field.getAttribute('data-wbi-toggle-target');
                var target = document.getElementById(targetId);
                if (!target) {
                    return;
                }

                var syncTarget = function() {
                    var shouldShow = field.value === field.getAttribute('data-wbi-toggle-value');
                    target.classList.toggle('wbi-is-hidden', !shouldShow);
                    target.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
                    target.querySelectorAll('input').forEach(function(input) {
                        input.disabled = !shouldShow;
                    });
                };

                field.addEventListener('change', syncTarget);
                syncTarget();
            });

            if (typeof window.Chart === 'undefined') {
                document.querySelectorAll('.wbi-dashboard-chart-error').forEach(function(node) {
                    node.classList.remove('wbi-is-hidden');
                });
                document.querySelectorAll('.wbi-dashboard-chart-details').forEach(function(node) {
                    node.setAttribute('open', 'open');
                });
                return;
            }

            var chartLocale = <?php echo $chart_locale_json; ?> || undefined;
            var chartCurrency = <?php echo $chart_currency_json; ?> || 'USD';
            var chartDecimals = <?php echo $chart_decimals_json; ?>;
            var currencyFormatter = new Intl.NumberFormat(chartLocale, {
                style: 'currency',
                currency: chartCurrency,
                minimumFractionDigits: chartDecimals,
                maximumFractionDigits: chartDecimals
            });

            var numberFormatter = new Intl.NumberFormat(chartLocale);

            function hasMeaningfulValues(values) {
                return Array.isArray(values) && values.some(function(value) {
                    return Number(value) > 0;
                });
            }

            function getSuggestedMax(values) {
                var maxValue = Math.max.apply(null, values.concat([0]));
                return maxValue > 0 ? Math.ceil(maxValue * 1.1) : 1;
            }

            function formatCurrency(value) {
                return currencyFormatter.format(Number(value || 0));
            }

            function formatNumber(value) {
                return numberFormatter.format(Number(value || 0));
            }

            function lineOrBarScales(values, isCurrency, horizontal) {
                var numericAxis = {
                    beginAtZero: true,
                    suggestedMax: getSuggestedMax(values),
                    ticks: isCurrency ? {
                        callback: function(value) {
                            return formatCurrency(value);
                        }
                    } : {
                        precision: 0,
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                };

                var categoryAxis = {
                    ticks: {
                        autoSkip: true,
                        maxTicksLimit: horizontal ? 12 : 8
                    },
                    grid: {
                        display: !horizontal
                    }
                };

                return horizontal ? { x: numericAxis, y: categoryAxis } : { x: categoryAxis, y: numericAxis };
            }

            function createChart(config) {
                if (!config.canvas || !hasMeaningfulValues(config.values)) {
                    return;
                }

                var isCircular = config.type === 'pie' || config.type === 'doughnut';
                var tooltipFormatter = config.isCurrency ? formatCurrency : formatNumber;
                var options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: config.indexAxis || 'x',
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: config.showLegend,
                            position: config.legendPosition || 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var parsedValue = isCircular
                                        ? context.parsed
                                        : context.parsed[config.indexAxis === 'y' ? 'x' : 'y'];
                                    return config.datasetLabel + ': ' + tooltipFormatter(parsedValue);
                                }
                            }
                        }
                    }
                };

                if (!isCircular) {
                    options.scales = config.scales || lineOrBarScales(config.values, config.isCurrency, config.indexAxis === 'y');
                }

                new Chart(config.canvas, {
                    type: config.type,
                    data: {
                        labels: config.labels,
                        datasets: [{
                            label: config.datasetLabel,
                            data: config.values,
                            backgroundColor: config.backgroundColor,
                            borderColor: config.borderColor,
                            borderWidth: config.borderWidth || 1,
                            fill: !!config.fill,
                            tension: config.tension || 0,
                            pointRadius: config.pointRadius || 3,
                            pointHoverRadius: config.pointHoverRadius || 5,
                            pointBackgroundColor: config.pointBackgroundColor || config.borderColor
                        }]
                    },
                    options: options
                });
            }

            createChart({
                canvas: document.getElementById('wbiMonthlyChart'),
                type: 'line',
                labels: <?php echo $monthly_labels_json; ?>,
                values: <?php echo $monthly_totals_json; ?>,
                datasetLabel: '<?php echo esc_js( __( 'Facturación mensual', 'wbi-suite' ) ); ?>',
                backgroundColor: 'rgba(79, 70, 229, 0.12)',
                borderColor: '#4f46e5',
                fill: true,
                tension: 0.25,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                isCurrency: true,
                showLegend: false
            });

            createChart({
                canvas: document.getElementById('wbiRevenueChart'),
                type: 'bar',
                labels: <?php echo $daily_labels_json; ?>,
                values: <?php echo $daily_totals_json; ?>,
                datasetLabel: '<?php echo esc_js( __( 'Facturación diaria', 'wbi-suite' ) ); ?>',
                backgroundColor: 'rgba(2, 132, 199, 0.75)',
                borderColor: '#0284c7',
                borderWidth: 1,
                isCurrency: true,
                showLegend: false
            });

            createChart({
                canvas: document.getElementById('wbiTopProductsChart'),
                type: 'bar',
                labels: <?php echo $top5_names_json; ?>,
                values: <?php echo $top5_qtys_json; ?>,
                datasetLabel: '<?php echo esc_js( __( 'Unidades vendidas', 'wbi-suite' ) ); ?>',
                backgroundColor: 'rgba(5, 150, 105, 0.75)',
                borderColor: '#059669',
                borderWidth: 1,
                indexAxis: 'y',
                isCurrency: false,
                showLegend: false
            });

            createChart({
                canvas: document.getElementById('wbiStatusChart'),
                type: 'doughnut',
                labels: <?php echo $status_labels_json; ?>,
                values: <?php echo $status_values_json; ?>,
                datasetLabel: '<?php echo esc_js( __( 'Pedidos', 'wbi-suite' ) ); ?>',
                backgroundColor: <?php echo $status_chart_palette; ?>,
                borderColor: '#ffffff',
                borderWidth: 2,
                isCurrency: false,
                showLegend: true,
                legendPosition: 'bottom'
            });

            <?php if ( $has_source_module ) : ?>
            createChart({
                canvas: document.getElementById('wbiSourceChart'),
                type: 'pie',
                labels: <?php echo $source_labels_json; ?>,
                values: <?php echo $source_totals_json; ?>,
                datasetLabel: '<?php echo esc_js( __( 'Facturación por origen', 'wbi-suite' ) ); ?>',
                backgroundColor: <?php echo $source_chart_palette; ?>,
                borderColor: '#ffffff',
                borderWidth: 2,
                isCurrency: true,
                showLegend: true,
                legendPosition: 'bottom'
            });
            <?php endif; ?>
        })();
        </script>

        <?php
        WBI_Admin_Shell::close_page();
    }

    private function format_display_date( $date_string ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_string, wp_timezone() );
        return $date ? $date->format( 'd/m/Y' ) : (string) $date_string;
    }

    private function build_daily_chart_series( $rows, $start_date, $end_date ) {
        $totals_by_day = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row->period ) ) {
                continue;
            }
            $totals_by_day[ (string) $row->period ] = isset( $row->total ) ? (float) $row->total : 0.0;
        }

        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
        $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, wp_timezone() );
        if ( false === $start || false === $end ) {
            return array(
                'labels'       => array_keys( $totals_by_day ),
                'table_labels' => array_keys( $totals_by_day ),
                'values'       => array_values( $totals_by_day ),
            );
        }

        $show_year = $start->format( 'Y' ) !== $end->format( 'Y' );
        $labels    = array();
        $table     = array();
        $values    = array();
        for ( $cursor = $start; $cursor <= $end; $cursor = $cursor->modify( '+1 day' ) ) {
            $key      = $cursor->format( 'Y-m-d' );
            $labels[] = $cursor->format( $show_year ? 'd/m/y' : 'd/m' );
            $table[]  = $cursor->format( 'd/m/Y' );
            $values[] = isset( $totals_by_day[ $key ] ) ? (float) $totals_by_day[ $key ] : 0.0;
        }

        return array(
            'labels'       => $labels,
            'table_labels' => $table,
            'values'       => $values,
        );
    }

    private function build_monthly_chart_series( $rows, $year ) {
        $totals_by_month = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row->period ) ) {
                continue;
            }
            $month_key = $this->normalize_month_period_key( (string) $row->period );
            if ( '' === $month_key ) {
                continue;
            }
            $totals_by_month[ $month_key ] = isset( $row->total ) ? (float) $row->total : 0.0;
        }

        $labels = array();
        $table  = array();
        $values = array();
        for ( $month = 1; $month <= 12; $month++ ) {
            $date = DateTimeImmutable::createFromFormat( '!Y-n-j', $year . '-' . $month . '-1', wp_timezone() );
            if ( false === $date ) {
                continue;
            }
            $key      = $date->format( 'Y-m' );
            $labels[] = wp_date( 'M', $date->getTimestamp(), wp_timezone() );
            $table[]  = wp_date( 'F Y', $date->getTimestamp(), wp_timezone() );
            $values[] = isset( $totals_by_month[ $key ] ) ? (float) $totals_by_month[ $key ] : 0.0;
        }

        return array(
            'labels'       => $labels,
            'table_labels' => $table,
            'values'       => $values,
        );
    }

    private function normalize_month_period_key( $period ) {
        $period = trim( (string) $period );
        if ( preg_match( '/^(\\d{4}-\\d{2})/', $period, $matches ) ) {
            return $matches[1];
        }

        $timestamp = strtotime( $period );
        if ( false !== $timestamp ) {
            return gmdate( 'Y-m', $timestamp );
        }

        return '';
    }

    private function get_comparison_label( $compare, $prev_start, $prev_end ) {
        switch ( $compare ) {
            case 'prev_period':
                return sprintf( __( 'Período anterior (%1$s al %2$s)', 'wbi-suite' ), $this->format_display_date( $prev_start ), $this->format_display_date( $prev_end ) );
            case 'prev_year':
                return sprintf( __( 'Mismo período del año anterior (%1$s al %2$s)', 'wbi-suite' ), $this->format_display_date( $prev_start ), $this->format_display_date( $prev_end ) );
            case 'custom_compare':
                return sprintf( __( 'Comparación personalizada (%1$s al %2$s)', 'wbi-suite' ), $this->format_display_date( $prev_start ), $this->format_display_date( $prev_end ) );
            default:
                return __( 'Comparación', 'wbi-suite' );
        }
    }

    private function has_meaningful_values( $values ) {
        foreach ( (array) $values as $value ) {
            if ( abs( (float) $value ) > 0.00001 ) {
                return true;
            }
        }
        return false;
    }

    private function get_plain_price( $amount ) {
        $plain_price = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        $plain_price = preg_replace( '/\s+/u', ' ', $plain_price );

        return trim( (string) $plain_price );
    }

    private function format_chart_row( $label, $value, $type ) {
        if ( 'currency' === $type ) {
            $formatted_value = $this->get_plain_price( $value );
        } else {
            $formatted_value = number_format_i18n( (float) $value );
        }

        return array(
            (string) $label,
            $formatted_value,
        );
    }

    private function render_chart_table( $headers, $rows, $numeric_columns = array() ) {
        if ( empty( $rows ) ) {
            return;
        }

        echo '<div class="wbi-table-responsive"><table class="wbi-table"><thead><tr>';
        foreach ( $headers as $index => $header ) {
            $align = in_array( $index, $numeric_columns, true ) ? ' data-align="right"' : '';
            echo '<th scope="col"' . $align . '>' . esc_html( $header ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $row as $index => $cell ) {
                $align = in_array( $index, $numeric_columns, true ) ? ' data-align="right"' : '';
                echo '<td' . $align . '>' . esc_html( (string) $cell ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function calc_delta( $current, $previous ) {
        if ( (float) $previous === 0.0 ) {
            return null;
        }
        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }

    private function render_delta( $delta ) {
        if ( null === $delta ) {
            return '';
        }
        if ( $delta > 0 ) {
            return '<span class="wbi-delta positive">' . esc_html__( 'Subió', 'wbi-suite' ) . ' ' . esc_html( $delta ) . '%</span>';
        }
        if ( $delta < 0 ) {
            return '<span class="wbi-delta negative">' . esc_html__( 'Bajó', 'wbi-suite' ) . ' ' . esc_html( abs( $delta ) ) . '%</span>';
        }
        return '<span class="wbi-delta neutral">' . esc_html__( 'Sin cambios (0%)', 'wbi-suite' ) . '</span>';
    }

    private function render_hidden_query_fields( array $source_args, $allowed_keys = array(), $exclude_keys = array() ) {
        $query_args = $this->get_allowed_query_args( $source_args, $allowed_keys, $exclude_keys );
        foreach ( $query_args as $key => $value ) {
            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $item ) . '" />';
                }
            } else {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
            }
        }
    }

    private function get_allowed_query_args( array $source_args, $allowed_keys = array(), $exclude_keys = array() ) {
        $args = array();
        foreach ( $source_args as $key => $value ) {
            if ( ! in_array( $key, $allowed_keys, true ) || in_array( $key, $exclude_keys, true ) ) {
                continue;
            }
            if ( 'page' === $key ) {
                $args['page'] = 'wbi-dashboard-view';
                continue;
            }
            if ( 'statuses' === $key ) {
                $statuses = WBI_Admin_Query_Helper::get_string_array( array( 'statuses' => $value ), 'statuses', $this->allowed_statuses );
                if ( ! empty( $statuses ) ) {
                    $args['statuses'] = $statuses;
                }
                continue;
            }
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            switch ( $key ) {
                case 'wbi_range':
                    $normalized = WBI_Admin_Query_Helper::get_enum( array( $key => $value ), $key, $this->allowed_ranges, '30d' );
                    break;
                case 'wbi_compare':
                    $normalized = WBI_Admin_Query_Helper::get_enum( array( $key => $value ), $key, $this->allowed_comparisons, 'none' );
                    break;
                case 'wbi_start':
                case 'wbi_end':
                case 'wbi_prev_start':
                case 'wbi_prev_end':
                    $normalized = WBI_Admin_Query_Helper::get_valid_date( array( $key => $value ), $key, '' );
                    break;
                case 'wbi_top_per_page':
                case 'wbi_least_per_page':
                    $normalized = WBI_Admin_Query_Helper::get_absint( array( $key => $value ), $key, 5 );
                    $normalized = in_array( $normalized, array( 5, 10, 25 ), true ) ? $normalized : 5;
                    break;
                case 'wbi_top_page':
                case 'wbi_least_page':
                    $normalized = max( 1, WBI_Admin_Query_Helper::get_absint( array( $key => $value ), $key, 1 ) );
                    break;
                default:
                    $normalized = sanitize_text_field( (string) $value );
                    break;
            }

            if ( '' !== $normalized ) {
                $args[ $key ] = $normalized;
            }
        }
        if ( empty( $args['page'] ) ) {
            $args['page'] = 'wbi-dashboard-view';
        }
        return $args;
    }
}