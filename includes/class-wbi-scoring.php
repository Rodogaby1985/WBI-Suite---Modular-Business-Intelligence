<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Scoring_Module {

    public function __construct() {
        // Admin submenu
        add_action( 'admin_menu', array( $this, 'add_submenu' ), 100 );

        // WP Cron daily recalculation — register the action handler
        add_action( 'wbi_scoring_daily', array( $this, 'compute_all_scores' ) );

        // Schedule if not already scheduled (uses admin_init to avoid running on every front-end request)
        add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );

        // AJAX: manual recalculation
        add_action( 'wp_ajax_wbi_scoring_recalc', array( $this, 'ajax_recalc' ) );

        // WooCommerce: order edit screen badge
        add_action( 'add_meta_boxes', array( $this, 'add_order_score_metabox' ) );

        // WC Users list column
        add_filter( 'manage_users_columns',        array( $this, 'add_score_user_column' ) );
        add_filter( 'manage_users_custom_column',  array( $this, 'render_score_user_column' ), 10, 3 );

        // CSV export
        add_action( 'admin_post_wbi_scoring_export', array( $this, 'handle_scoring_export' ) );
    }

    /**
     * Schedule the daily cron event if it is not already registered.
     * Hooked to admin_init so it only runs once per admin request, not on every page load.
     */
    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'wbi_scoring_daily' ) ) {
            wp_schedule_event( time(), 'daily', 'wbi_scoring_daily' );
        }
    }

    /**
     * Clear the scheduled cron event. Call this on plugin deactivation.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'wbi_scoring_daily' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wbi_scoring_daily' );
        }
    }

    // -------------------------------------------------------------------------
    // Scoring algorithm helpers
    // -------------------------------------------------------------------------

    private function score_recency( $days ) {
        if ( $days <= 30 )  return 5;
        if ( $days <= 60 )  return 4;
        if ( $days <= 90 )  return 3;
        if ( $days <= 180 ) return 2;
        return 1;
    }

    private function score_frequency( $count ) {
        if ( $count >= 10 ) return 5;
        if ( $count >= 7 )  return 4;
        if ( $count >= 4 )  return 3;
        if ( $count >= 2 )  return 2;
        return 1;
    }

    /**
     * Score monetary value based on percentile thresholds.
     * Splits sorted totals into 5 equal buckets (top 20% = 5, etc.)
     *
     * @param float $total
     * @param array $sorted_totals All totals sorted ascending.
     * @return int
     */
    private function score_monetary( $total, array $sorted_totals ) {
        $n = count( $sorted_totals );
        if ( $n === 0 ) return 1;
        $pos = array_search( $total, $sorted_totals );
        if ( $pos === false ) {
            // Find position by value
            $pos = 0;
            foreach ( $sorted_totals as $i => $v ) {
                if ( $v <= $total ) $pos = $i;
            }
        }
        $pct = ( $pos + 1 ) / $n; // 0..1 where 1 = top
        if ( $pct >= 0.8 ) return 5;
        if ( $pct >= 0.6 ) return 4;
        if ( $pct >= 0.4 ) return 3;
        if ( $pct >= 0.2 ) return 2;
        return 1;
    }

    private function classify( $score ) {
        if ( $score >= 13 ) return 'A';
        if ( $score >= 10 ) return 'B';
        if ( $score >= 7 )  return 'C';
        return 'D';
    }

    // -------------------------------------------------------------------------
    // Compute all scores — single SQL query + batch user meta updates
    // -------------------------------------------------------------------------

    public function compute_all_scores() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm_cust.meta_value AS user_id,
                    MAX(p.post_date)   AS last_order,
                    COUNT(p.ID)        AS order_count,
                    SUM(pm_total.meta_value) AS total_spent
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_cust  ON p.ID = pm_cust.post_id  AND pm_cust.meta_key  = '_customer_user'
             JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
             WHERE p.post_type   = 'shop_order'
               AND p.post_status IN ('wc-completed','wc-processing')
               AND pm_cust.meta_value > 0
               AND p.post_date >= DATE_SUB(NOW(), INTERVAL 365 DAY)
             GROUP BY pm_cust.meta_value"
        );

        if ( empty( $rows ) ) return;

        // Build sorted totals for monetary percentile scoring
        $sorted_totals = array_map( function( $r ) { return floatval( $r->total_spent ); }, $rows );
        sort( $sorted_totals );

        $now       = current_time( 'mysql' );
        $batch     = array();
        $batch_size = 100;

        foreach ( $rows as $row ) {
            $user_id   = intval( $row->user_id );
            $days_ago  = (int) ( ( strtotime( $now ) - strtotime( $row->last_order ) ) / DAY_IN_SECONDS );
            $r_score   = $this->score_recency( $days_ago );
            $f_score   = $this->score_frequency( intval( $row->order_count ) );
            $m_score   = $this->score_monetary( floatval( $row->total_spent ), $sorted_totals );
            $total_score = $r_score + $f_score + $m_score;
            $class       = $this->classify( $total_score );

            $batch[] = array(
                'user_id'     => $user_id,
                'score'       => $total_score,
                'class'       => $class,
                'last_order'  => $row->last_order,
                'total_spent' => $row->total_spent,
                'order_count' => $row->order_count,
            );

            if ( count( $batch ) >= $batch_size ) {
                $this->flush_score_batch( $batch, $now );
                $batch = array();
            }
        }

        if ( ! empty( $batch ) ) {
            $this->flush_score_batch( $batch, $now );
        }
    }

    private function flush_score_batch( array $batch, $date ) {
        foreach ( $batch as $item ) {
            update_user_meta( $item['user_id'], '_wbi_score',         $item['score'] );
            update_user_meta( $item['user_id'], '_wbi_score_class',   $item['class'] );
            update_user_meta( $item['user_id'], '_wbi_score_date',    $date );
            update_user_meta( $item['user_id'], '_wbi_score_total',   $item['total_spent'] );
            update_user_meta( $item['user_id'], '_wbi_score_orders',  $item['order_count'] );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: manual recalculation
    // -------------------------------------------------------------------------

    public function ajax_recalc() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos' );
        if ( ! wp_verify_nonce( $_POST['_wbi_scoring_nonce'] ?? '', 'wbi_scoring_recalc' ) ) wp_die( 'Nonce inválido' );

        $this->compute_all_scores();
        wp_send_json_success( array( 'message' => 'Scores recalculados correctamente.' ) );
    }

    // -------------------------------------------------------------------------
    // Admin submenu
    // -------------------------------------------------------------------------

    public function add_submenu() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Scoring Clientes',
            '<span class="dashicons dashicons-star-filled" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Scoring Clientes',
            'manage_woocommerce',
            'wbi-scoring',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        // Handle manual recalculation via form POST
        if ( isset( $_POST['wbi_scoring_recalc_submit'] ) ) {
            if ( ! wp_verify_nonce( $_POST['_wbi_scoring_nonce'] ?? '', 'wbi_scoring_recalc_page' ) ) {
                wp_die( 'Nonce inválido' );
            }
            if ( current_user_can( 'manage_options' ) ) {
                $this->compute_all_scores();
                echo '<div class="notice notice-success"><p>✅ Scores recalculados correctamente.</p></div>';
            }
        }

        $per_page   = 20;
        $paged      = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset     = ( $paged - 1 ) * $per_page;
        $filter_class = isset( $_GET['score_class'] ) ? sanitize_text_field( wp_unslash( $_GET['score_class'] ) ) : '';

        // Count distribution
        $distribution = $this->get_class_distribution();
        $total_scored = array_sum( array_column( $distribution, 'count' ) );

        // User list
        $users_data  = $this->get_scored_users( $per_page, $offset, $filter_class );
        $total_users = $this->get_scored_users_count( $filter_class );
        $total_pages = ceil( $total_users / $per_page );

        $export_url = add_query_arg( array(
            'action'   => 'wbi_scoring_export',
            '_wpnonce' => wp_create_nonce( 'wbi_scoring_export' ),
        ), admin_url( 'admin-post.php' ) );

        $class_colors = array( 'A' => '#00a32a', 'B' => '#2271b1', 'C' => '#dba617', 'D' => '#d63638' );
        ?>
        <div class="wrap">
            <h1>Scoring Clientes</h1>

            <div class="notice notice-info"><p><strong>Scoring RFM</strong>: Clasifica clientes según Recencia (cuándo compraron), Frecuencia (cuántas veces) y Valor Monetario (cuánto gastaron). Cada dimensión se puntúa del 1 al 5. Un cliente con score 15 es el más valioso. El recálculo es automático (1 vez por día) o manual con el botón abajo.</p></div>

            <!-- Recalculate button -->
            <form method="post" style="margin-bottom:20px;">
                <?php wp_nonce_field( 'wbi_scoring_recalc_page', '_wbi_scoring_nonce' ); ?>
                <button type="submit" name="wbi_scoring_recalc_submit" class="button button-secondary">Recalcular Scores</button>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button">Exportar CSV</a>
            </form>

            <!-- Summary cards -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; max-width:800px;">
                <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid #2271b1;border-radius:3px;padding:16px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo intval( $total_scored ); ?></div>
                    <div style="color:#555;margin-top:4px;">Total con Score</div>
                </div>
                <?php foreach ( array( 'A', 'B', 'C', 'D' ) as $cl ) :
                    $cnt = 0;
                    foreach ( $distribution as $d ) { if ( $d->class === $cl ) { $cnt = $d->count; break; } }
                    $pct = $total_scored > 0 ? round( $cnt / $total_scored * 100, 1 ) : 0;
                    $color = $class_colors[ $cl ];
                ?>
                <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid <?php echo esc_attr( $color ); ?>;border-radius:3px;padding:16px;text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $color ); ?>;"><?php echo intval( $cnt ); ?></div>
                    <div style="color:#555;margin-top:4px;">Clase <?php echo esc_html( $cl ); ?> (<?php echo esc_html( $pct ); ?>%)</div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div style="background:#fff;padding:15px 20px;border:1px solid #c3c4c7;border-left:4px solid #2271b1;margin-bottom:20px;">
                <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="wbi-scoring">
                    <label>Clase:</label>
                    <select name="score_class">
                        <option value="">— Todas —</option>
                        <?php foreach ( array( 'A', 'B', 'C', 'D' ) as $cl ) : ?>
                            <option value="<?php echo esc_attr( $cl ); ?>" <?php selected( $filter_class, $cl ); ?>>Clase <?php echo esc_html( $cl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">Filtrar</button>
                </form>
            </div>

            <p style="color:#555;">Total: <strong><?php echo intval( $total_users ); ?></strong> clientes</p>

            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Score</th>
                        <th>Clase</th>
                        <th>Última Compra</th>
                        <th>Total Gastado</th>
                        <th>Pedidos (365d)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $users_data ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;color:#888;">Sin datos de scoring. Haz clic en "Recalcular Scores" para comenzar.</td></tr>
                <?php else : ?>
                    <?php foreach ( $users_data as $u ) :
                        $score = $u->score;
                        $class = get_user_meta( $u->ID, '_wbi_score_class', true );
                        $date  = get_user_meta( $u->ID, '_wbi_score_date', true );
                        $color = isset( $class_colors[ $class ] ) ? $class_colors[ $class ] : '#555';
                        $edit_url = get_edit_user_link( $u->ID );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $u->display_name ); ?></a></td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td style="font-weight:700;"><?php echo esc_html( $score ?: '—' ); ?></td>
                        <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;"><?php echo esc_html( $class ?: '—' ); ?></td>
                        <td><?php echo $date ? esc_html( date_i18n( 'd/m/Y', strtotime( $date ) ) ) : '—'; ?></td>
                        <td><?php echo $u->total_spent ? wc_price( $u->total_spent ) : '—'; ?></td>
                        <td><?php echo esc_html( $u->order_count ?: '—' ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
            endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Data helpers for admin page
    // -------------------------------------------------------------------------

    private function get_class_distribution() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT um.meta_value AS class, COUNT(*) AS count
             FROM {$wpdb->usermeta} um
             WHERE um.meta_key = '_wbi_score_class'
               AND um.meta_value != ''
             GROUP BY um.meta_value
             ORDER BY um.meta_value ASC"
        );
    }

    private function get_scored_users( $per_page, $offset, $class_filter = '' ) {
        global $wpdb;

        $class_sql = '';
        $args      = array();

        if ( $class_filter ) {
            $class_sql = " AND um_class.meta_value = %s";
            $args[]    = $class_filter;
        }

        $args[] = $per_page;
        $args[] = $offset;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email,
                    um_score.meta_value AS score,
                    um_total.meta_value AS total_spent,
                    um_orders.meta_value AS order_count
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} um_class  ON u.ID = um_class.user_id  AND um_class.meta_key  = '_wbi_score_class'
             LEFT JOIN {$wpdb->usermeta} um_score  ON u.ID = um_score.user_id  AND um_score.meta_key  = '_wbi_score'
             LEFT JOIN {$wpdb->usermeta} um_total  ON u.ID = um_total.user_id  AND um_total.meta_key  = '_wbi_score_total'
             LEFT JOIN {$wpdb->usermeta} um_orders ON u.ID = um_orders.user_id AND um_orders.meta_key = '_wbi_score_orders'
             WHERE 1=1{$class_sql}
             ORDER BY CAST(um_score.meta_value AS SIGNED) DESC, u.display_name ASC
             LIMIT %d OFFSET %d",
            ...$args
        );
        // phpcs:enable

        return $wpdb->get_results( $sql );
    }

    private function get_scored_users_count( $class_filter = '' ) {
        global $wpdb;
        if ( $class_filter ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                 WHERE meta_key = '_wbi_score_class' AND meta_value = %s",
                $class_filter
            ) );
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_wbi_score_class' AND meta_value != ''"
        );
    }

    // -------------------------------------------------------------------------
    // WooCommerce order edit screen: Score badge
    // -------------------------------------------------------------------------

    public function add_order_score_metabox() {
        add_meta_box(
            'wbi_order_score',
            '⭐ Score WBI',
            array( $this, 'render_order_score_metabox' ),
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_order_score_metabox( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) {
            echo '<p style="color:#888;">Cliente invitado — sin scoring.</p>';
            return;
        }

        $score = get_user_meta( $user_id, '_wbi_score',       true );
        $class = get_user_meta( $user_id, '_wbi_score_class', true );
        $date  = get_user_meta( $user_id, '_wbi_score_date',  true );

        $colors = array( 'A' => '#00a32a', 'B' => '#2271b1', 'C' => '#dba617', 'D' => '#d63638' );
        $color  = isset( $colors[ $class ] ) ? $colors[ $class ] : '#888';

        if ( ! $score ) {
            echo '<p style="color:#888;">Sin score calculado aún.</p>';
            return;
        }

        echo '<p><strong>Score:</strong> <span style="font-size:18px;font-weight:700;">' . esc_html( $score ) . '/15</span></p>';
        echo '<p><strong>Clase:</strong> <span style="color:' . esc_attr( $color ) . ';font-size:16px;font-weight:700;">' . esc_html( $class ) . '</span></p>';
        if ( $date ) {
            echo '<p style="color:#888;font-size:11px;">Calculado: ' . esc_html( date_i18n( 'd/m/Y', strtotime( $date ) ) ) . '</p>';
        }
    }

    // -------------------------------------------------------------------------
    // Users list column
    // -------------------------------------------------------------------------

    public function add_score_user_column( $columns ) {
        $columns['wbi_score'] = '⭐ Score WBI';
        return $columns;
    }

    public function render_score_user_column( $output, $column_name, $user_id ) {
        if ( 'wbi_score' !== $column_name ) return $output;

        $score = get_user_meta( $user_id, '_wbi_score',       true );
        $class = get_user_meta( $user_id, '_wbi_score_class', true );

        if ( ! $score ) return '<span style="color:#888;">—</span>';

        $colors = array( 'A' => '#00a32a', 'B' => '#2271b1', 'C' => '#dba617', 'D' => '#d63638' );
        $color  = isset( $colors[ $class ] ) ? $colors[ $class ] : '#555';

        return '<span style="color:' . esc_attr( $color ) . ';font-weight:700;">' . esc_html( $score ) . ' (' . esc_html( $class ) . ')</span>';
    }

    // -------------------------------------------------------------------------
    // Public method for CSV export
    // -------------------------------------------------------------------------

    public static function get_all_scored_users_for_export() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT u.ID, u.display_name, u.user_email,
                    um_score.meta_value AS score,
                    um_class.meta_value AS class,
                    um_date.meta_value  AS score_date
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} um_score ON u.ID = um_score.user_id AND um_score.meta_key = '_wbi_score'
             JOIN {$wpdb->usermeta} um_class ON u.ID = um_class.user_id AND um_class.meta_key = '_wbi_score_class'
             LEFT JOIN {$wpdb->usermeta} um_date  ON u.ID = um_date.user_id  AND um_date.meta_key  = '_wbi_score_date'
             ORDER BY CAST(um_score.meta_value AS SIGNED) DESC
             LIMIT 10000"
        );
    }

    // -------------------------------------------------------------------------
    // Admin-post CSV export handler
    // -------------------------------------------------------------------------

    public function handle_scoring_export() {
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wbi_scoring_export' ) ) wp_die( 'Nonce inválido' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        global $wpdb;

        $users = $wpdb->get_results(
            "SELECT u.ID, u.display_name, u.user_email,
                    um_score.meta_value  AS score,
                    um_class.meta_value  AS class,
                    um_rec.meta_value    AS recency,
                    um_freq.meta_value   AS frequency,
                    um_mon.meta_value    AS monetary,
                    um_seg.meta_value    AS segment
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} um_score  ON u.ID = um_score.user_id  AND um_score.meta_key  = '_wbi_score'
             JOIN {$wpdb->usermeta} um_class  ON u.ID = um_class.user_id  AND um_class.meta_key  = '_wbi_score_class'
             LEFT JOIN {$wpdb->usermeta} um_rec   ON u.ID = um_rec.user_id   AND um_rec.meta_key   = '_wbi_score_recency'
             LEFT JOIN {$wpdb->usermeta} um_freq  ON u.ID = um_freq.user_id  AND um_freq.meta_key  = '_wbi_score_frequency'
             LEFT JOIN {$wpdb->usermeta} um_mon   ON u.ID = um_mon.user_id   AND um_mon.meta_key   = '_wbi_score_monetary'
             LEFT JOIN {$wpdb->usermeta} um_seg   ON u.ID = um_seg.user_id   AND um_seg.meta_key   = '_wbi_score_segment'
             ORDER BY CAST(um_score.meta_value AS SIGNED) DESC
             LIMIT 10000"
        );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wbi-scoring-export-' . date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'customer_name', 'email', 'rfm_score', 'recency', 'frequency', 'monetary', 'segment', 'class' ) );
        foreach ( $users as $u ) {
            fputcsv( $out, array(
                $u->display_name,
                $u->user_email,
                $u->score,
                $u->recency,
                $u->frequency,
                $u->monetary,
                $u->segment,
                $u->class,
            ) );
        }
        fclose( $out );
        exit;
    }
}
