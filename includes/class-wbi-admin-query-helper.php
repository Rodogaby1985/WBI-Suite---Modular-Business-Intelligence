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
        $from = self::get_valid_date( $source, $from_key, $default_from );
        $to   = self::get_valid_date( $source, $to_key, $default_to );

        if ( '' !== $from && '' !== $to && $from > $to ) {
            $tmp  = $from;
            $from = $to;
            $to   = $tmp;
        }

        return array( $from, $to );
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
