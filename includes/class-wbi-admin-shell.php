<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Admin_Shell {

    public static function open_page( $classes = '' ) {
        $page_classes = trim( 'wbi-page ' . $classes );
        echo '<div class="wrap wbi-wrap"><div class="' . esc_attr( $page_classes ) . '">';
    }

    public static function close_page() {
        echo '</div></div>';
    }

    public static function render_notice( $message, $type = 'info' ) {
        $allowed_types = array( 'success', 'warning', 'danger', 'info' );
        $type          = in_array( $type, $allowed_types, true ) ? $type : 'info';
        $role          = ( 'danger' === $type ) ? 'alert' : 'status';

        echo '<div class="wbi-alert wbi-alert-' . esc_attr( $type ) . '" role="' . esc_attr( $role ) . '"><p>' . wp_kses_post( $message ) . '</p></div>';
    }

    public static function render_header( $args ) {
        $title       = isset( $args['title'] ) ? (string) $args['title'] : '';
        $description = isset( $args['description'] ) ? (string) $args['description'] : '';
        $back_link   = isset( $args['back_link'] ) && is_array( $args['back_link'] ) ? $args['back_link'] : array();
        $actions     = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();

        echo '<header class="wbi-page-header wbi-header">';
        echo '<div class="wbi-page-header-main">';

        if ( ! empty( $back_link['url'] ) ) {
            $back_label = ! empty( $back_link['label'] ) ? $back_link['label'] : __( 'Volver', 'wbi-suite' );
            echo '<a class="wbi-page-back" href="' . esc_url( $back_link['url'] ) . '">';
            echo '<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>';
            echo '<span>' . esc_html( $back_label ) . '</span>';
            echo '</a>';
        }

        echo '<div>';
        echo '<h1 class="wbi-page-title">' . esc_html( $title ) . '</h1>';
        if ( '' !== $description ) {
            echo '<p class="wbi-page-description">' . esc_html( $description ) . '</p>';
        }
        echo '</div>';
        echo '</div>';

        if ( ! empty( $actions ) ) {
            echo '<div class="wbi-page-actions">';
            foreach ( $actions as $action ) {
                if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
                    continue;
                }

                $classes    = ! empty( $action['class'] ) ? (string) $action['class'] : 'wbi-btn';
                $target     = ! empty( $action['target'] ) ? (string) $action['target'] : '';
                $aria_label = ! empty( $action['aria_label'] ) ? (string) $action['aria_label'] : '';
                $rel        = '_blank' === $target ? ' rel="noopener noreferrer"' : '';

                echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $action['url'] ) . '"';
                if ( '' !== $target ) {
                    echo ' target="' . esc_attr( $target ) . '"' . $rel;
                }
                if ( '' !== $aria_label ) {
                    echo ' aria-label="' . esc_attr( $aria_label ) . '"';
                }
                echo '>' . esc_html( $action['label'] ) . '</a>';
            }
            echo '</div>';
        }

        echo '</header>';
    }

    public static function render_tabs( $tabs, $active_key, $args = array() ) {
        $label = isset( $args['label'] ) ? (string) $args['label'] : __( 'Navegación de la página', 'wbi-suite' );

        echo '<nav class="wbi-nav-tabs" aria-label="' . esc_attr( $label ) . '">';

        foreach ( $tabs as $key => $tab ) {
            if ( empty( $tab['url'] ) || empty( $tab['label'] ) ) {
                continue;
            }

            $is_active = (string) $key === (string) $active_key;
            $classes   = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );

            echo '<a href="' . esc_url( $tab['url'] ) . '" class="' . esc_attr( $classes ) . '"';
            if ( $is_active ) {
                echo ' aria-current="page"';
            }
            if ( ! empty( $tab['aria_label'] ) ) {
                echo ' aria-label="' . esc_attr( $tab['aria_label'] ) . '"';
            }
            echo '>' . esc_html( $tab['label'] ) . '</a>';
        }

        echo '</nav>';
    }

    public static function render_pagination( $pagination, $summary = '' ) {
        if ( '' === $summary && empty( $pagination ) ) {
            return;
        }

        if ( empty( $pagination ) ) {
            if ( '' !== $summary ) {
                echo '<p class="wbi-page-summary">' . esc_html( $summary ) . '</p>';
            }
            return;
        }

        echo '<nav class="wbi-pagination tablenav" aria-label="' . esc_attr__( 'Paginación', 'wbi-suite' ) . '">';
        if ( '' !== $summary ) {
            echo '<p class="wbi-page-summary">' . esc_html( $summary ) . '</p>';
        }
        echo '<div class="tablenav-pages">' . wp_kses_post( $pagination ) . '</div>';
        echo '</nav>';
    }
}
