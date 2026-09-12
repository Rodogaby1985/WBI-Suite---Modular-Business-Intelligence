<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Admin_Query_Helper {

    public static function get_string( array $source, $key, $default = '' ) {
        if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
            return $default;
        }

        return sanitize_text_field( wp_unslash( (string) $source[ $key ] ) );
    }

    public static function get_key( array $source, $key, $default = '' ) {
        if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
            return $default;
        }

        return sanitize_key( wp_unslash( (string) $source[ $key ] ) );
    }

    public static function get_enum( array $source, $key, array $allowed, $default = '' ) {
        $value = self::get_key( $source, $key, '' );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    public static function get_absint( array $source, $key, $default = 0 ) {
        if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
            return (int) $default;
        }

        return absint( wp_unslash( (string) $source[ $key ] ) );
    }

    public static function get_float( array $source, $key, $default = 0.0 ) {
        if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
            return (float) $default;
        }

        return floatval( sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ) );
    }

    public static function get_bool_flag( array $source, $key, $default = false ) {
        if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
            return (bool) $default;
        }

        $value = strtolower( trim( wp_unslash( (string) $source[ $key ] ) ) );
        return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
    }

    public static function get_string_array( array $source, $key, $allowed = null ) {
        if ( ! isset( $source[ $key ] ) ) {
            return array();
        }

        $raw_values = $source[ $key ];
        if ( ! is_array( $raw_values ) ) {
            $raw_values = array( $raw_values );
        }

        $result = array();
        foreach ( $raw_values as $value ) {
            if ( is_array( $value ) ) {
                continue;
            }

            $sanitized = sanitize_text_field( wp_unslash( (string) $value ) );
            if ( '' === $sanitized ) {
                continue;
            }

            if ( is_array( $allowed ) && ! in_array( $sanitized, $allowed, true ) ) {
                continue;
            }

            $result[] = $sanitized;
        }

        return array_values( array_unique( $result ) );
    }

    public static function is_valid_date_ymd( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return false;
        }

        $timezone = new DateTimeZone( 'UTC' );
        $date     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
        $errors   = DateTimeImmutable::getLastErrors();

        if ( false === $date ) {
            return false;
        }

        if ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) {
            return false;
        }

        return $date->format( 'Y-m-d' ) === $value;
    }

    public static function get_valid_date( array $source, $key, $default = '' ) {
        $value = self::get_string( $source, $key, '' );
        return self::is_valid_date_ymd( $value ) ? $value : $default;
    }

    public static function normalize_date_range( array $source, $from_key, $to_key, $default_from, $default_to ) {
        $result = self::normalize_date_range_with_meta( $source, $from_key, $to_key, $default_from, $default_to );
        return array( $result['from'], $result['to'] );
    }

    public static function normalize_date_range_with_meta( array $source, $from_key, $to_key, $default_from, $default_to ) {
        $raw_from = self::get_string( $source, $from_key, '' );
        $raw_to   = self::get_string( $source, $to_key, '' );

        $from = '' !== $raw_from && self::is_valid_date_ymd( $raw_from ) ? $raw_from : '';
        $to   = '' !== $raw_to && self::is_valid_date_ymd( $raw_to ) ? $raw_to : '';

        $error_code = '';
        if ( ( '' !== $raw_from && '' === $from ) || ( '' !== $raw_to && '' === $to ) ) {
            $error_code = 'invalid_date';
        } elseif ( ( '' === $raw_from ) xor ( '' === $raw_to ) ) {
            $error_code = 'incomplete_range';
        }

        if ( '' === $from ) {
            $from = $default_from;
        }
        if ( '' === $to ) {
            $to = $default_to;
        }

        $is_reversed = false;
        if ( '' !== $from && '' !== $to && $from > $to ) {
            $is_reversed = true;
            $tmp         = $from;
            $from        = $to;
            $to          = $tmp;
            if ( '' === $error_code ) {
                $error_code = 'reversed_range';
            }
        }

        return array(
            'from'        => $from,
            'to'          => $to,
            'is_reversed' => $is_reversed,
            'error_code'  => $error_code,
            'has_error'   => '' !== $error_code,
        );
    }

    public static function get_site_date_ymd( $modify = null ) {
        $date = new DateTimeImmutable( 'now', wp_timezone() );
        if ( is_string( $modify ) && '' !== $modify ) {
            $modified = $date->modify( $modify );
            if ( false !== $modified ) {
                $date = $modified;
            }
        }
        return $date->format( 'Y-m-d' );
    }

    public static function backfill_missing_invoice_dates( $date_from, $date_to, $invoice_type = '', $batch_size = 200 ) {
        if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
            return 0;
        }
        $cache_key = 'wbi_inv_backfill_' . md5( implode( '|', array( (string) $invoice_type, (string) $batch_size ) ) );
        if ( false !== get_transient( $cache_key ) ) {
            return 0;
        }

        $updated   = 0;
        $limit     = max( 1, (int) $batch_size );
        do {
            $query_args = array(
                'return'       => 'ids',
                'limit'        => $limit,
                'orderby'      => 'ID',
                'order'        => 'DESC',
                'meta_query'   => array(
                    array(
                        'key'     => '_wbi_invoice_number',
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => '_wbi_invoice_date',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            );
            if ( in_array( $invoice_type, array( 'A', 'B', 'C' ), true ) ) {
                $query_args['meta_query'][] = array(
                    'key'     => '_wbi_invoice_type',
                    'value'   => $invoice_type,
                    'compare' => '=',
                );
            }

            $order_ids = wc_get_orders( $query_args );
            if ( ! is_array( $order_ids ) ) {
                $order_ids = array();
            }
            if ( empty( $order_ids ) ) {
                break;
            }

            $batch_updated = 0;
            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( (int) $order_id );
                if ( ! $order || $order->get_meta( '_wbi_invoice_date', true ) ) {
                    continue;
                }

                $created = $order->get_date_created();
                $date    = $created ? wp_date( 'Y-m-d', $created->getTimestamp(), wp_timezone() ) : self::get_site_date_ymd();
                $order->update_meta_data( '_wbi_invoice_date', $date );
                $order->save();
                $updated++;
                $batch_updated++;
            }

            if ( 0 === $batch_updated ) {
                break;
            }
        } while ( count( $order_ids ) === $limit );

        set_transient( $cache_key, 1, 15 * MINUTE_IN_SECONDS );
        return $updated;
    }

    public static function build_url( $base_url, array $args ) {
        $filtered = array();
        foreach ( $args as $key => $value ) {
            if ( null === $value || false === $value || '' === $value ) {
                continue;
            }
            $filtered[ $key ] = $value;
        }

        return add_query_arg( $filtered, $base_url );
    }
}
