<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Cashflow Module — Flujo de Caja
 *
 * Muestra los últimos 6 meses de ingresos y gastos, y proyecta los
 * próximos 3 meses usando el promedio de los últimos 3 meses.
 * Permite registrar gastos manuales recurrentes o puntuales.
 */
class WBI_Cashflow_Module {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
        add_action( 'admin_post_wbi_save_expense', array( $this, 'handle_save_expense' ) );
        add_action( 'admin_post_wbi_delete_expense', array( $this, 'handle_delete_expense' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    // -------------------------------------------------------------------------
    // ADMIN MENU & ASSETS
    // -------------------------------------------------------------------------

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            '💰 Flujo de Caja',
            '💰 Flujo de Caja',
            'manage_options',
            'wbi-cashflow',
            array( $this, 'render' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-cashflow' ) === false ) return;
        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
    }

    // -------------------------------------------------------------------------
    // EXPENSE CRUD
    // -------------------------------------------------------------------------

    private function get_expenses() {
        return get_option( 'wbi_cashflow_expenses', array() );
    }

    private function save_expenses( $expenses ) {
        update_option( 'wbi_cashflow_expenses', $expenses );
    }

    public function handle_save_expense() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_expense_nonce', '_wbi_exp_nonce' );

        $expenses = $this->get_expenses();
        $id       = sanitize_text_field( wp_unslash( isset( $_POST['exp_id'] ) ? $_POST['exp_id'] : '' ) );

        $entry = array(
            'id'          => $id ?: uniqid( 'wbi_exp_', true ),
            'description' => sanitize_text_field( wp_unslash( isset( $_POST['exp_description'] ) ? $_POST['exp_description'] : '' ) ),
            'amount'      => max( 0, floatval( isset( $_POST['exp_amount'] ) ? $_POST['exp_amount'] : 0 ) ),
            'date'        => sanitize_text_field( wp_unslash( isset( $_POST['exp_date'] ) ? $_POST['exp_date'] : '' ) ),
            'recurring'   => ! empty( $_POST['exp_recurring'] ),
            'frequency'   => sanitize_text_field( wp_unslash( isset( $_POST['exp_frequency'] ) ? $_POST['exp_frequency'] : 'monthly' ) ),
        );

        $found = false;
        foreach ( $expenses as $k => $e ) {
            if ( $e['id'] === $entry['id'] ) {
                $expenses[ $k ] = $entry;
                $found = true;
                break;
            }
        }
        if ( ! $found ) $expenses[] = $entry;

        $this->save_expenses( $expenses );
        wp_safe_redirect( admin_url( 'admin.php?page=wbi-cashflow&saved=1' ) );
        exit;
    }

    public function handle_delete_expense() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_delete_expense' );

        $id       = sanitize_text_field( wp_unslash( isset( $_GET['exp_id'] ) ? $_GET['exp_id'] : '' ) );
        $expenses = $this->get_expenses();
        $expenses = array_values( array_filter( $expenses, function( $e ) use ( $id ) {
            return $e['id'] !== $id;
        } ) );
        $this->save_expenses( $expenses );
        wp_safe_redirect( admin_url( 'admin.php?page=wbi-cashflow&deleted=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // CASHFLOW CALCULATION
    // -------------------------------------------------------------------------

    /**
     * Returns an array of 9 months (6 past + 3 future) with income, expenses,
     * net flow and cumulative balance. Future months are projections.
     *
     * @return array
     */
    private function build_cashflow_table() {
        $statuses      = array( 'wc-completed' );
        $all_expenses  = $this->get_expenses();

        $months = array();
        // Build last 6 months (including current) + 3 future months = 9 total.
        for ( $i = -5; $i <= 3; $i++ ) {
            $ts    = strtotime( date( 'Y-m-01' ) . " +{$i} months" );
            $label = date( 'Y-m', $ts );
            $months[ $label ] = array(
                'label'      => $label,
                'is_future'  => $i > 0,
                'income'     => 0,
                'expenses'   => 0,
                'net'        => 0,
                'cumulative' => 0,
            );
        }

        // Fill income for past/current months
        $month_keys = array_keys( $months );
        $past_keys  = array_filter( $month_keys, function( $k ) { return $k <= date( 'Y-m' ); } );

        foreach ( $past_keys as $month_key ) {
            $s = $month_key . '-01';
            $e = date( 'Y-m-t', strtotime( $s ) );
            $income = (float) $this->engine->get_revenue( $s, $e, $statuses );
            $months[ $month_key ]['income'] = $income;
        }

        // Project future income: average of last 3 past months
        $past_incomes = array();
        foreach ( $past_keys as $k ) {
            $past_incomes[] = $months[ $k ]['income'];
        }
        $last3    = array_slice( $past_incomes, -3 );
        $avg_inc  = count( $last3 ) > 0 ? array_sum( $last3 ) / count( $last3 ) : 0;

        foreach ( $months as $k => &$m ) {
            if ( $m['is_future'] ) {
                $m['income'] = $avg_inc;
            }
        }
        unset( $m );

        // Fill expenses: sum all expenses that match each month
        foreach ( $all_expenses as $exp ) {
            $exp_date = isset( $exp['date'] ) ? $exp['date'] : '';
            if ( ! $exp_date ) continue;

            if ( ! empty( $exp['recurring'] ) ) {
                // Recurring: spread across all 9 months
                $freq = isset( $exp['frequency'] ) ? $exp['frequency'] : 'monthly';
                foreach ( $months as $k => &$m ) {
                    if ( $freq === 'monthly' ) {
                        $m['expenses'] += (float) $exp['amount'];
                    } elseif ( $freq === 'weekly' ) {
                        // 52 weeks / 12 months ≈ 4.33 average weeks per month
                        $m['expenses'] += (float) $exp['amount'] * 4.33;
                    }
                }
                unset( $m );
            } else {
                // One-time: only the matching month
                $exp_month = substr( $exp_date, 0, 7 );
                if ( isset( $months[ $exp_month ] ) ) {
                    $months[ $exp_month ]['expenses'] += (float) $exp['amount'];
                }
            }
        }

        // Calculate net and cumulative
        $cumulative = 0;
        foreach ( $months as $k => &$m ) {
            $m['net']        = $m['income'] - $m['expenses'];
            $cumulative     += $m['net'];
            $m['cumulative'] = $cumulative;
        }
        unset( $m );

        return $months;
    }

    // -------------------------------------------------------------------------
    // RENDER
    // -------------------------------------------------------------------------

    public function render() {
        $months   = $this->build_cashflow_table();
        $expenses = $this->get_expenses();

        // Check for negative projection in future months
        $negative_months = array();
        foreach ( $months as $m ) {
            if ( $m['is_future'] && $m['net'] < 0 ) {
                $negative_months[] = $m['label'];
            }
        }

        // Build chart data
        $chart_labels   = array();
        $chart_income   = array();
        $chart_expenses = array();
        $chart_net      = array();
        foreach ( $months as $m ) {
            $chart_labels[]   = $m['label'];
            $chart_income[]   = round( $m['income'],   2 );
            $chart_expenses[] = round( $m['expenses'], 2 );
            $chart_net[]      = round( $m['net'],      2 );
        }
        $chart_labels_json   = wp_json_encode( $chart_labels );
        $chart_income_json   = wp_json_encode( $chart_income );
        $chart_expenses_json = wp_json_encode( $chart_expenses );
        $chart_net_json      = wp_json_encode( $chart_net );

        // Editing expense?
        $editing  = null;
        $edit_id  = isset( $_GET['edit_exp'] ) ? sanitize_text_field( wp_unslash( $_GET['edit_exp'] ) ) : '';
        if ( $edit_id ) {
            foreach ( $expenses as $e ) {
                if ( $e['id'] === $edit_id ) {
                    $editing = $e;
                    break;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>💰 Flujo de Caja</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Gasto guardado correctamente.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>🗑️ Gasto eliminado.</p></div>
            <?php endif; ?>

            <?php if ( ! empty( $negative_months ) ) : ?>
                <div class="notice notice-error" style="border-left-color:#d63638;">
                    <p>
                        <strong>⚠️ Alerta de Flujo Negativo:</strong>
                        Se proyectan meses con flujo neto negativo:
                        <strong><?php echo esc_html( implode( ', ', $negative_months ) ); ?></strong>.
                        Revisá tus gastos o considerá aumentar los ingresos proyectados.
                    </p>
                </div>
            <?php endif; ?>

            <!-- GRÁFICO -->
            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-top:20px; margin-bottom:20px;">
                <h2 style="margin-top:0;">📈 Ingresos vs Gastos — 6 meses pasados + 3 proyectados</h2>
                <div style="position:relative; max-height:350px;">
                    <canvas id="wbiCashflowChart"></canvas>
                </div>
            </div>

            <!-- TABLA DE PROYECCIÓN -->
            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-bottom:20px;">
                <h2 style="margin-top:0;">📅 Tabla de Proyección Mensual</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th style="text-align:right;">Ingresos</th>
                            <th style="text-align:right;">Gastos</th>
                            <th style="text-align:right;">Flujo Neto</th>
                            <th style="text-align:right;">Saldo Acumulado</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $months as $m ) :
                        $row_style = $m['is_future'] ? 'background:#f8f9fa; font-style:italic; color:#50575e;' : '';
                        $net_color = $m['net'] >= 0 ? '#00a32a' : '#d63638';
                    ?>
                        <tr style="<?php echo esc_attr( $row_style ); ?>">
                            <td><strong><?php echo esc_html( $m['label'] ); ?></strong></td>
                            <td style="text-align:right; color:#00a32a;">
                                <?php echo wc_price( $m['income'] ); ?>
                            </td>
                            <td style="text-align:right; color:#d63638;">
                                <?php echo wc_price( $m['expenses'] ); ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:<?php echo esc_attr( $net_color ); ?>;">
                                <?php echo wc_price( $m['net'] ); ?>
                            </td>
                            <td style="text-align:right;">
                                <?php echo wc_price( $m['cumulative'] ); ?>
                            </td>
                            <td>
                                <?php echo $m['is_future']
                                    ? '<span style="color:#dba617;">📊 Proyección</span>'
                                    : '<span style="color:#2271b1;">✅ Real</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:12px; color:#646970; margin-top:10px;">
                    * Los ingresos futuros se proyectan como el promedio de los últimos 3 meses reales.
                </p>
            </div>

            <!-- GASTOS + FORMULARIO -->
            <div style="display:grid; grid-template-columns: 1fr 380px; gap:24px; align-items:start;">

                <!-- LISTADO DE GASTOS -->
                <div style="background:#fff; padding:20px; border:1px solid #c3c4c7;">
                    <h2 style="margin-top:0;">💸 Gastos Configurados</h2>
                    <?php if ( ! empty( $expenses ) ) : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Descripción</th>
                                <th style="text-align:right;">Monto</th>
                                <th>Fecha</th>
                                <th>Recurrente</th>
                                <th>Frecuencia</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $expenses as $exp ) :
                            $edit_url   = esc_url( admin_url( 'admin.php?page=wbi-cashflow&edit_exp=' . rawurlencode( $exp['id'] ) ) );
                            $delete_url = esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wbi_delete_expense&exp_id=' . rawurlencode( $exp['id'] ) ), 'wbi_delete_expense' ) );
                            $freq_label = ( isset( $exp['frequency'] ) && $exp['frequency'] === 'weekly' ) ? 'Semanal' : 'Mensual';
                        ?>
                            <tr>
                                <td><?php echo esc_html( $exp['description'] ); ?></td>
                                <td style="text-align:right;"><?php echo wc_price( $exp['amount'] ); ?></td>
                                <td><?php echo esc_html( $exp['date'] ); ?></td>
                                <td><?php echo ! empty( $exp['recurring'] ) ? '✅ Sí' : 'No'; ?></td>
                                <td><?php echo ! empty( $exp['recurring'] ) ? esc_html( $freq_label ) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo $edit_url; ?>" class="button button-small">✏️</a>
                                    <a href="<?php echo $delete_url; ?>" class="button button-small"
                                       onclick="return confirm('¿Eliminar este gasto?');">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                        <p style="color:#646970;">No hay gastos registrados aún.</p>
                    <?php endif; ?>
                </div>

                <!-- FORMULARIO -->
                <div style="background:#fff; padding:20px; border:1px solid #c3c4c7;">
                    <h2 style="margin-top:0;"><?php echo $editing ? '✏️ Editar Gasto' : '➕ Nuevo Gasto'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wbi_expense_nonce', '_wbi_exp_nonce' ); ?>
                        <input type="hidden" name="action" value="wbi_save_expense">
                        <input type="hidden" name="exp_id" value="<?php echo esc_attr( $editing ? $editing['id'] : '' ); ?>">

                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="padding:8px 0;"><label for="exp_description">Descripción</label></th>
                                <td style="padding:8px 0;">
                                    <input type="text" id="exp_description" name="exp_description" class="regular-text"
                                           value="<?php echo esc_attr( $editing ? $editing['description'] : '' ); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="exp_amount">Monto</label></th>
                                <td style="padding:8px 0;">
                                    <input type="number" id="exp_amount" name="exp_amount" min="0" step="0.01"
                                           class="regular-text"
                                           value="<?php echo esc_attr( $editing ? $editing['amount'] : '' ); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><label for="exp_date">Fecha</label></th>
                                <td style="padding:8px 0;">
                                    <input type="date" id="exp_date" name="exp_date"
                                           value="<?php echo esc_attr( $editing ? $editing['date'] : date( 'Y-m-d' ) ); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;">Recurrente</th>
                                <td style="padding:8px 0;">
                                    <input type="checkbox" id="exp_recurring" name="exp_recurring" value="1"
                                           <?php checked( $editing ? ! empty( $editing['recurring'] ) : false ); ?>
                                           onchange="document.getElementById('exp_freq_row').style.display=this.checked?'':'none'">
                                </td>
                            </tr>
                            <tr id="exp_freq_row" style="display:<?php echo ( $editing && ! empty( $editing['recurring'] ) ) ? '' : 'none'; ?>;">
                                <th style="padding:8px 0;"><label for="exp_frequency">Frecuencia</label></th>
                                <td style="padding:8px 0;">
                                    <select id="exp_frequency" name="exp_frequency">
                                        <option value="monthly" <?php selected( $editing ? ( isset( $editing['frequency'] ) ? $editing['frequency'] : 'monthly' ) : 'monthly', 'monthly' ); ?>>Mensual</option>
                                        <option value="weekly"  <?php selected( $editing ? ( isset( $editing['frequency'] ) ? $editing['frequency'] : 'monthly' ) : 'monthly', 'weekly' ); ?>>Semanal</option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p style="margin-top:15px;">
                            <button type="submit" class="button button-primary">
                                <?php echo $editing ? '💾 Actualizar' : '➕ Agregar'; ?>
                            </button>
                            <?php if ( $editing ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-cashflow' ) ); ?>"
                                   class="button" style="margin-left:5px;">Cancelar</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

            </div><!-- end grid -->
        </div>

        <script>
        (function(){
            var ctx = document.getElementById('wbiCashflowChart');
            if (!ctx || typeof Chart === 'undefined') return;
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo $chart_labels_json; ?>,
                    datasets: [
                        {
                            label: 'Ingresos',
                            data: <?php echo $chart_income_json; ?>,
                            borderColor: '#00a32a',
                            backgroundColor: 'rgba(0,163,42,0.1)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#00a32a'
                        },
                        {
                            label: 'Gastos',
                            data: <?php echo $chart_expenses_json; ?>,
                            borderColor: '#d63638',
                            backgroundColor: 'rgba(214,54,56,0.1)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#d63638'
                        },
                        {
                            label: 'Flujo Neto',
                            data: <?php echo $chart_net_json; ?>,
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34,113,177,0.05)',
                            fill: false,
                            tension: 0.3,
                            borderDash: [5, 3],
                            pointBackgroundColor: '#2271b1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: false }
                    }
                }
            });
        })();
        </script>
        <?php
    }
}
