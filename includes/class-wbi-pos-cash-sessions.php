<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI POS Cash Sessions
 *
 * Manages the custom DB table {prefix}wbi_pos_cash_sessions.
 * Each row represents one cash register session for a seller.
 *
 * Table schema:
 *   id                  BIGINT UNSIGNED AUTO_INCREMENT PK
 *   seller_user_id      BIGINT UNSIGNED NOT NULL
 *   operator_user_id    BIGINT UNSIGNED NOT NULL
 *   opened_at           DATETIME NOT NULL
 *   closed_at           DATETIME NULL
 *   opening_cash        DECIMAL(12,2) NOT NULL DEFAULT 0
 *   closing_cash_counted DECIMAL(12,2) NULL
 *   difference          DECIMAL(12,2) NULL
 *   opening_note        TEXT NULL
 *   closing_note        TEXT NULL
 *   status              VARCHAR(10) NOT NULL DEFAULT 'open'
 */
class WBI_POS_Cash_Sessions {

    const TABLE_VERSION_OPTION = 'wbi_pos_cash_sessions_db_version';
    const TABLE_VERSION        = '1.0';

    // =========================================================================
    // DB TABLE MANAGEMENT
    // =========================================================================

    /**
     * Create or upgrade the cash sessions table using dbDelta.
     * Safe to call multiple times.
     */
    public static function create_table() {
        global $wpdb;

        $table      = $wpdb->prefix . 'wbi_pos_cash_sessions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            seller_user_id bigint(20) unsigned NOT NULL,
            operator_user_id bigint(20) unsigned NOT NULL,
            opened_at datetime NOT NULL,
            closed_at datetime DEFAULT NULL,
            opening_cash decimal(12,2) NOT NULL DEFAULT '0.00',
            closing_cash_counted decimal(12,2) DEFAULT NULL,
            difference decimal(12,2) DEFAULT NULL,
            opening_note text DEFAULT NULL,
            closing_note text DEFAULT NULL,
            status varchar(10) NOT NULL DEFAULT 'open',
            PRIMARY KEY  (id),
            KEY seller_user_id (seller_user_id),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }

    /**
     * Ensure the table exists (runs on init, cheap version check).
     */
    public static function maybe_create_table() {
        if ( get_option( self::TABLE_VERSION_OPTION ) !== self::TABLE_VERSION ) {
            self::create_table();
        }
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    /**
     * Get the currently open session for a seller, or null.
     *
     * @param int $seller_user_id
     * @return object|null  Row from DB or null.
     */
    public static function get_open_session( $seller_user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_pos_cash_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE seller_user_id = %d AND status = 'open' ORDER BY opened_at DESC LIMIT 1",
                absint( $seller_user_id )
            )
        );
        // phpcs:enable
    }

    /**
     * Get a specific session by ID.
     *
     * @param int $session_id
     * @return object|null
     */
    public static function get_session( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_pos_cash_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $session_id ) )
        );
        // phpcs:enable
    }

    /**
     * Get summary totals for a session (grouped by payment method).
     *
     * Returns an array:
     *   'totals_by_method' => [ 'cash' => 1234.50, 'transfer' => 500.00, ... ]
     *   'total_sold'       => 1734.50
     *   'total_paid'       => 1534.50
     *   'total_balance'    => 200.00
     *   'order_count'      => 12
     *
     * @param int $session_id
     * @return array
     */
    public static function get_session_summary( $session_id ) {
        global $wpdb;

        $session_id = absint( $session_id );

        // Fetch all POS orders linked to this session
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wbi_pos_cash_session_id' AND meta_value = %s",
                (string) $session_id
            )
        );

        // Also check HPOS (custom order tables) if available
        if ( function_exists( 'wc_get_orders' ) ) {
            $hpos_orders = wc_get_orders( array(
                'meta_key'   => '_wbi_pos_cash_session_id',
                'meta_value' => (string) $session_id,
                'return'     => 'ids',
                'limit'      => -1,
            ) );
            if ( is_array( $hpos_orders ) ) {
                $order_ids = array_unique( array_merge( (array) $order_ids, $hpos_orders ) );
            }
        }

        $totals_by_method = array();
        $total_sold       = 0.0;
        $total_paid       = 0.0;
        $total_balance    = 0.0;
        $order_count      = 0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( absint( $order_id ) );
            if ( ! $order ) continue;

            $order_total = (float) $order->get_total();
            $paid_total  = (float) $order->get_meta( '_wbi_pos_paid_total', true );
            $balance_due = (float) $order->get_meta( '_wbi_pos_balance_due', true );
            $payments    = json_decode( $order->get_meta( '_wbi_pos_payments', true ), true );

            $total_sold    += $order_total;
            $total_paid    += $paid_total;
            $total_balance += $balance_due;
            $order_count++;

            if ( is_array( $payments ) ) {
                foreach ( $payments as $p ) {
                    $method = sanitize_key( $p['method'] ?? 'other' );
                    $amount = (float) ( $p['amount'] ?? 0 );
                    $totals_by_method[ $method ] = ( $totals_by_method[ $method ] ?? 0.0 ) + $amount;
                }
            }
        }

        return array(
            'totals_by_method' => $totals_by_method,
            'total_sold'       => round( $total_sold, 2 ),
            'total_paid'       => round( $total_paid, 2 ),
            'total_balance'    => round( $total_balance, 2 ),
            'order_count'      => $order_count,
        );
    }

    // =========================================================================
    // WRITE OPERATIONS
    // =========================================================================

    /**
     * Open a new cash session for a seller.
     *
     * @param int    $seller_user_id
     * @param int    $operator_user_id
     * @param float  $opening_cash
     * @param string $opening_note
     * @return int|false  New session ID or false on error.
     */
    public static function open_session( $seller_user_id, $operator_user_id, $opening_cash = 0.0, $opening_note = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_pos_cash_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'seller_user_id'   => absint( $seller_user_id ),
                'operator_user_id' => absint( $operator_user_id ),
                'opened_at'        => current_time( 'mysql' ),
                'opening_cash'     => round( (float) $opening_cash, 2 ),
                'opening_note'     => sanitize_textarea_field( $opening_note ),
                'status'           => 'open',
            ),
            array( '%d', '%d', '%s', '%f', '%s', '%s' )
        );
        // phpcs:enable

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Close an open session.
     *
     * @param int    $session_id
     * @param float  $closing_cash_counted
     * @param string $closing_note
     * @return bool
     */
    public static function close_session( $session_id, $closing_cash_counted = 0.0, $closing_note = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wbi_pos_cash_sessions';

        $session = self::get_session( $session_id );
        if ( ! $session || 'open' !== $session->status ) {
            return false;
        }

        $summary      = self::get_session_summary( $session_id );
        $cash_in      = (float) $session->opening_cash + (float) ( $summary['totals_by_method']['cash'] ?? 0 );
        $difference   = round( (float) $closing_cash_counted - $cash_in, 2 );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $table,
            array(
                'closed_at'             => current_time( 'mysql' ),
                'closing_cash_counted'  => round( (float) $closing_cash_counted, 2 ),
                'difference'            => $difference,
                'closing_note'          => sanitize_textarea_field( $closing_note ),
                'status'                => 'closed',
            ),
            array( 'id' => absint( $session_id ) ),
            array( '%s', '%f', '%f', '%s', '%s' ),
            array( '%d' )
        );
        // phpcs:enable

        return false !== $result;
    }
}
