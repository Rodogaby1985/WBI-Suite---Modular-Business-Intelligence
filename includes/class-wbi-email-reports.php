<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Email_Reports {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();

        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Register custom cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Schedule/unschedule on settings save
        add_action( 'update_option_wbi_modules_settings', array( $this, 'reschedule_cron' ), 10, 2 );

        // The cron hook
        add_action( 'wbi_send_scheduled_report', array( $this, 'send_report' ) );

        // Schedule on init if not yet scheduled
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );

        // Test email AJAX handler
        add_action( 'wp_ajax_wbi_send_test_email', array( $this, 'ajax_send_test_email' ) );
    }

    public function register_settings() {
        add_settings_section(
            'wbi_email_section',
            'Reportes Automáticos por Email',
            null,
            'wbi-settings'
        );

        add_settings_field( 'wbi_email_enabled',    'Activar reportes por email',   array( $this, 'field_email_enabled' ),    'wbi-settings', 'wbi_email_section' );
        add_settings_field( 'wbi_email_recipients', 'Destinatarios (separados por coma)', array( $this, 'field_email_recipients' ), 'wbi-settings', 'wbi_email_section' );
        add_settings_field( 'wbi_email_frequency',  'Frecuencia de envío',          array( $this, 'field_email_frequency' ),  'wbi-settings', 'wbi_email_section' );
    }

    public function field_email_enabled() {
        $opts    = get_option( 'wbi_modules_settings' );
        $checked = ! empty( $opts['wbi_email_enabled'] ) ? 'checked' : '';
        echo '<input type="checkbox" name="wbi_modules_settings[wbi_email_enabled]" value="1" ' . $checked . '> <b>Activar</b>';
    }

    public function field_email_recipients() {
        $opts = get_option( 'wbi_modules_settings' );
        $val  = isset( $opts['wbi_email_recipients'] ) ? esc_attr( $opts['wbi_email_recipients'] ) : '';
        echo '<input type="text" name="wbi_modules_settings[wbi_email_recipients]" value="' . $val . '" style="width:400px;" placeholder="admin@ejemplo.com, otro@ejemplo.com">';
    }

    public function field_email_frequency() {
        $opts = get_option( 'wbi_modules_settings' );
        $val  = isset( $opts['wbi_email_frequency'] ) ? $opts['wbi_email_frequency'] : 'weekly';
        $opts_list = array(
            'daily'   => 'Diario (cada día)',
            'weekly'  => 'Semanal (cada lunes)',
            'monthly' => 'Mensual (1ro de cada mes)',
        );
        echo '<select name="wbi_modules_settings[wbi_email_frequency]">';
        foreach ( $opts_list as $k => $label ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $val, $k, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => 'Una vez por semana',
            );
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => 'Una vez por mes',
            );
        }
        return $schedules;
    }

    public function maybe_schedule_cron() {
        $opts = get_option( 'wbi_modules_settings' );
        if ( empty( $opts['wbi_email_enabled'] ) ) {
            $this->unschedule_cron();
            return;
        }
        if ( ! wp_next_scheduled( 'wbi_send_scheduled_report' ) ) {
            $frequency = isset( $opts['wbi_email_frequency'] ) ? $opts['wbi_email_frequency'] : 'weekly';
            $recurrence = $this->get_recurrence( $frequency );
            wp_schedule_event( time(), $recurrence, 'wbi_send_scheduled_report' );
        }
    }

    public function reschedule_cron( $old_value, $new_value ) {
        $this->unschedule_cron();
        if ( ! empty( $new_value['wbi_email_enabled'] ) ) {
            $frequency  = isset( $new_value['wbi_email_frequency'] ) ? $new_value['wbi_email_frequency'] : 'weekly';
            $recurrence = $this->get_recurrence( $frequency );
            wp_schedule_event( time(), $recurrence, 'wbi_send_scheduled_report' );
        }
    }

    private function unschedule_cron() {
        $timestamp = wp_next_scheduled( 'wbi_send_scheduled_report' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wbi_send_scheduled_report' );
        }
    }

    private function get_recurrence( $frequency ) {
        $map = array(
            'daily'   => 'daily',
            'weekly'  => 'weekly',
            'monthly' => 'monthly',
        );
        return isset( $map[ $frequency ] ) ? $map[ $frequency ] : 'weekly';
    }

    public function send_report() {
        $opts = get_option( 'wbi_modules_settings' );
        if ( empty( $opts['wbi_email_enabled'] ) || empty( $opts['wbi_email_recipients'] ) ) return;

        $recipients = array_filter( array_map( 'trim', explode( ',', $opts['wbi_email_recipients'] ) ) );
        if ( empty( $recipients ) ) return;

        $frequency = isset( $opts['wbi_email_frequency'] ) ? $opts['wbi_email_frequency'] : 'weekly';

        // Determine date range based on frequency
        switch ( $frequency ) {
            case 'daily':
                $start = date( 'Y-m-d', strtotime( '-1 day' ) );
                $end   = date( 'Y-m-d', strtotime( '-1 day' ) );
                $label = 'Ayer (' . date( 'd/m/Y', strtotime( '-1 day' ) ) . ')';
                break;
            case 'monthly':
                $start = date( 'Y-m-01', strtotime( 'last month' ) );
                $end   = date( 'Y-m-t', strtotime( 'last month' ) );
                $label = 'Mes Pasado (' . date( 'F Y', strtotime( 'last month' ) ) . ')';
                break;
            default: // weekly
                $start = date( 'Y-m-d', strtotime( '-7 days' ) );
                $end   = date( 'Y-m-d', strtotime( '-1 day' ) );
                $label = 'Semana del ' . date( 'd/m', strtotime( '-7 days' ) ) . ' al ' . date( 'd/m/Y', strtotime( '-1 day' ) );
        }

        $subject = '[WBI Suite] Reporte de Ventas - ' . $label;
        $body    = $this->build_email_body( $start, $end, $label );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        foreach ( $recipients as $email ) {
            if ( is_email( $email ) ) {
                wp_mail( $email, $subject, $body, $headers );
            }
        }
    }

    private function build_email_body( $start, $end, $label ) {
        $opts = get_option( 'wbi_modules_settings' );

        $revenue   = $this->engine->get_revenue( $start, $end );
        $units     = $this->engine->get_units_sold( $start, $end );
        $top5      = $this->engine->get_best_sellers( $start, $end );
        $statuses  = $this->engine->get_order_status_counts();
        $threshold = isset( $opts['wbi_stock_threshold'] ) ? intval( $opts['wbi_stock_threshold'] ) : 5;
        $low_stock = $this->engine->get_low_stock_products( $threshold );

        $site_name = get_bloginfo( 'name' );
        $currency  = get_woocommerce_currency_symbol();

        $top5_rows = '';
        if ( ! empty( $top5 ) ) {
            foreach ( array_slice( $top5, 0, 5 ) as $i => $p ) {
                $bg = ( $i % 2 === 0 ) ? '#f9f9f9' : '#ffffff';
                $top5_rows .= "<tr style='background:{$bg};'>
                    <td style='padding:8px 12px;'>" . esc_html( $p->name ) . "</td>
                    <td style='padding:8px 12px; text-align:right;'><strong>" . intval( $p->qty ) . "</strong></td>
                </tr>";
            }
        } else {
            $top5_rows = "<tr><td colspan='2' style='padding:8px 12px; color:#888;'>Sin ventas en el período.</td></tr>";
        }

        $status_rows = '';
        $status_labels = array(
            'wc-completed'  => 'Completados',
            'wc-processing' => 'En Proceso',
            'wc-on-hold'    => 'En Espera',
            'wc-cancelled'  => 'Cancelados',
            'wc-failed'     => 'Fallidos',
        );
        foreach ( $status_labels as $key => $lbl ) {
            $count = ( isset( $statuses[ $key ] ) && is_object( $statuses[ $key ] ) ) ? $statuses[ $key ]->count : 0;
            $status_rows .= "<tr><td style='padding:6px 12px;'>{$lbl}</td><td style='padding:6px 12px; text-align:right;'><strong>{$count}</strong></td></tr>";
        }

        $low_stock_section = '';
        if ( ! empty( $low_stock ) ) {
            $low_stock_section = "<h3 style='color:#d63638;'>⚠️ Alertas de Stock Bajo (≤{$threshold} uds.)</h3>
            <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;'>
                <thead><tr style='background:#f0f0f1;'>
                    <th style='padding:8px 12px; text-align:left;'>Producto</th>
                    <th style='padding:8px 12px; text-align:right;'>Stock</th>
                    <th style='padding:8px 12px;'>SKU</th>
                </tr></thead><tbody>";
            foreach ( $low_stock as $p ) {
                $low_stock_section .= "<tr>
                    <td style='padding:8px 12px; border-bottom:1px solid #f0f0f1;'>" . esc_html( $p->post_title ) . "</td>
                    <td style='padding:8px 12px; text-align:right; border-bottom:1px solid #f0f0f1; color:#d63638;'><strong>" . intval( $p->stock ) . "</strong></td>
                    <td style='padding:8px 12px; border-bottom:1px solid #f0f0f1;'>" . esc_html( $p->sku ?: '-' ) . "</td>
                </tr>";
            }
            $low_stock_section .= "</tbody></table>";
        }

        $dashboard_url = admin_url( 'admin.php?page=wbi-dashboard-view' );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; margin:0; padding:0; background:#f1f1f1;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f1f1; padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff; border-radius:4px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.1);">

    <!-- Header -->
    <tr><td style="background:#2271b1; padding:25px 30px;">
        <h1 style="color:#fff; margin:0; font-size:22px;">📊 WBI Suite - Reporte de Ventas</h1>
        <p style="color:#c8d8e9; margin:5px 0 0; font-size:14px;"><?php echo esc_html( $site_name ); ?> · <?php echo esc_html( $label ); ?></p>
    </td></tr>

    <!-- KPIs -->
    <tr><td style="padding:25px 30px; background:#f8f9fa;">
        <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="50%" style="padding:0 10px 0 0;">
                <div style="background:#fff; border-left:4px solid #2271b1; padding:15px 20px; border-radius:3px;">
                    <p style="margin:0; font-size:11px; text-transform:uppercase; color:#646970; letter-spacing:0.5px;">Facturación</p>
                    <p style="margin:8px 0 0; font-size:26px; font-weight:700; color:#1d2327;"><?php echo $currency; ?><?php echo number_format( (float) $revenue, 2 ); ?></p>
                </div>
            </td>
            <td width="50%" style="padding:0 0 0 10px;">
                <div style="background:#fff; border-left:4px solid #00a32a; padding:15px 20px; border-radius:3px;">
                    <p style="margin:0; font-size:11px; text-transform:uppercase; color:#646970; letter-spacing:0.5px;">Unidades Vendidas</p>
                    <p style="margin:8px 0 0; font-size:26px; font-weight:700; color:#1d2327;"><?php echo intval( $units ); ?></p>
                </div>
            </td>
        </tr>
        </table>
    </td></tr>

    <!-- Body -->
    <tr><td style="padding:25px 30px;">

        <!-- Top 5 -->
        <h3 style="color:#1d2327; border-bottom:2px solid #f0f0f1; padding-bottom:8px;">🔥 Top 5 Productos Más Vendidos</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
            <thead><tr style="background:#f0f0f1;">
                <th style="padding:8px 12px; text-align:left; font-size:11px; text-transform:uppercase; color:#50575e;">Producto</th>
                <th style="padding:8px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#50575e;">Unidades</th>
            </tr></thead>
            <tbody><?php echo $top5_rows; ?></tbody>
        </table>

        <!-- Estado de Pedidos -->
        <h3 style="color:#1d2327; border-bottom:2px solid #f0f0f1; padding-bottom:8px; margin-top:25px;">📦 Estado de Pedidos (Total General)</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
            <tbody><?php echo $status_rows; ?></tbody>
        </table>

        <!-- Low Stock -->
        <?php echo $low_stock_section; ?>

        <!-- CTA -->
        <div style="text-align:center; margin-top:30px;">
            <a href="<?php echo esc_url( $dashboard_url ); ?>" style="display:inline-block; background:#2271b1; color:#fff; text-decoration:none; padding:12px 30px; border-radius:3px; font-weight:600;">
                Ver Dashboard Completo →
            </a>
        </div>

    </td></tr>

    <!-- Footer -->
    <tr><td style="background:#f0f0f1; padding:15px 30px; text-align:center; font-size:12px; color:#646970;">
        Generado automáticamente por WBI Suite · <?php echo esc_html( $site_name ); ?>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    public function ajax_send_test_email() {
        check_ajax_referer( 'wbi_test_email_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Sin permisos' );

        $to      = get_option( 'admin_email' );
        $subject = '[WBI Suite] Email de Prueba';
        $body    = $this->build_email_body( date( 'Y-m-d', strtotime( '-7 days' ) ), date( 'Y-m-d', strtotime( '-1 day' ) ), 'Prueba' );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = wp_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success( array( 'message' => 'Email de prueba enviado a ' . $to ) );
        } else {
            wp_send_json_error( 'No se pudo enviar el email. Verificá la configuración de correo de WordPress.' );
        }
    }
}
