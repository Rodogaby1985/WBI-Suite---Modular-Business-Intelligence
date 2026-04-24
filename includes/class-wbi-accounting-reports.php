<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Accounting Reports Module — Reportes Contables
 *
 * Genera reportes contables estándar argentinos consultando los datos
 * de los módulos de Facturación, Notas de Crédito/Débito, Flujo de Caja,
 * Impuestos y WooCommerce. No crea tablas propias.
 */
class WBI_Accounting_Reports_Module {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_page' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wbi_accrep_load_report', array( $this, 'ajax_load_report' ) );
        add_action( 'wp_ajax_wbi_accrep_export_csv',  array( $this, 'ajax_export_csv' ) );
    }

    // -------------------------------------------------------------------------
    // ADMIN MENU & ASSETS
    // -------------------------------------------------------------------------

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Reportes Contables',
            '<span class="dashicons dashicons-chart-bar" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Rep. Contables',
            'manage_options',
            'wbi-accounting-reports',
            array( $this, 'render' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-accounting-reports' ) === false ) return;
        add_action( 'admin_head', array( $this, 'print_styles' ) );
    }

    public function print_styles() {
        $gen_date = esc_js( current_time( 'd/m/Y H:i' ) );
        ?>
        <style id="wbi-accrep-print">
        @media print {
            #adminmenuwrap, #wpadminbar, .wbi-accrep-tabs, .wbi-accrep-filters,
            .wbi-accrep-actions, #wpfooter, .update-nag, .notice, .wbi-accrep-no-print { display: none !important; }
            .wbi-accrep-report-area { margin: 0 !important; padding: 0 !important; }
            body { font-family: Arial, sans-serif; font-size: 11pt; color: #000; }
            table { border-collapse: collapse; width: 100%; page-break-inside: auto; }
            th, td { border: 1px solid #999; padding: 4px 6px; font-size: 9pt; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .wbi-accrep-print-header { display: block !important; margin-bottom: 16px; }
            @page { margin: 1.5cm; }
        }
        @media screen {
            .wbi-accrep-print-header { display: none; }
        }
        .wbi-accrep-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin: 16px 0 0; border-bottom: 2px solid #2271b1; }
        .wbi-accrep-tab-btn { padding: 8px 14px; border: 1px solid #c3c4c7; border-bottom: none; background: #f6f7f7; cursor: pointer; border-radius: 4px 4px 0 0; font-size: 13px; color: #2271b1; text-decoration: none; display: inline-block; }
        .wbi-accrep-tab-btn.active, .wbi-accrep-tab-btn:hover { background: #2271b1; color: #fff; }
        .wbi-accrep-filters { background: #fff; border: 1px solid #c3c4c7; border-radius: 0 4px 4px 4px; padding: 14px 16px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .wbi-accrep-filters label { font-size: 12px; font-weight: 600; display: block; }
        .wbi-accrep-filters input[type=date] { font-size: 13px; }
        .wbi-accrep-actions { display: flex; gap: 8px; margin-bottom: 12px; }
        .wbi-accrep-report-area { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px 20px; min-height: 200px; }
        .wbi-accrep-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .wbi-accrep-table th { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 6px 8px; text-align: left; white-space: nowrap; }
        .wbi-accrep-table td { border: 1px solid #e0e0e0; padding: 5px 8px; vertical-align: top; }
        .wbi-accrep-table tfoot td { font-weight: bold; background: #f0f6fc; border-top: 2px solid #2271b1; }
        .wbi-accrep-table tr:hover td { background: #f9f9f9; }
        .wbi-accrep-num { text-align: right; }
        .wbi-accrep-bar-wrap { margin: 4px 0; }
        .wbi-accrep-bar { display: inline-block; height: 14px; background: #2271b1; border-radius: 2px; vertical-align: middle; min-width: 2px; }
        .wbi-accrep-notice { padding: 12px 16px; background: #fcf9e8; border-left: 4px solid #dba617; border-radius: 2px; font-size: 13px; margin-bottom: 12px; }
        .wbi-accrep-notice--warning { background: #fff8e1; border-left: 4px solid #ffe082; }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // HELPER: Permission Check
    // -------------------------------------------------------------------------

    private function check_permission() {
        $opts        = get_option( 'wbi_modules_settings', array() );
        $perm_key    = 'wbi_permissions_accounting_reports';
        $permissions = ( isset( $opts[ $perm_key ] ) && ! empty( $opts[ $perm_key ] ) )
            ? (array) $opts[ $perm_key ]
            : array( 'administrator' );
        $user = wp_get_current_user();
        return (bool) array_intersect( $user->roles, $permissions );
    }

    // -------------------------------------------------------------------------
    // HELPER: Date Range
    // -------------------------------------------------------------------------

    private function get_date_range() {
        $preset = isset( $_GET['preset'] ) ? sanitize_text_field( wp_unslash( $_GET['preset'] ) ) : '';
        $now    = current_time( 'timestamp' );

        switch ( $preset ) {
            case 'this_month':
                $from = date( 'Y-m-01', $now );
                $to   = date( 'Y-m-t', $now );
                break;
            case 'last_month':
                $ts   = strtotime( 'first day of last month', $now );
                $from = date( 'Y-m-01', $ts );
                $to   = date( 'Y-m-t', $ts );
                break;
            case 'this_quarter':
                $month  = (int) date( 'n', $now );
                $qstart = ( (int) ceil( $month / 3 ) - 1 ) * 3 + 1;
                $qend   = $qstart + 2;
                $year   = date( 'Y', $now );
                $from   = sprintf( '%s-%02d-01', $year, $qstart );
                $to     = date( 'Y-m-t', strtotime( sprintf( '%s-%02d-01', $year, $qend ) ) );
                break;
            case 'this_year':
                $from = date( 'Y-01-01', $now );
                $to   = date( 'Y-12-31', $now );
                break;
            case 'last_year':
                $y    = (int) date( 'Y', $now ) - 1;
                $from = $y . '-01-01';
                $to   = $y . '-12-31';
                break;
            default:
                $from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : date( 'Y-m-01', $now );
                $to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : date( 'Y-m-t', $now );
        }

        return array(
            'from'   => $from,
            'to'     => $to,
            'preset' => $preset,
        );
    }

    // -------------------------------------------------------------------------
    // HELPER: Module Active Check
    // -------------------------------------------------------------------------

    private function is_module_active( $key ) {
        $opts = get_option( 'wbi_modules_settings', array() );
        return ! empty( $opts[ $key ] );
    }

    // -------------------------------------------------------------------------
    // MAIN RENDER
    // -------------------------------------------------------------------------

    public function render() {
        if ( ! $this->check_permission() ) {
            wp_die( esc_html__( 'No tenés permisos para acceder a este módulo.', 'wbi-suite' ) );
        }

        $tabs = array(
            'iva_ventas'   => '📒 IVA Ventas',
            'iva_compras'  => '📗 IVA Compras',
            'posicion_iva' => '⚖️ Posición IVA',
            'resultados'   => '📊 Resultados',
            'rentabilidad' => '🏆 Rentabilidad',
            'ventas'       => '📈 Resumen Ventas',
            'cashflow'     => '💧 Flujo de Caja',
        );

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'iva_ventas';
        if ( ! array_key_exists( $active_tab, $tabs ) ) {
            $active_tab = 'iva_ventas';
        }

        $dr     = $this->get_date_range();
        $from   = $dr['from'];
        $to     = $dr['to'];
        $preset = $dr['preset'];

        $store_name = get_bloginfo( 'name' );
        $store_cuit = get_option( 'wbi_store_cuit', '' );
        $store_addr = get_option( 'wbi_store_address', '' );
        ?>
        <div class="wrap">
            <h1>📊 Reportes Contables</h1>

            <!-- Print header (only visible when printing) -->
            <div class="wbi-accrep-print-header">
                <table style="width:100%;border:none;border-collapse:collapse;">
                    <tr>
                        <td style="border:none;font-size:16pt;font-weight:bold;"><?php echo esc_html( $store_name ); ?></td>
                        <td style="border:none;text-align:right;font-size:10pt;">
                            <?php if ( $store_cuit ) echo 'CUIT: ' . esc_html( $store_cuit ) . '<br>'; ?>
                            <?php if ( $store_addr ) echo esc_html( $store_addr ) . '<br>'; ?>
                            Período: <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?><br>
                            Generado: <?php echo esc_html( current_time( 'd/m/Y H:i' ) ); ?>
                        </td>
                    </tr>
                </table>
                <hr style="border:1px solid #000;margin:8px 0;">
                <h2 style="margin:4px 0;"><?php echo esc_html( $tabs[ $active_tab ] ); ?></h2>
            </div>

            <!-- Tabs -->
            <div class="wbi-accrep-tabs wbi-accrep-no-print">
                <?php foreach ( $tabs as $slug => $label ) :
                    $url = add_query_arg( array(
                        'page'      => 'wbi-accounting-reports',
                        'tab'       => $slug,
                        'date_from' => $from,
                        'date_to'   => $to,
                        'preset'    => $preset,
                    ), admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="wbi-accrep-tab-btn <?php echo $active_tab === $slug ? 'active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="wbi-accrep-filters wbi-accrep-no-print">
                <div>
                    <label>Desde<br>
                        <input type="date" id="wbi-accrep-from" value="<?php echo esc_attr( $from ); ?>">
                    </label>
                </div>
                <div>
                    <label>Hasta<br>
                        <input type="date" id="wbi-accrep-to" value="<?php echo esc_attr( $to ); ?>">
                    </label>
                </div>
                <div>
                    <label>&nbsp;<br>
                        <select id="wbi-accrep-preset">
                            <option value="">Personalizado</option>
                            <option value="this_month"   <?php selected( $preset, 'this_month' ); ?>>Este mes</option>
                            <option value="last_month"   <?php selected( $preset, 'last_month' ); ?>>Mes anterior</option>
                            <option value="this_quarter" <?php selected( $preset, 'this_quarter' ); ?>>Este trimestre</option>
                            <option value="this_year"    <?php selected( $preset, 'this_year' ); ?>>Este año</option>
                            <option value="last_year"    <?php selected( $preset, 'last_year' ); ?>>Año anterior</option>
                        </select>
                    </label>
                </div>
                <div>
                    <label>&nbsp;<br>
                        <button class="button button-primary" id="wbi-accrep-apply">Aplicar</button>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="wbi-accrep-actions wbi-accrep-no-print">
                <button class="button" id="wbi-accrep-export-csv">⬇ Exportar CSV</button>
                <button class="button" onclick="window.print()">🖨 Imprimir</button>
            </div>

            <!-- Report Area -->
            <div class="wbi-accrep-report-area" id="wbi-accrep-report">
                <?php $this->render_report( $active_tab, $from, $to ); ?>
            </div>
        </div>

        <script>
        (function($){
            var tab    = <?php echo wp_json_encode( $active_tab ); ?>;
            var nonce  = <?php echo wp_json_encode( wp_create_nonce( 'wbi_accrep_nonce' ) ); ?>;
            var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            function getFilters() {
                return {
                    date_from : $('#wbi-accrep-from').val(),
                    date_to   : $('#wbi-accrep-to').val(),
                    preset    : $('#wbi-accrep-preset').val(),
                    tab       : tab,
                };
            }

            // Preset auto-fills dates
            $('#wbi-accrep-preset').on('change', function(){
                var preset = $(this).val();
                if ( ! preset ) return;
                var now = new Date();
                var y = now.getFullYear(), m = now.getMonth() + 1;
                var from, to;
                function pad(n){ return n < 10 ? '0'+n : ''+n; }
                function dim(y,m){ return new Date(y,m,0).getDate(); }
                if ( preset === 'this_month' ){
                    from = y+'-'+pad(m)+'-01'; to = y+'-'+pad(m)+'-'+dim(y,m);
                } else if ( preset === 'last_month' ){
                    var lm = m===1?12:m-1, ly = m===1?y-1:y;
                    from = ly+'-'+pad(lm)+'-01'; to = ly+'-'+pad(lm)+'-'+dim(ly,lm);
                } else if ( preset === 'this_quarter' ){
                    var q = Math.ceil(m/3), qs=(q-1)*3+1, qe=qs+2;
                    from = y+'-'+pad(qs)+'-01'; to = y+'-'+pad(qe)+'-'+dim(y,qe);
                } else if ( preset === 'this_year' ){
                    from = y+'-01-01'; to = y+'-12-31';
                } else if ( preset === 'last_year' ){
                    from = (y-1)+'-01-01'; to = (y-1)+'-12-31';
                }
                if ( from ){ $('#wbi-accrep-from').val(from); $('#wbi-accrep-to').val(to); }
            });

            // Apply: navigate with new params
            $('#wbi-accrep-apply').on('click', function(){
                var f = getFilters();
                window.location.href = '<?php echo esc_js( admin_url( 'admin.php' ) ); ?>'
                    + '?page=wbi-accounting-reports'
                    + '&tab='       + encodeURIComponent(f.tab)
                    + '&date_from=' + encodeURIComponent(f.date_from)
                    + '&date_to='   + encodeURIComponent(f.date_to)
                    + '&preset='    + encodeURIComponent(f.preset);
            });

            // Export CSV
            $('#wbi-accrep-export-csv').on('click', function(){
                var f = getFilters();
                window.location.href = ajaxurl
                    + '?action=wbi_accrep_export_csv'
                    + '&_nonce='    + encodeURIComponent(nonce)
                    + '&tab='       + encodeURIComponent(f.tab)
                    + '&date_from=' + encodeURIComponent(f.date_from)
                    + '&date_to='   + encodeURIComponent(f.date_to);
            });
        })(jQuery);
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // REPORT DISPATCHER
    // -------------------------------------------------------------------------

    private function render_report( $tab, $from, $to ) {
        switch ( $tab ) {
            case 'iva_ventas':   $this->render_iva_ventas( $from, $to );   break;
            case 'iva_compras':  $this->render_iva_compras( $from, $to );  break;
            case 'posicion_iva': $this->render_posicion_iva( $from, $to ); break;
            case 'resultados':   $this->render_resultados( $from, $to );   break;
            case 'rentabilidad': $this->render_rentabilidad( $from, $to ); break;
            case 'ventas':       $this->render_ventas( $from, $to );       break;
            case 'cashflow':     $this->render_cashflow( $from, $to );     break;
            default:             $this->render_iva_ventas( $from, $to );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX HANDLERS
    // -------------------------------------------------------------------------

    public function ajax_load_report() {
        check_ajax_referer( 'wbi_accrep_nonce', '_nonce' );
        if ( ! $this->check_permission() ) {
            wp_send_json_error( 'Sin permisos.' );
        }

        $tab  = isset( $_POST['tab'] )       ? sanitize_text_field( wp_unslash( $_POST['tab'] ) )       : 'iva_ventas';
        $from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : date( 'Y-m-01' );
        $to   = isset( $_POST['date_to'] )   ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )   : date( 'Y-m-t' );

        ob_start();
        $this->render_report( $tab, $from, $to );
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

    public function ajax_export_csv() {
        if ( ! check_ajax_referer( 'wbi_accrep_nonce', '_nonce', false ) ) {
            wp_die( 'Nonce inválido.' );
        }
        if ( ! $this->check_permission() ) {
            wp_die( 'Sin permisos.' );
        }

        $tab  = isset( $_GET['tab'] )       ? sanitize_text_field( wp_unslash( $_GET['tab'] ) )       : 'iva_ventas';
        $from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : date( 'Y-m-01' );
        $to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : date( 'Y-m-t' );

        $filename = 'reporte-' . $tab . '-' . $from . '.csv';
        $rows     = $this->get_csv_data( $tab, $from, $to );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputs( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }
        fclose( $output );
        exit;
    }

    // -------------------------------------------------------------------------
    // CSV DATA GENERATOR
    // -------------------------------------------------------------------------

    private function get_csv_data( $tab, $from, $to ) {
        switch ( $tab ) {
            case 'iva_ventas':   return $this->csv_iva_ventas( $from, $to );
            case 'iva_compras':  return $this->csv_iva_compras( $from, $to );
            case 'posicion_iva': return $this->csv_posicion_iva( $from, $to );
            case 'resultados':   return $this->csv_resultados( $from, $to );
            case 'rentabilidad': return $this->csv_rentabilidad( $from, $to );
            case 'ventas':       return $this->csv_ventas( $from, $to );
            case 'cashflow':     return $this->csv_cashflow( $from, $to );
            default:             return array();
        }
    }

    // -------------------------------------------------------------------------
    // TAB 1: LIBRO IVA VENTAS
    // -------------------------------------------------------------------------

    private function get_iva_ventas_rows( $from, $to ) {
        global $wpdb;
        $rows = array();

        // From wbi_invoices (Documents module)
        $inv_table = $wpdb->prefix . 'wbi_invoices';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $inv_table ) ) === $inv_table ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    invoice_date   AS date,
                    'FA'           AS doc_type,
                    invoice_letter AS letter,
                    invoice_number AS number,
                    customer_name  AS customer,
                    customer_cuit  AS cuit,
                    neto_gravado,
                    iva_105,
                    iva_21,
                    iva_27,
                    otros_tributos,
                    total
                 FROM {$inv_table}
                 WHERE invoice_date BETWEEN %s AND %s
                 ORDER BY invoice_date ASC, invoice_number ASC",
                $from, $to
            ) );
            foreach ( $results as $r ) {
                $rows[] = array(
                    'date'           => $r->date,
                    'doc_type'       => $r->doc_type,
                    'letter'         => $r->letter,
                    'number'         => $r->number,
                    'customer'       => $r->customer,
                    'cuit'           => $r->cuit,
                    'neto_gravado'   => floatval( $r->neto_gravado ),
                    'iva_105'        => floatval( $r->iva_105 ),
                    'iva_21'         => floatval( $r->iva_21 ),
                    'iva_27'         => floatval( $r->iva_27 ),
                    'otros_tributos' => floatval( $r->otros_tributos ),
                    'total'          => floatval( $r->total ),
                );
            }
        }

        // From wbi_credit_debit_notes (Credit Notes module)
        $cdn_table = $wpdb->prefix . 'wbi_credit_debit_notes';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cdn_table ) ) === $cdn_table ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    note_date     AS date,
                    note_type     AS doc_type,
                    note_letter   AS letter,
                    note_number   AS number,
                    customer_name AS customer,
                    customer_cuit AS cuit,
                    neto_gravado,
                    iva_105,
                    iva_21,
                    iva_27,
                    0             AS otros_tributos,
                    total
                 FROM {$cdn_table}
                 WHERE note_date BETWEEN %s AND %s
                 ORDER BY note_date ASC, note_number ASC",
                $from, $to
            ) );
            foreach ( $results as $r ) {
                $sign   = ( strtoupper( $r->doc_type ) === 'NC' ) ? -1 : 1;
                $rows[] = array(
                    'date'           => $r->date,
                    'doc_type'       => strtoupper( $r->doc_type ),
                    'letter'         => $r->letter,
                    'number'         => $r->number,
                    'customer'       => $r->customer,
                    'cuit'           => $r->cuit,
                    'neto_gravado'   => $sign * floatval( $r->neto_gravado ),
                    'iva_105'        => $sign * floatval( $r->iva_105 ),
                    'iva_21'         => $sign * floatval( $r->iva_21 ),
                    'iva_27'         => $sign * floatval( $r->iva_27 ),
                    'otros_tributos' => $sign * floatval( $r->otros_tributos ),
                    'total'          => $sign * floatval( $r->total ),
                );
            }
        }

        usort( $rows, function( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        } );

        return $rows;
    }

    private function render_iva_ventas( $from, $to ) {
        if ( ! $this->is_module_active( 'wbi_enable_invoice' ) ) {
            echo '<div class="wbi-accrep-notice">📋 Activá el módulo de <strong>Facturación</strong> para usar este reporte.</div>';
            return;
        }

        $rows   = $this->get_iva_ventas_rows( $from, $to );
        $totals = array_fill_keys( array( 'neto_gravado', 'iva_105', 'iva_21', 'iva_27', 'otros_tributos', 'total' ), 0 );
        foreach ( $rows as $r ) {
            foreach ( $totals as $k => $v ) {
                $totals[ $k ] += $r[ $k ];
            }
        }
        ?>
        <h2>📒 Libro IVA Ventas — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>
        <?php if ( empty( $rows ) ) : ?>
            <p>No se encontraron comprobantes en el período seleccionado.</p>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table class="wbi-accrep-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Tipo</th><th>Letra</th><th>Número</th>
                    <th>Cliente</th><th>CUIT</th>
                    <th class="wbi-accrep-num">Neto Gravado</th>
                    <th class="wbi-accrep-num">IVA 10.5%</th>
                    <th class="wbi-accrep-num">IVA 21%</th>
                    <th class="wbi-accrep-num">IVA 27%</th>
                    <th class="wbi-accrep-num">Otros Trib.</th>
                    <th class="wbi-accrep-num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['date'] ); ?></td>
                    <td><?php echo esc_html( $r['doc_type'] ); ?></td>
                    <td><?php echo esc_html( $r['letter'] ); ?></td>
                    <td><?php echo esc_html( $r['number'] ); ?></td>
                    <td><?php echo esc_html( $r['customer'] ); ?></td>
                    <td><?php echo esc_html( $r['cuit'] ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['neto_gravado'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_105'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_21'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_27'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['otros_tributos'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['total'] ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6">TOTALES</td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['neto_gravado'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_105'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_21'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_27'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['otros_tributos'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['total'] ) ); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
        <?php
    }

    private function csv_iva_ventas( $from, $to ) {
        $rows   = $this->get_iva_ventas_rows( $from, $to );
        $output = array();
        $output[] = array( 'Fecha', 'Tipo', 'Letra', 'Número', 'Cliente', 'CUIT', 'Neto Gravado', 'IVA 10.5%', 'IVA 21%', 'IVA 27%', 'Otros Tributos', 'Total' );
        $totals   = array_fill_keys( array( 'neto_gravado', 'iva_105', 'iva_21', 'iva_27', 'otros_tributos', 'total' ), 0 );
        foreach ( $rows as $r ) {
            $output[] = array(
                $r['date'], $r['doc_type'], $r['letter'], $r['number'],
                $r['customer'], $r['cuit'],
                $this->fmt_csv( $r['neto_gravado'] ), $this->fmt_csv( $r['iva_105'] ),
                $this->fmt_csv( $r['iva_21'] ), $this->fmt_csv( $r['iva_27'] ),
                $this->fmt_csv( $r['otros_tributos'] ), $this->fmt_csv( $r['total'] ),
            );
            foreach ( $totals as $k => $v ) {
                $totals[ $k ] += $r[ $k ];
            }
        }
        $output[] = array(
            'TOTALES', '', '', '', '', '',
            $this->fmt_csv( $totals['neto_gravado'] ), $this->fmt_csv( $totals['iva_105'] ),
            $this->fmt_csv( $totals['iva_21'] ), $this->fmt_csv( $totals['iva_27'] ),
            $this->fmt_csv( $totals['otros_tributos'] ), $this->fmt_csv( $totals['total'] ),
        );
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 2: LIBRO IVA COMPRAS
    // -------------------------------------------------------------------------

    private function get_iva_compras_rows( $from, $to ) {
        global $wpdb;
        $rows = array();

        $po_table = $wpdb->prefix . 'wbi_purchase_orders';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $po_table ) ) === $po_table ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    order_date     AS date,
                    'FC'           AS doc_type,
                    supplier_name  AS supplier,
                    supplier_cuit  AS cuit,
                    neto_gravado,
                    iva_amount     AS iva_21,
                    total_amount   AS total
                 FROM {$po_table}
                 WHERE order_date BETWEEN %s AND %s
                   AND status NOT IN ('cancelled','draft')
                 ORDER BY order_date ASC",
                $from, $to
            ) );
            foreach ( $results as $r ) {
                $rows[] = array(
                    'date'         => $r->date,
                    'doc_type'     => $r->doc_type,
                    'supplier'     => $r->supplier,
                    'cuit'         => $r->cuit,
                    'neto_gravado' => floatval( $r->neto_gravado ),
                    'iva_105'      => 0.0,
                    'iva_21'       => floatval( $r->iva_21 ),
                    'iva_27'       => 0.0,
                    'total'        => floatval( $r->total ),
                );
            }
        }

        return $rows;
    }

    private function render_iva_compras( $from, $to ) {
        $rows   = $this->get_iva_compras_rows( $from, $to );
        $totals = array_fill_keys( array( 'neto_gravado', 'iva_105', 'iva_21', 'iva_27', 'total' ), 0 );
        foreach ( $rows as $r ) {
            foreach ( $totals as $k => $v ) {
                if ( isset( $r[ $k ] ) ) $totals[ $k ] += $r[ $k ];
            }
        }
        ?>
        <h2>📗 Libro IVA Compras — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>
        <?php if ( empty( $rows ) ) : ?>
            <div class="wbi-accrep-notice">
                📋 No hay datos de compras disponibles para el período seleccionado.<br>
                <small>Activá el módulo de <strong>Órdenes de Compra</strong> para poblar este reporte.</small>
            </div>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table class="wbi-accrep-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Tipo</th><th>Proveedor</th><th>CUIT</th>
                    <th class="wbi-accrep-num">Neto Gravado</th>
                    <th class="wbi-accrep-num">IVA 10.5%</th>
                    <th class="wbi-accrep-num">IVA 21%</th>
                    <th class="wbi-accrep-num">IVA 27%</th>
                    <th class="wbi-accrep-num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['date'] ); ?></td>
                    <td><?php echo esc_html( $r['doc_type'] ); ?></td>
                    <td><?php echo esc_html( $r['supplier'] ); ?></td>
                    <td><?php echo esc_html( $r['cuit'] ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['neto_gravado'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_105'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_21'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['iva_27'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['total'] ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">TOTALES</td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['neto_gravado'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_105'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_21'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['iva_27'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $totals['total'] ) ); ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
        <?php
    }

    private function csv_iva_compras( $from, $to ) {
        $rows   = $this->get_iva_compras_rows( $from, $to );
        $output = array();
        $output[] = array( 'Fecha', 'Tipo', 'Proveedor', 'CUIT', 'Neto Gravado', 'IVA 10.5%', 'IVA 21%', 'IVA 27%', 'Total' );
        $totals   = array_fill_keys( array( 'neto_gravado', 'iva_105', 'iva_21', 'iva_27', 'total' ), 0 );
        foreach ( $rows as $r ) {
            $output[] = array(
                $r['date'], $r['doc_type'], $r['supplier'], $r['cuit'],
                $this->fmt_csv( $r['neto_gravado'] ), $this->fmt_csv( $r['iva_105'] ),
                $this->fmt_csv( $r['iva_21'] ), $this->fmt_csv( $r['iva_27'] ),
                $this->fmt_csv( $r['total'] ),
            );
            foreach ( $totals as $k => $v ) {
                if ( isset( $r[ $k ] ) ) $totals[ $k ] += $r[ $k ];
            }
        }
        $output[] = array(
            'TOTALES', '', '', '',
            $this->fmt_csv( $totals['neto_gravado'] ), $this->fmt_csv( $totals['iva_105'] ),
            $this->fmt_csv( $totals['iva_21'] ), $this->fmt_csv( $totals['iva_27'] ),
            $this->fmt_csv( $totals['total'] ),
        );
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 3: POSICIÓN IVA
    // -------------------------------------------------------------------------

    private function get_posicion_iva_months( $from, $to ) {
        $from_ts = strtotime( $from );
        $to_ts   = strtotime( $to );
        $months  = array();
        $cur     = strtotime( date( 'Y-m-01', $from_ts ) );
        while ( $cur <= $to_ts ) {
            $months[ date( 'Y-m', $cur ) ] = array( 'debito' => 0.0, 'credito' => 0.0 );
            $cur = strtotime( '+1 month', $cur );
        }

        foreach ( $this->get_iva_ventas_rows( $from, $to ) as $r ) {
            $ym = substr( $r['date'], 0, 7 );
            if ( isset( $months[ $ym ] ) ) {
                $months[ $ym ]['debito'] += $r['iva_105'] + $r['iva_21'] + $r['iva_27'];
            }
        }

        foreach ( $this->get_iva_compras_rows( $from, $to ) as $r ) {
            $ym = substr( $r['date'], 0, 7 );
            if ( isset( $months[ $ym ] ) ) {
                $months[ $ym ]['credito'] += $r['iva_105'] + $r['iva_21'] + $r['iva_27'];
            }
        }

        return $months;
    }

    private function render_posicion_iva( $from, $to ) {
        $months     = $this->get_posicion_iva_months( $from, $to );
        $total_deb  = 0.0;
        $total_cred = 0.0;
        $max_val    = 1.0;
        foreach ( $months as $d ) {
            $total_deb  += $d['debito'];
            $total_cred += $d['credito'];
            $max_val     = max( $max_val, $d['debito'], $d['credito'] );
        }
        $saldo = $total_deb - $total_cred;
        ?>
        <h2>⚖️ Posición IVA — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
            <div style="background:#e8f4fd;border:1px solid #90caf9;border-radius:6px;padding:16px 24px;min-width:160px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">IVA Débito Fiscal</div>
                <div style="font-size:22px;font-weight:bold;color:#1565c0;"><?php echo esc_html( $this->fmt( $total_deb ) ); ?></div>
            </div>
            <div style="background:#fce4ec;border:1px solid #f48fb1;border-radius:6px;padding:16px 24px;min-width:160px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">IVA Crédito Fiscal</div>
                <div style="font-size:22px;font-weight:bold;color:#b71c1c;"><?php echo esc_html( $this->fmt( $total_cred ) ); ?></div>
            </div>
            <div style="background:<?php echo $saldo >= 0 ? '#e8f5e9' : '#fff3e0'; ?>;border:1px solid <?php echo $saldo >= 0 ? '#a5d6a7' : '#ffcc80'; ?>;border-radius:6px;padding:16px 24px;min-width:160px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">Saldo</div>
                <div style="font-size:22px;font-weight:bold;color:<?php echo $saldo >= 0 ? '#2e7d32' : '#e65100'; ?>;">
                    <?php echo esc_html( $this->fmt( abs( $saldo ) ) ); ?>
                    <small style="font-size:12px;"><?php echo $saldo >= 0 ? '(a pagar)' : '(a favor)'; ?></small>
                </div>
            </div>
        </div>

        <table class="wbi-accrep-table">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="wbi-accrep-num">IVA Débito</th><th>&#9646;</th>
                    <th class="wbi-accrep-num">IVA Crédito</th><th>&#9646;</th>
                    <th class="wbi-accrep-num">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $months as $ym => $d ) :
                    $saldo_m = $d['debito'] - $d['credito'];
                    $w_deb   = $max_val > 0 ? (int) round( ( $d['debito']  / $max_val ) * 120 ) : 0;
                    $w_cred  = $max_val > 0 ? (int) round( ( $d['credito'] / $max_val ) * 120 ) : 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $ym ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $d['debito'] ) ); ?></td>
                    <td><span class="wbi-accrep-bar" style="width:<?php echo esc_attr( $w_deb ); ?>px;"></span></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $d['credito'] ) ); ?></td>
                    <td><span class="wbi-accrep-bar" style="width:<?php echo esc_attr( $w_cred ); ?>px;background:#e57373;"></span></td>
                    <td class="wbi-accrep-num" style="font-weight:600;color:<?php echo $saldo_m >= 0 ? '#2e7d32' : '#d63638'; ?>;">
                        <?php echo esc_html( $this->fmt( $saldo_m ) ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTALES</td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $total_deb ) ); ?></td><td></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $total_cred ) ); ?></td><td></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $saldo ) ); ?></td>
                </tr>
            </tfoot>
        </table>
        <?php
    }

    private function csv_posicion_iva( $from, $to ) {
        $months = $this->get_posicion_iva_months( $from, $to );
        $output = array();
        $output[] = array( 'Mes', 'IVA Débito Fiscal', 'IVA Crédito Fiscal', 'Saldo' );
        $td = 0.0; $tc = 0.0;
        foreach ( $months as $ym => $d ) {
            $saldo    = $d['debito'] - $d['credito'];
            $output[] = array( $ym, $this->fmt_csv( $d['debito'] ), $this->fmt_csv( $d['credito'] ), $this->fmt_csv( $saldo ) );
            $td += $d['debito'];
            $tc += $d['credito'];
        }
        $output[] = array( 'TOTALES', $this->fmt_csv( $td ), $this->fmt_csv( $tc ), $this->fmt_csv( $td - $tc ) );
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 4: ESTADO DE RESULTADOS
    // -------------------------------------------------------------------------

    private function get_resultados_data( $from, $to ) {
        global $wpdb;

        $inv_table = $wpdb->prefix . 'wbi_invoices';
        $cdn_table = $wpdb->prefix . 'wbi_credit_debit_notes';

        // Revenue
        $revenue = 0.0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $inv_table ) ) === $inv_table ) {
            $revenue += (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(neto_gravado),0) FROM {$inv_table} WHERE invoice_date BETWEEN %s AND %s",
                $from, $to
            ) );
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cdn_table ) ) === $cdn_table ) {
            $revenue -= (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(neto_gravado),0) FROM {$cdn_table}
                 WHERE note_date BETWEEN %s AND %s AND UPPER(note_type)='NC'",
                $from, $to
            ) );
        }
        // Fallback to WC order stats when invoice module is not enabled
        if ( $revenue === 0.0 && ! $this->is_module_active( 'wbi_enable_invoice' ) ) {
            $revenue = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_order_stats
                 WHERE date_created BETWEEN %s AND %s AND status IN ('wc-completed','wc-processing')",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );
        }

        // COGS
        $cogs        = 0.0;
        $costs_table = $wpdb->prefix . 'wbi_product_costs';
        if ( $this->is_module_active( 'wbi_enable_costs' ) &&
             $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $costs_table ) ) === $costs_table ) {
            $cogs = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(c.cost_price * opl.product_qty),0)
                 FROM {$wpdb->prefix}wc_order_product_lookup opl
                 INNER JOIN {$costs_table} c ON c.product_id = opl.product_id
                 INNER JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
                 WHERE os.date_created BETWEEN %s AND %s
                   AND os.status IN ('wc-completed','wc-processing')",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );
        }

        $gross_margin = $revenue - $cogs;

        // Monthly revenue
        $from_ts = strtotime( $from );
        $to_ts   = strtotime( $to );
        $months  = array();
        $cur     = strtotime( date( 'Y-m-01', $from_ts ) );
        while ( $cur <= $to_ts ) {
            $ym    = date( 'Y-m', $cur );
            $mfrom = date( 'Y-m-01', $cur );
            $mto   = date( 'Y-m-t', $cur );

            $m_rev = 0.0;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $inv_table ) ) === $inv_table ) {
                $m_rev = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(neto_gravado),0) FROM {$inv_table} WHERE invoice_date BETWEEN %s AND %s",
                    $mfrom, $mto
                ) );
            }
            if ( $m_rev === 0.0 && ! $this->is_module_active( 'wbi_enable_invoice' ) ) {
                $m_rev = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_order_stats
                     WHERE date_created BETWEEN %s AND %s AND status IN ('wc-completed','wc-processing')",
                    $mfrom . ' 00:00:00', $mto . ' 23:59:59'
                ) );
            }
            $months[ $ym ] = array( 'revenue' => $m_rev, 'cogs' => 0.0, 'margin' => $m_rev );
            $cur = strtotime( '+1 month', $cur );
        }

        return array(
            'revenue'      => $revenue,
            'cogs'         => $cogs,
            'gross_margin' => $gross_margin,
            'net_income'   => $gross_margin,
            'months'       => $months,
        );
    }

    private function render_resultados( $from, $to ) {
        $data       = $this->get_resultados_data( $from, $to );
        $margin_pct = $data['revenue'] > 0 ? round( ( $data['gross_margin'] / $data['revenue'] ) * 100, 1 ) : 0;
        ?>
        <h2>📊 Estado de Resultados — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>

        <table class="wbi-accrep-table" style="max-width:520px;margin-bottom:24px;">
            <tbody>
                <tr>
                    <td><strong>Ingresos (Ventas Netas)</strong></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $data['revenue'] ) ); ?></td>
                </tr>
                <tr>
                    <td style="padding-left:24px;">Costo de Ventas (CMV)</td>
                    <td class="wbi-accrep-num">(<?php echo esc_html( $this->fmt( $data['cogs'] ) ); ?>)</td>
                </tr>
                <tr style="background:#f0f6fc;">
                    <td><strong>Margen Bruto</strong></td>
                    <td class="wbi-accrep-num">
                        <strong><?php echo esc_html( $this->fmt( $data['gross_margin'] ) ); ?></strong>
                        <small>(<?php echo esc_html( $margin_pct ); ?>%)</small>
                    </td>
                </tr>
                <tr>
                    <td style="padding-left:24px;">Gastos Operativos</td>
                    <td class="wbi-accrep-num">(—)</td>
                </tr>
                <tr style="background:#e8f5e9;">
                    <td><strong>Resultado Neto</strong></td>
                    <td class="wbi-accrep-num"><strong><?php echo esc_html( $this->fmt( $data['net_income'] ) ); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <?php if ( ! empty( $data['months'] ) ) :
            $rev_vals = array_column( $data['months'], 'revenue' );
            $max_rev  = max( array_merge( $rev_vals, array( 1 ) ) );
        ?>
        <h3>Desglose Mensual</h3>
        <div style="overflow-x:auto;">
        <table class="wbi-accrep-table">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="wbi-accrep-num">Ingresos</th>
                    <th class="wbi-accrep-num">CMV</th>
                    <th class="wbi-accrep-num">Margen Bruto</th>
                    <th>Tendencia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $data['months'] as $ym => $m ) :
                    $w = $max_rev > 0 ? (int) round( ( $m['revenue'] / $max_rev ) * 100 ) : 0;
                ?>
                <tr>
                    <td><?php echo esc_html( $ym ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m['revenue'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m['cogs'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m['margin'] ) ); ?></td>
                    <td><span class="wbi-accrep-bar" style="width:<?php echo esc_attr( $w ); ?>px;"></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if ( ! $this->is_module_active( 'wbi_enable_costs' ) ) : ?>
        <div class="wbi-accrep-notice" style="margin-top:12px;">
            💡 Activá el módulo de <strong>Costos y Márgenes</strong> para ver el Costo de Ventas detallado.
        </div>
        <?php endif; ?>
        <?php
    }

    private function csv_resultados( $from, $to ) {
        $data   = $this->get_resultados_data( $from, $to );
        $output = array();
        $output[] = array( 'Concepto', 'Monto' );
        $output[] = array( 'Ingresos (Ventas Netas)', $this->fmt_csv( $data['revenue'] ) );
        $output[] = array( 'Costo de Ventas (CMV)', $this->fmt_csv( $data['cogs'] ) );
        $output[] = array( 'Margen Bruto', $this->fmt_csv( $data['gross_margin'] ) );
        $output[] = array( 'Gastos Operativos', '—' );
        $output[] = array( 'Resultado Neto', $this->fmt_csv( $data['net_income'] ) );
        $output[] = array();
        $output[] = array( 'Mes', 'Ingresos', 'CMV', 'Margen' );
        foreach ( $data['months'] as $ym => $m ) {
            $output[] = array( $ym, $this->fmt_csv( $m['revenue'] ), $this->fmt_csv( $m['cogs'] ), $this->fmt_csv( $m['margin'] ) );
        }
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 5: RENTABILIDAD POR PRODUCTO
    // -------------------------------------------------------------------------

    private function get_rentabilidad_rows( $from, $to ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                opl.product_id,
                p.post_title AS product_name,
                pm_sku.meta_value AS sku,
                SUM(opl.product_qty) AS units_sold,
                SUM(opl.product_net_revenue) AS revenue
             FROM {$wpdb->prefix}wc_order_product_lookup opl
             INNER JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
             LEFT JOIN  {$wpdb->posts} p ON p.ID = opl.product_id
             LEFT JOIN  {$wpdb->postmeta} pm_sku ON pm_sku.post_id = opl.product_id AND pm_sku.meta_key = '_sku'
             WHERE os.date_created BETWEEN %s AND %s
               AND os.status IN ('wc-completed','wc-processing')
             GROUP BY opl.product_id, p.post_title, pm_sku.meta_value
             ORDER BY revenue DESC
             LIMIT 100",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ) );

        $costs_table = $wpdb->prefix . 'wbi_product_costs';
        $has_costs   = $this->is_module_active( 'wbi_enable_costs' ) &&
                       ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $costs_table ) ) === $costs_table );

        $result = array();
        foreach ( $rows as $r ) {
            $cost = 0.0;
            if ( $has_costs ) {
                $cp   = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT cost_price FROM {$costs_table} WHERE product_id = %d LIMIT 1",
                    $r->product_id
                ) );
                $cost = $cp * floatval( $r->units_sold );
            }
            $revenue    = floatval( $r->revenue );
            $margin     = $revenue - $cost;
            $margin_pct = $revenue > 0 ? round( ( $margin / $revenue ) * 100, 1 ) : 0;

            $result[] = array(
                'product_id'   => (int) $r->product_id,
                'product_name' => $r->product_name ?: ( 'Producto #' . $r->product_id ),
                'sku'          => $r->sku ?: '—',
                'units_sold'   => (int) $r->units_sold,
                'revenue'      => $revenue,
                'cost'         => $cost,
                'margin'       => $margin,
                'margin_pct'   => $margin_pct,
            );
        }

        return $result;
    }

    private function render_rentabilidad( $from, $to ) {
        $rows         = $this->get_rentabilidad_rows( $from, $to );
        $sort         = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : 'revenue';
        $allowed      = array( 'revenue', 'margin', 'margin_pct', 'units_sold' );
        if ( ! in_array( $sort, $allowed, true ) ) $sort = 'revenue';

        usort( $rows, function( $a, $b ) use ( $sort ) {
            return $b[ $sort ] <=> $a[ $sort ];
        } );

        $filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : '';
        if ( $filter === 'top10' )    $rows = array_slice( $rows, 0, 10 );
        if ( $filter === 'bottom10' ) { rsort( $rows ); $rows = array_slice( $rows, 0, 10 ); }
        ?>
        <h2>🏆 Rentabilidad por Producto — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>

        <div class="wbi-accrep-no-print" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <strong>Ordenar por:</strong>
            <?php foreach ( array( 'revenue' => 'Ingresos', 'margin' => 'Margen $', 'margin_pct' => 'Margen %', 'units_sold' => 'Unidades' ) as $s => $lbl ) : ?>
            <a href="<?php echo esc_url( add_query_arg( array( 'sort' => $s, 'filter' => $filter ) ) ); ?>"
               class="button button-small <?php echo $sort === $s ? 'button-primary' : ''; ?>">
                <?php echo esc_html( $lbl ); ?>
            </a>
            <?php endforeach; ?>
            &nbsp;|&nbsp;
            <a href="<?php echo esc_url( add_query_arg( array( 'filter' => 'top10', 'sort' => $sort ) ) ); ?>"
               class="button button-small <?php echo $filter === 'top10' ? 'button-primary' : ''; ?>">Top 10</a>
            <a href="<?php echo esc_url( add_query_arg( array( 'filter' => 'bottom10', 'sort' => $sort ) ) ); ?>"
               class="button button-small <?php echo $filter === 'bottom10' ? 'button-primary' : ''; ?>">Bottom 10</a>
            <a href="<?php echo esc_url( add_query_arg( array( 'filter' => '' ) ) ); ?>"
               class="button button-small">Ver todos</a>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <p>No hay datos de ventas para el período seleccionado.</p>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table class="wbi-accrep-table">
            <thead>
                <tr>
                    <th>#</th><th>Producto</th><th>SKU</th>
                    <th class="wbi-accrep-num">Unidades</th>
                    <th class="wbi-accrep-num">Ingresos</th>
                    <th class="wbi-accrep-num">Costo</th>
                    <th class="wbi-accrep-num">Margen $</th>
                    <th class="wbi-accrep-num">Margen %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $i => $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $i + 1 ); ?></td>
                    <td><?php echo esc_html( $r['product_name'] ); ?></td>
                    <td><?php echo esc_html( $r['sku'] ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( number_format( $r['units_sold'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['revenue'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['cost'] ) ); ?></td>
                    <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $r['margin'] ) ); ?></td>
                    <td class="wbi-accrep-num" style="color:<?php echo $r['margin_pct'] >= 0 ? '#2e7d32' : '#d63638'; ?>;">
                        <?php echo esc_html( $r['margin_pct'] ); ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ( ! $this->is_module_active( 'wbi_enable_costs' ) ) : ?>
        <div class="wbi-accrep-notice" style="margin-top:12px;">
            💡 Activá el módulo de <strong>Costos y Márgenes</strong> para ver los costos reales por producto.
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private function csv_rentabilidad( $from, $to ) {
        $rows   = $this->get_rentabilidad_rows( $from, $to );
        $output = array();
        $output[] = array( 'Producto', 'SKU', 'Unidades', 'Ingresos', 'Costo', 'Margen $', 'Margen %' );
        foreach ( $rows as $r ) {
            $output[] = array(
                $r['product_name'], $r['sku'], $r['units_sold'],
                $this->fmt_csv( $r['revenue'] ), $this->fmt_csv( $r['cost'] ),
                $this->fmt_csv( $r['margin'] ), $r['margin_pct'] . '%',
            );
        }
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 6: RESUMEN DE VENTAS
    // -------------------------------------------------------------------------

    private function get_ventas_data( $from, $to ) {
        global $wpdb;

        $monthly = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE_FORMAT(date_created,'%%Y-%%m') AS ym,
                COUNT(*)                            AS orders,
                SUM(total_amount)                   AS revenue,
                AVG(total_amount)                   AS avg_order
             FROM {$wpdb->prefix}wc_order_stats
             WHERE date_created BETWEEN %s AND %s
               AND status IN ('wc-completed','wc-processing')
             GROUP BY ym ORDER BY ym ASC",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ) );

        $by_payment = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                pm.meta_value     AS payment_method,
                COUNT(*)          AS orders,
                SUM(os.total_amount) AS revenue
             FROM {$wpdb->prefix}wc_order_stats os
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = os.order_id AND pm.meta_key = '_payment_method_title'
             WHERE os.date_created BETWEEN %s AND %s
               AND os.status IN ('wc-completed','wc-processing')
             GROUP BY payment_method ORDER BY revenue DESC",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ) );

        $top_customers = $wpdb->get_results( $wpdb->prepare(
            "SELECT customer_id, COUNT(*) AS orders, SUM(total_amount) AS revenue
             FROM {$wpdb->prefix}wc_order_stats
             WHERE date_created BETWEEN %s AND %s
               AND status IN ('wc-completed','wc-processing')
               AND customer_id > 0
             GROUP BY customer_id ORDER BY revenue DESC LIMIT 20",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ) );

        // Fallback: if wc_order_stats returned no rows, read directly from wp_posts + wp_postmeta.
        $using_fallback = false;
        if ( empty( $monthly ) ) {
            $using_fallback = true;
            $monthly = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    DATE_FORMAT(p.post_date,'%%Y-%%m')          AS ym,
                    COUNT(*)                                     AS orders,
                    SUM(CAST(pm.meta_value AS DECIMAL(15,2)))   AS revenue,
                    AVG(CAST(pm.meta_value AS DECIMAL(15,2)))   AS avg_order
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date BETWEEN %s AND %s
                 GROUP BY ym ORDER BY ym ASC",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );

            $by_payment = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    pm2.meta_value                               AS payment_method,
                    COUNT(*)                                     AS orders,
                    SUM(CAST(pm.meta_value AS DECIMAL(15,2)))   AS revenue
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = '_order_total'
                 LEFT  JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_payment_method_title'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date BETWEEN %s AND %s
                 GROUP BY payment_method ORDER BY revenue DESC",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );

            $top_customers = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    CAST(pm_cu.meta_value AS UNSIGNED)           AS customer_id,
                    COUNT(*)                                     AS orders,
                    SUM(CAST(pm.meta_value AS DECIMAL(15,2)))   AS revenue
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm    ON pm.post_id    = p.ID AND pm.meta_key    = '_order_total'
                 INNER JOIN {$wpdb->postmeta} pm_cu ON pm_cu.post_id = p.ID AND pm_cu.meta_key = '_customer_user'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date BETWEEN %s AND %s
                   AND pm_cu.meta_value > 0
                 GROUP BY customer_id ORDER BY revenue DESC LIMIT 20",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );
        }

        return compact( 'monthly', 'by_payment', 'top_customers', 'using_fallback' );
    }

    private function render_ventas( $from, $to ) {
        $data      = $this->get_ventas_data( $from, $to );
        $total_rev = 0.0; $total_ord = 0; $max_rev = 1.0;
        foreach ( $data['monthly'] as $m ) {
            $total_rev += floatval( $m->revenue );
            $total_ord += intval( $m->orders );
            $max_rev    = max( $max_rev, floatval( $m->revenue ) );
        }
        $avg_order = $total_ord > 0 ? $total_rev / $total_ord : 0.0;
        ?>
        <h2>📈 Resumen de Ventas — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>

        <?php if ( $data['using_fallback'] ) : ?>
        <?php $this->render_analytics_fallback_notice(); ?>
        <?php endif; ?>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
            <div style="background:#e8f4fd;border:1px solid #90caf9;border-radius:6px;padding:12px 20px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">Total Ventas</div>
                <div style="font-size:20px;font-weight:bold;color:#1565c0;"><?php echo esc_html( $this->fmt( $total_rev ) ); ?></div>
            </div>
            <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:12px 20px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">Pedidos</div>
                <div style="font-size:20px;font-weight:bold;color:#2e7d32;"><?php echo esc_html( number_format( $total_ord ) ); ?></div>
            </div>
            <div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:6px;padding:12px 20px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">Ticket Promedio</div>
                <div style="font-size:20px;font-weight:bold;color:#e65100;"><?php echo esc_html( $this->fmt( $avg_order ) ); ?></div>
            </div>
        </div>

        <h3>Ventas por Mes</h3>
        <table class="wbi-accrep-table" style="margin-bottom:20px;">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="wbi-accrep-num">Pedidos</th>
                    <th class="wbi-accrep-num">Ingresos</th>
                    <th class="wbi-accrep-num">Ticket Prom.</th>
                    <th>Barra</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $data['monthly'] as $m ) :
                $w = $max_rev > 0 ? (int) round( ( floatval( $m->revenue ) / $max_rev ) * 120 ) : 0;
            ?>
            <tr>
                <td><?php echo esc_html( $m->ym ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( number_format( intval( $m->orders ) ) ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m->revenue ) ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m->avg_order ) ); ?></td>
                <td><span class="wbi-accrep-bar" style="width:<?php echo esc_attr( $w ); ?>px;"></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Ventas por Medio de Pago</h3>
        <?php if ( ! empty( $data['by_payment'] ) ) : ?>
        <table class="wbi-accrep-table" style="max-width:420px;margin-bottom:20px;">
            <thead><tr><th>Medio de Pago</th><th class="wbi-accrep-num">Pedidos</th><th class="wbi-accrep-num">Total</th></tr></thead>
            <tbody>
            <?php foreach ( $data['by_payment'] as $p ) : ?>
            <tr>
                <td><?php echo esc_html( $p->payment_method ?: 'No especificado' ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( number_format( intval( $p->orders ) ) ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $p->revenue ) ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p>No hay datos de medios de pago para el período.</p>
        <?php endif; ?>

        <h3>Top 20 Clientes</h3>
        <?php if ( ! empty( $data['top_customers'] ) ) : ?>
        <table class="wbi-accrep-table" style="max-width:450px;">
            <thead><tr><th>#</th><th>Cliente</th><th class="wbi-accrep-num">Pedidos</th><th class="wbi-accrep-num">Total</th></tr></thead>
            <tbody>
            <?php foreach ( $data['top_customers'] as $i => $c ) :
                $user = get_user_by( 'id', (int) $c->customer_id );
                $name = $user ? $user->display_name : ( 'Cliente #' . $c->customer_id );
            ?>
            <tr>
                <td><?php echo esc_html( $i + 1 ); ?></td>
                <td><?php echo esc_html( $name ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( number_format( intval( $c->orders ) ) ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $c->revenue ) ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p>No hay datos de clientes para el período.</p>
        <?php endif; ?>
        <?php
    }

    private function csv_ventas( $from, $to ) {
        $data   = $this->get_ventas_data( $from, $to );
        $output = array();
        $output[] = array( 'Mes', 'Pedidos', 'Ingresos', 'Ticket Promedio' );
        foreach ( $data['monthly'] as $m ) {
            $output[] = array( $m->ym, intval( $m->orders ), $this->fmt_csv( $m->revenue ), $this->fmt_csv( $m->avg_order ) );
        }
        $output[] = array();
        $output[] = array( 'Medio de Pago', 'Pedidos', 'Total', '' );
        foreach ( $data['by_payment'] as $p ) {
            $output[] = array( $p->payment_method ?: 'No especificado', intval( $p->orders ), $this->fmt_csv( $p->revenue ), '' );
        }
        return $output;
    }

    // -------------------------------------------------------------------------
    // TAB 7: FLUJO DE CAJA PROYECTADO
    // -------------------------------------------------------------------------

    private function get_cashflow_data( $from, $to ) {
        global $wpdb;
        $has_cashflow = $this->is_module_active( 'wbi_enable_cashflow' );

        $monthly = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(date_created,'%%Y-%%m') AS ym, SUM(total_amount) AS income
             FROM {$wpdb->prefix}wc_order_stats
             WHERE date_created BETWEEN %s AND %s
               AND status IN ('wc-completed','wc-processing')
             GROUP BY ym ORDER BY ym ASC",
            $from . ' 00:00:00', $to . ' 23:59:59'
        ) );

        // Fallback: if wc_order_stats returned no rows, read directly from wp_posts + wp_postmeta.
        $using_fallback = false;
        if ( empty( $monthly ) ) {
            $using_fallback = true;
            $monthly = $wpdb->get_results( $wpdb->prepare(
                "SELECT
                    DATE_FORMAT(p.post_date,'%%Y-%%m')         AS ym,
                    SUM(CAST(pm.meta_value AS DECIMAL(15,2)))  AS income
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date BETWEEN %s AND %s
                 GROUP BY ym ORDER BY ym ASC",
                $from . ' 00:00:00', $to . ' 23:59:59'
            ) );
        }

        // Aggregate cashflow module expenses by month
        $expenses_by_month = array();
        if ( $has_cashflow ) {
            $from_ym = substr( $from, 0, 7 );
            $to_ym   = substr( $to, 0, 7 );
            foreach ( get_option( 'wbi_cashflow_expenses', array() ) as $e ) {
                if ( empty( $e['date'] ) ) continue;
                $ym = substr( $e['date'], 0, 7 );
                if ( $ym >= $from_ym && $ym <= $to_ym ) {
                    $expenses_by_month[ $ym ] = ( isset( $expenses_by_month[ $ym ] ) ? $expenses_by_month[ $ym ] : 0.0 ) + floatval( $e['amount'] );
                }
            }
        }

        // 3-month projection from average of last 3 months
        $incomes = array_map( function( $m ) { return floatval( $m->income ); }, (array) $monthly );
        $last3   = array_slice( $incomes, -3 );
        $avg     = count( $last3 ) > 0 ? array_sum( $last3 ) / count( $last3 ) : 0.0;
        $last_ym = ! empty( $monthly ) ? end( $monthly )->ym : date( 'Y-m' );
        $proj    = array();
        for ( $i = 1; $i <= 3; $i++ ) {
            $ts           = strtotime( "+{$i} months", strtotime( $last_ym . '-01' ) );
            $proj[ date( 'Y-m', $ts ) ] = $avg;
        }

        return compact( 'monthly', 'expenses_by_month', 'proj', 'has_cashflow', 'avg', 'using_fallback' );
    }

    private function render_cashflow( $from, $to ) {
        $data   = $this->get_cashflow_data( $from, $to );
        $labels = array( '30 días', '60 días', '90 días' );
        $colors = array( '#e8f4fd', '#e8f5e9', '#fff3e0' );
        $tcol   = array( '#1565c0', '#2e7d32', '#e65100' );
        ?>
        <h2>💧 Flujo de Caja Proyectado — <?php echo esc_html( $from ); ?> al <?php echo esc_html( $to ); ?></h2>

        <?php if ( $data['using_fallback'] ) : ?>
        <?php $this->render_analytics_fallback_notice(); ?>
        <?php endif; ?>

        <?php if ( ! $data['has_cashflow'] ) : ?>
        <div class="wbi-accrep-notice">
            💡 Activá el módulo de <strong>Flujo de Caja</strong> para registrar gastos y ver proyecciones más precisas.
            Los datos de ingresos se obtienen directamente de los pedidos de WooCommerce.
        </div>
        <?php endif; ?>

        <!-- Projection cards -->
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 20px;">
            <?php $i = 0; foreach ( $data['proj'] as $ym => $val ) : ?>
            <div style="background:<?php echo esc_attr( $colors[ $i ] ); ?>;border-radius:6px;padding:12px 20px;min-width:160px;">
                <div style="font-size:11px;color:#555;font-weight:600;text-transform:uppercase;">
                    Proyección <?php echo esc_html( $labels[ $i ] ); ?> (<?php echo esc_html( $ym ); ?>)
                </div>
                <div style="font-size:20px;font-weight:bold;color:<?php echo esc_attr( $tcol[ $i ] ); ?>;">
                    <?php echo esc_html( $this->fmt( $val ) ); ?>
                </div>
            </div>
            <?php $i++; endforeach; ?>
        </div>

        <h3>Ingresos Históricos</h3>
        <table class="wbi-accrep-table" style="max-width:500px;margin-bottom:20px;">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="wbi-accrep-num">Ingresos</th>
                    <th class="wbi-accrep-num">Gastos</th>
                    <th class="wbi-accrep-num">Saldo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $data['monthly'] as $m ) :
                $exp   = isset( $data['expenses_by_month'][ $m->ym ] ) ? $data['expenses_by_month'][ $m->ym ] : 0.0;
                $saldo = floatval( $m->income ) - $exp;
            ?>
            <tr>
                <td><?php echo esc_html( $m->ym ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $m->income ) ); ?></td>
                <td class="wbi-accrep-num"><?php echo esc_html( $this->fmt( $exp ) ); ?></td>
                <td class="wbi-accrep-num" style="font-weight:600;color:<?php echo $saldo >= 0 ? '#2e7d32' : '#d63638'; ?>;">
                    <?php echo esc_html( $this->fmt( $saldo ) ); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $data['proj'] ) ) : ?>
        <h3>Proyección (próximos 3 meses)</h3>
        <table class="wbi-accrep-table" style="max-width:380px;">
            <thead><tr><th>Mes</th><th class="wbi-accrep-num">Ingreso Proyectado</th></tr></thead>
            <tbody>
            <?php foreach ( $data['proj'] as $ym => $val ) : ?>
            <tr>
                <td><?php echo esc_html( $ym ); ?></td>
                <td class="wbi-accrep-num" style="color:#1565c0;font-style:italic;"><?php echo esc_html( $this->fmt( $val ) ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    private function csv_cashflow( $from, $to ) {
        $data   = $this->get_cashflow_data( $from, $to );
        $output = array();
        $output[] = array( 'Mes', 'Ingresos', 'Gastos', 'Saldo' );
        foreach ( $data['monthly'] as $m ) {
            $exp   = isset( $data['expenses_by_month'][ $m->ym ] ) ? $data['expenses_by_month'][ $m->ym ] : 0.0;
            $saldo = floatval( $m->income ) - $exp;
            $output[] = array( $m->ym, $this->fmt_csv( $m->income ), $this->fmt_csv( $exp ), $this->fmt_csv( $saldo ) );
        }
        $output[] = array();
        $output[] = array( 'Proyección', '', '', '' );
        $output[] = array( 'Mes', 'Ingreso Proyectado', '', '' );
        foreach ( $data['proj'] as $ym => $val ) {
            $output[] = array( $ym, $this->fmt_csv( $val ), '', '' );
        }
        return $output;
    }

    // -------------------------------------------------------------------------
    // NUMBER FORMATTING HELPERS
    // -------------------------------------------------------------------------

    /**
     * Render a notice informing the user that the fallback data source is active.
     */
    private function render_analytics_fallback_notice() {
        ?>
        <div class="wbi-accrep-notice wbi-accrep-notice--warning">
            ⚠️ <strong><?php esc_html_e( 'WooCommerce Analytics parece desactualizado', 'wbi-suite' ); ?></strong>
            <?php esc_html_e( '(Action Scheduler atrasado). Mostrando datos calculados directamente desde los pedidos.', 'wbi-suite' ); ?>
        </div>
        <?php
    }

    /**
     * Format a number as Argentine currency string ($ 1.234,56).
     */
    private function fmt( $value ) {
        return '$ ' . number_format( (float) $value, 2, ',', '.' );
    }

    /**
     * Format a number for CSV export (1234.56, no currency symbol).
     */
    private function fmt_csv( $value ) {
        return number_format( (float) $value, 2, '.', '' );
    }
}
