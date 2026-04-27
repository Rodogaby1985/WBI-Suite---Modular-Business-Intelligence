<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI POS Cash Admin
 *
 * Adds an admin page "Caja POS" under the WooErp dashboard menu:
 *   - List of cash sessions (filters by date, user, status)
 *   - Session detail (movements + totals)
 *   - CSV export
 *
 * Permissions:
 *   - admin / manage_woocommerce => sees all sessions
 *   - wbi_cashier / wbi_vendedor => sees only their own sessions
 */
class WBI_POS_Cash_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 110 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX: CSV export
        add_action( 'wp_ajax_wbi_pos_export_sessions_csv', array( $this, 'handle_csv_export' ) );
    }

    // =========================================================================
    // MENU
    // =========================================================================

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            '🏦 Caja POS',
            '<span class="dashicons dashicons-money-alt" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Caja POS',
            'manage_woocommerce',
            'wbi-pos-cash',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wbi-pos-cash' ) ) {
            return;
        }
        wp_enqueue_style(
            'wbi-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/admin.css',
            array(),
            '8.0.0'
        );
    }

    // =========================================================================
    // PAGE RENDER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'wbi_pos_access' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'wbi-suite' ) );
        }

        // Sub-view: detail of a single session
        $session_id = isset( $_GET['session_id'] ) ? absint( $_GET['session_id'] ) : 0;
        if ( $session_id ) {
            $this->render_session_detail( $session_id );
            return;
        }

        $this->render_sessions_list();
    }

    // =========================================================================
    // SESSIONS LIST
    // =========================================================================

    private function render_sessions_list() {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wbi_pos_cash_sessions';

        $current_user_id      = get_current_user_id();
        $can_see_all          = current_user_can( 'manage_woocommerce' );

        // ── Filters ──────────────────────────────────────────────────────────
        $filter_user   = isset( $_GET['filter_user'] )   ? absint( $_GET['filter_user'] )                         : 0;
        $filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
        $filter_from   = isset( $_GET['filter_from'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) )  : '';
        $filter_to     = isset( $_GET['filter_to'] )     ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) )    : '';

        $where  = array( '1=1' );
        $params = array();

        if ( ! $can_see_all ) {
            $where[]  = 'seller_user_id = %d';
            $params[] = $current_user_id;
        } elseif ( $filter_user > 0 ) {
            $where[]  = 'seller_user_id = %d';
            $params[] = $filter_user;
        }

        if ( in_array( $filter_status, array( 'open', 'closed' ), true ) ) {
            $where[]  = 'status = %s';
            $params[] = $filter_status;
        }

        if ( $filter_from ) {
            $where[]  = 'DATE(opened_at) >= %s';
            $params[] = $filter_from;
        }

        if ( $filter_to ) {
            $where[]  = 'DATE(opened_at) <= %s';
            $params[] = $filter_to;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $params ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_sessions} WHERE {$where_sql} ORDER BY opened_at DESC LIMIT 200", ...$params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sessions = $wpdb->get_results( "SELECT * FROM {$table_sessions} ORDER BY opened_at DESC LIMIT 200" );
        }
        // phpcs:enable

        // Build user list for filter dropdown (admin only)
        $seller_users = array();
        if ( $can_see_all ) {
            $seller_users = get_users( array(
                'role__in' => array( 'administrator', 'shop_manager', 'wbi_cashier', 'wbi_vendedor' ),
                'fields'   => array( 'ID', 'display_name' ),
                'orderby'  => 'display_name',
                'order'    => 'ASC',
                'number'   => 200,
            ) );
        }

        $base_url = admin_url( 'admin.php?page=wbi-pos-cash' );
        ?>
        <div class="wrap">
            <h1>🏦 Caja POS — Sesiones</h1>

            <!-- ── Filters ─────────────────────────────────────────────── -->
            <form method="get" action="" style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                <input type="hidden" name="page" value="wbi-pos-cash">

                <div>
                    <label style="display:block;font-size:12px;margin-bottom:2px;"><?php esc_html_e( 'Desde', 'wbi-suite' ); ?></label>
                    <input type="date" name="filter_from" value="<?php echo esc_attr( $filter_from ); ?>" class="regular-text" style="width:140px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;margin-bottom:2px;"><?php esc_html_e( 'Hasta', 'wbi-suite' ); ?></label>
                    <input type="date" name="filter_to" value="<?php echo esc_attr( $filter_to ); ?>" class="regular-text" style="width:140px;">
                </div>

                <?php if ( $can_see_all && $seller_users ) : ?>
                <div>
                    <label style="display:block;font-size:12px;margin-bottom:2px;"><?php esc_html_e( 'Cajero', 'wbi-suite' ); ?></label>
                    <select name="filter_user">
                        <option value="0"><?php esc_html_e( 'Todos', 'wbi-suite' ); ?></option>
                        <?php foreach ( $seller_users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $filter_user, $u->ID ); ?>>
                                <?php echo esc_html( $u->display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label style="display:block;font-size:12px;margin-bottom:2px;"><?php esc_html_e( 'Estado', 'wbi-suite' ); ?></label>
                    <select name="filter_status">
                        <option value=""><?php esc_html_e( 'Todos', 'wbi-suite' ); ?></option>
                        <option value="open"  <?php selected( $filter_status, 'open' ); ?>><?php esc_html_e( 'Abierta', 'wbi-suite' ); ?></option>
                        <option value="closed" <?php selected( $filter_status, 'closed' ); ?>><?php esc_html_e( 'Cerrada', 'wbi-suite' ); ?></option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="button button-primary">🔍 <?php esc_html_e( 'Filtrar', 'wbi-suite' ); ?></button>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Limpiar', 'wbi-suite' ); ?></a>
                </div>

                <!-- CSV Export -->
                <div style="margin-left:auto;">
                    <?php
                    $export_params = array_filter( array(
                        'action'        => 'wbi_pos_export_sessions_csv',
                        'nonce'         => wp_create_nonce( 'wbi_pos_export_csv' ),
                        'filter_user'   => $filter_user   ?: '',
                        'filter_status' => $filter_status ?: '',
                        'filter_from'   => $filter_from   ?: '',
                        'filter_to'     => $filter_to     ?: '',
                    ) );
                    ?>
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?' . http_build_query( $export_params ) ) ); ?>"
                       class="button">📥 <?php esc_html_e( 'Exportar CSV', 'wbi-suite' ); ?></a>
                </div>
            </form>

            <!-- ── Sessions table ──────────────────────────────────────── -->
            <?php if ( empty( $sessions ) ) : ?>
                <p><?php esc_html_e( 'No se encontraron sesiones con los filtros aplicados.', 'wbi-suite' ); ?></p>
            <?php else : ?>
            <table class="widefat striped wbi-sortable" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ( $can_see_all ) : ?>
                        <th><?php esc_html_e( 'Cajero', 'wbi-suite' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'Apertura', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Cierre', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Efectivo inicial', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Efectivo esperado', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Efectivo contado', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Diferencia', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Estado', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'wbi-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sessions as $session ) :
                    $seller    = get_userdata( $session->seller_user_id );
                    $status_lbl = 'open' === $session->status
                        ? '<span style="color:#00a32a;font-weight:600;">🟢 ' . esc_html__( 'Abierta', 'wbi-suite' ) . '</span>'
                        : '<span style="color:#d63638;font-weight:600;">🔴 ' . esc_html__( 'Cerrada', 'wbi-suite' ) . '</span>';

                    // Calculate expected cash for display
                    $totals        = WBI_POS_Cash_Movements::get_session_totals( $session->id, $session->opening_cash );
                    $expected_cash = $totals['expected_cash'];
                    $diff          = ( 'closed' === $session->status && null !== $session->closing_cash_counted )
                        ? round( (float) $session->closing_cash_counted - $expected_cash, 2 )
                        : null;
                    $diff_style    = '';
                    if ( null !== $diff ) {
                        $diff_style = $diff < 0 ? 'color:#d63638;font-weight:600;' : ( $diff > 0 ? 'color:#00a32a;font-weight:600;' : '' );
                    }

                    $detail_url = add_query_arg( array( 'page' => 'wbi-pos-cash', 'session_id' => $session->id ), admin_url( 'admin.php' ) );
                ?>
                <tr>
                    <td><?php echo esc_html( $session->id ); ?></td>
                    <?php if ( $can_see_all ) : ?>
                    <td><?php echo esc_html( $seller ? $seller->display_name : '#' . $session->seller_user_id ); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html( $session->opened_at ); ?></td>
                    <td><?php echo esc_html( $session->closed_at ?: '—' ); ?></td>
                    <td><?php echo esc_html( number_format( (float) $session->opening_cash, 2 ) ); ?></td>
                    <td><?php echo esc_html( number_format( $expected_cash, 2 ) ); ?></td>
                    <td><?php echo 'closed' === $session->status && null !== $session->closing_cash_counted ? esc_html( number_format( (float) $session->closing_cash_counted, 2 ) ) : '—'; ?></td>
                    <td style="<?php echo esc_attr( $diff_style ); ?>">
                        <?php echo null !== $diff ? esc_html( number_format( $diff, 2 ) ) : '—'; ?>
                    </td>
                    <td><?php echo wp_kses_post( $status_lbl ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
                            🔍 <?php esc_html_e( 'Ver detalle', 'wbi-suite' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // SESSION DETAIL
    // =========================================================================

    private function render_session_detail( $session_id ) {
        $session = WBI_POS_Cash_Sessions::get_session( $session_id );
        if ( ! $session ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Sesión no encontrada.', 'wbi-suite' ) . '</p></div>';
            return;
        }

        // Permission: non-admin can only see their own sessions
        $current_user_id = get_current_user_id();
        $can_see_all     = current_user_can( 'manage_woocommerce' );
        if ( ! $can_see_all && (int) $session->seller_user_id !== $current_user_id && (int) $session->operator_user_id !== $current_user_id ) {
            wp_die( esc_html__( 'Sin permisos para ver esta sesión.', 'wbi-suite' ) );
        }

        $seller      = get_userdata( $session->seller_user_id );
        $operator    = get_userdata( $session->operator_user_id );
        $movements   = WBI_POS_Cash_Movements::get_movements( $session_id );
        $totals      = WBI_POS_Cash_Movements::get_session_totals( $session_id, $session->opening_cash );
        $expected    = $totals['expected_cash'];
        $counted     = ( 'closed' === $session->status && null !== $session->closing_cash_counted )
                         ? (float) $session->closing_cash_counted
                         : null;
        $difference  = ( null !== $counted ) ? round( $counted - $expected, 2 ) : null;

        $back_url    = admin_url( 'admin.php?page=wbi-pos-cash' );

        $method_labels = array(
            'cash'     => __( 'Efectivo', 'wbi-suite' ),
            'card'     => __( 'Tarjeta', 'wbi-suite' ),
            'debit'    => __( 'Tarjeta Débito', 'wbi-suite' ),
            'credit'   => __( 'Tarjeta Crédito', 'wbi-suite' ),
            'transfer' => __( 'Transferencia', 'wbi-suite' ),
            'qr'       => __( 'QR / MercadoPago', 'wbi-suite' ),
            'other'    => __( 'Otro', 'wbi-suite' ),
        );

        $type_labels = array(
            'sale_income'    => __( 'Venta', 'wbi-suite' ),
            'manual_income'  => __( 'Ingreso manual', 'wbi-suite' ),
            'manual_expense' => __( 'Egreso manual', 'wbi-suite' ),
            'withdrawal'     => __( 'Retiro', 'wbi-suite' ),
            'deposit'        => __( 'Depósito', 'wbi-suite' ),
        );
        ?>
        <div class="wrap">
            <h1>
                🏦 <?php esc_html_e( 'Caja POS — Detalle de sesión', 'wbi-suite' ); ?> #<?php echo esc_html( $session->id ); ?>
            </h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-bottom:16px;">
                ← <?php esc_html_e( 'Volver al listado', 'wbi-suite' ); ?>
            </a>

            <!-- ── Session summary card ─────────────────────────────────── -->
            <table class="widefat" style="max-width:560px; margin-bottom:24px;">
                <tbody>
                    <tr>
                        <th style="width:200px;"><?php esc_html_e( 'Cajero', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $seller ? $seller->display_name : '—' ); ?></td>
                    </tr>
                    <?php if ( $operator && $operator->ID !== ( $seller ? $seller->ID : 0 ) ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Operador', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $operator->display_name ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Apertura', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $session->opened_at ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Cierre', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $session->closed_at ?: '—' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Estado', 'wbi-suite' ); ?></th>
                        <td>
                            <?php if ( 'open' === $session->status ) : ?>
                                <span style="color:#00a32a;font-weight:600;">🟢 <?php esc_html_e( 'Abierta', 'wbi-suite' ); ?></span>
                            <?php else : ?>
                                <span style="color:#d63638;font-weight:600;">🔴 <?php esc_html_e( 'Cerrada', 'wbi-suite' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Efectivo inicial', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( number_format( (float) $session->opening_cash, 2 ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Efectivo esperado', 'wbi-suite' ); ?></th>
                        <td><strong><?php echo esc_html( number_format( $expected, 2 ) ); ?></strong></td>
                    </tr>
                    <?php if ( null !== $counted ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Efectivo contado', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( number_format( $counted, 2 ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Diferencia', 'wbi-suite' ); ?></th>
                        <td style="<?php echo esc_attr( $difference < 0 ? 'color:#d63638;font-weight:600;' : ( $difference > 0 ? 'color:#00a32a;font-weight:600;' : '' ) ); ?>">
                            <?php echo esc_html( number_format( $difference, 2 ) ); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $session->opening_note ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Nota apertura', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $session->opening_note ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $session->closing_note ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Nota cierre', 'wbi-suite' ); ?></th>
                        <td><?php echo esc_html( $session->closing_note ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- ── Totals by method ─────────────────────────────────────── -->
            <?php if ( ! empty( $totals['by_method'] ) ) : ?>
            <h2><?php esc_html_e( 'Totales por medio de pago (ingresos)', 'wbi-suite' ); ?></h2>
            <table class="widefat striped" style="max-width:400px; margin-bottom:24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Método', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'wbi-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $totals['by_method'] as $method => $amount ) : ?>
                    <tr>
                        <td><?php echo esc_html( $method_labels[ $method ] ?? $method ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $amount, 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="font-weight:600;">
                        <td><?php esc_html_e( 'Total ingresos', 'wbi-suite' ); ?></td>
                        <td><?php echo esc_html( number_format( $totals['total_income'], 2 ) ); ?></td>
                    </tr>
                    <?php if ( $totals['total_expense'] > 0 ) : ?>
                    <tr style="color:#d63638;">
                        <td><?php esc_html_e( 'Total egresos', 'wbi-suite' ); ?></td>
                        <td><?php echo esc_html( number_format( $totals['total_expense'], 2 ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- ── Movements list ───────────────────────────────────────── -->
            <h2><?php esc_html_e( 'Movimientos', 'wbi-suite' ); ?></h2>
            <?php if ( empty( $movements ) ) : ?>
                <p><?php esc_html_e( 'No hay movimientos registrados en esta sesión.', 'wbi-suite' ); ?></p>
            <?php else : ?>
            <table class="widefat striped wbi-sortable" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Fecha/Hora', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Tipo', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Método', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Monto', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Referencia', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Notas', 'wbi-suite' ); ?></th>
                        <th><?php esc_html_e( 'Registrado por', 'wbi-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $movements as $mov ) :
                    $creator   = get_userdata( $mov->created_by );
                    $is_income = in_array( $mov->type, array( 'sale_income', 'manual_income', 'deposit' ), true );
                    $amount_style = $is_income ? 'color:#00a32a;font-weight:600;' : 'color:#d63638;font-weight:600;';
                    $amount_sign  = $is_income ? '+' : '-';
                ?>
                <tr>
                    <td><?php echo esc_html( $mov->created_at ); ?></td>
                    <td><?php echo esc_html( $type_labels[ $mov->type ] ?? $mov->type ); ?></td>
                    <td><?php echo esc_html( $method_labels[ $mov->method ] ?? $mov->method ); ?></td>
                    <td style="<?php echo esc_attr( $amount_style ); ?>">
                        <?php echo esc_html( $amount_sign . number_format( (float) $mov->amount, 2 ) ); ?>
                    </td>
                    <td>
                        <?php if ( $mov->reference ) :
                            // If it looks like an order ID, link it
                            if ( is_numeric( $mov->reference ) && absint( $mov->reference ) > 0 ) :
                                $order_edit = admin_url( 'post.php?post=' . absint( $mov->reference ) . '&action=edit' );
                                if ( function_exists( 'wc_get_order_edit_link' ) ) {
                                    $order_edit = wc_get_order_edit_link( absint( $mov->reference ) );
                                }
                                ?>
                                <a href="<?php echo esc_url( $order_edit ); ?>" target="_blank">
                                    #<?php echo esc_html( $mov->reference ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $mov->reference ); ?>
                            <?php endif; ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $mov->notes ?: '—' ); ?></td>
                    <td><?php echo esc_html( $creator ? $creator->display_name : '#' . $mov->created_by ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // CSV EXPORT
    // =========================================================================

    public function handle_csv_export() {
        if ( ! check_ajax_referer( 'wbi_pos_export_csv', 'nonce', false ) ) {
            wp_die( 'Acceso no autorizado.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'wbi_pos_access' ) ) {
            wp_die( 'Sin permisos.' );
        }

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wbi_pos_cash_sessions';

        $current_user_id = get_current_user_id();
        $can_see_all     = current_user_can( 'manage_woocommerce' );

        $filter_user   = isset( $_GET['filter_user'] )   ? absint( $_GET['filter_user'] )                         : 0;
        $filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
        $filter_from   = isset( $_GET['filter_from'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) )  : '';
        $filter_to     = isset( $_GET['filter_to'] )     ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) )    : '';

        $where  = array( '1=1' );
        $params = array();

        if ( ! $can_see_all ) {
            $where[]  = 'seller_user_id = %d';
            $params[] = $current_user_id;
        } elseif ( $filter_user > 0 ) {
            $where[]  = 'seller_user_id = %d';
            $params[] = $filter_user;
        }

        if ( in_array( $filter_status, array( 'open', 'closed' ), true ) ) {
            $where[]  = 'status = %s';
            $params[] = $filter_status;
        }

        if ( $filter_from ) {
            $where[]  = 'DATE(opened_at) >= %s';
            $params[] = $filter_from;
        }

        if ( $filter_to ) {
            $where[]  = 'DATE(opened_at) <= %s';
            $params[] = $filter_to;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $params ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_sessions} WHERE {$where_sql} ORDER BY opened_at DESC LIMIT 2000", ...$params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sessions = $wpdb->get_results( "SELECT * FROM {$table_sessions} ORDER BY opened_at DESC LIMIT 2000" );
        }
        // phpcs:enable

        // Output headers
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="caja-pos-' . gmdate( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8 compatibility
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'ID',
            'Cajero',
            'Apertura',
            'Cierre',
            'Estado',
            'Efectivo inicial',
            'Total ingresos',
            'Total egresos (manuales)',
            'Efectivo esperado',
            'Efectivo contado',
            'Diferencia',
            'Nota apertura',
            'Nota cierre',
        ), ';' );

        foreach ( $sessions as $session ) {
            $seller = get_userdata( $session->seller_user_id );
            $totals = WBI_POS_Cash_Movements::get_session_totals( $session->id, $session->opening_cash );
            $counted   = ( 'closed' === $session->status && null !== $session->closing_cash_counted )
                ? (float) $session->closing_cash_counted
                : '';
            $difference = '' !== $counted ? round( $counted - $totals['expected_cash'], 2 ) : '';

            fputcsv( $out, array(
                $session->id,
                $seller ? $seller->display_name : '#' . $session->seller_user_id,
                $session->opened_at,
                $session->closed_at ?: '',
                $session->status,
                number_format( (float) $session->opening_cash, 2, '.', '' ),
                number_format( $totals['total_income'], 2, '.', '' ),
                number_format( $totals['total_expense'], 2, '.', '' ),
                number_format( $totals['expected_cash'], 2, '.', '' ),
                '' !== $counted ? number_format( $counted, 2, '.', '' ) : '',
                '' !== $difference ? number_format( $difference, 2, '.', '' ) : '',
                $session->opening_note ?: '',
                $session->closing_note ?: '',
            ), ';' );
        }

        fclose( $out );
        exit;
    }
}
