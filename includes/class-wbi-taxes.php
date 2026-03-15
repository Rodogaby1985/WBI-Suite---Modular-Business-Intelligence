<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WBI Taxes Module — Gestión de Impuestos por Provincia
 *
 * Permite configurar tasas impositivas (IVA, Percepciones, IIBB) por
 * provincia argentina y genera reportes de impuestos recaudados
 * en un período de tiempo.
 */
class WBI_Taxes_Module {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
        add_action( 'admin_post_wbi_save_tax_config', array( $this, 'handle_save_config' ) );
    }

    // -------------------------------------------------------------------------
    // ADMIN MENU
    // -------------------------------------------------------------------------

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            '🏛️ Gestión Impuestos',
            '🏛️ Impuestos',
            'manage_options',
            'wbi-taxes',
            array( $this, 'render' )
        );
    }

    // -------------------------------------------------------------------------
    // HANDLE SAVE CONFIG
    // -------------------------------------------------------------------------

    public function handle_save_config() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'wbi_tax_config_nonce', '_wbi_tax_nonce' );

        $config = array();
        if ( isset( $_POST['tax_config'] ) && is_array( $_POST['tax_config'] ) ) {
            foreach ( wp_unslash( $_POST['tax_config'] ) as $code => $rates ) {
                $code = sanitize_text_field( $code );
                if ( strlen( $code ) > 2 || ! ctype_alpha( $code ) ) continue;
                $config[ strtoupper( $code ) ] = array(
                    'iva'          => max( 0, floatval( isset( $rates['iva'] )          ? $rates['iva']          : 21 ) ),
                    'percepciones' => max( 0, floatval( isset( $rates['percepciones'] ) ? $rates['percepciones'] : 0 ) ),
                    'iibb'         => max( 0, floatval( isset( $rates['iibb'] )         ? $rates['iibb']         : 0 ) ),
                );
            }
        }
        update_option( 'wbi_tax_config', $config );
        wp_safe_redirect( admin_url( 'admin.php?page=wbi-taxes&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // RENDER
    // -------------------------------------------------------------------------

    public function render() {
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'config';

        $province_map = array(
            'C' => 'Ciudad Autónoma de Buenos Aires',
            'B' => 'Buenos Aires',
            'K' => 'Catamarca',
            'H' => 'Chaco',
            'U' => 'Chubut',
            'X' => 'Córdoba',
            'W' => 'Corrientes',
            'E' => 'Entre Ríos',
            'P' => 'Formosa',
            'Y' => 'Jujuy',
            'L' => 'La Pampa',
            'F' => 'La Rioja',
            'M' => 'Mendoza',
            'N' => 'Misiones',
            'Q' => 'Neuquén',
            'R' => 'Río Negro',
            'A' => 'Salta',
            'J' => 'San Juan',
            'D' => 'San Luis',
            'Z' => 'Santa Cruz',
            'S' => 'Santa Fe',
            'G' => 'Santiago del Estero',
            'V' => 'Tierra del Fuego',
            'T' => 'Tucumán',
        );

        $tax_config = get_option( 'wbi_tax_config', array() );
        ?>
        <div class="wrap">
            <h1>🏛️ Gestión de Impuestos</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Configuración de impuestos guardada.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-taxes&tab=config"
                   class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>">⚙️ Configuración</a>
                <a href="?page=wbi-taxes&tab=report"
                   class="nav-tab <?php echo $tab === 'report' ? 'nav-tab-active' : ''; ?>">📊 Reporte</a>
            </nav>

            <div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-top:10px;">

            <?php if ( $tab === 'config' ) : ?>
                <h2 style="margin-top:0;">⚙️ Tasas Impositivas por Provincia</h2>
                <p style="color:#50575e;">Configurá las tasas de IVA, Percepciones e IIBB para cada provincia. Los valores se usan para calcular el reporte de impuestos.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wbi_tax_config_nonce', '_wbi_tax_nonce' ); ?>
                    <input type="hidden" name="action" value="wbi_save_tax_config">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Provincia</th>
                                <th>IVA (%)</th>
                                <th>Percepciones (%)</th>
                                <th>IIBB (%)</th>
                                <th>Total Efectivo</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $province_map as $code => $name ) :
                            $cfg  = isset( $tax_config[ $code ] ) ? $tax_config[ $code ] : array();
                            $iva  = isset( $cfg['iva'] )          ? (float) $cfg['iva']          : 21.0;
                            $perc = isset( $cfg['percepciones'] ) ? (float) $cfg['percepciones'] : 0.0;
                            $iibb = isset( $cfg['iibb'] )         ? (float) $cfg['iibb']         : 0.0;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $code ); ?></code></td>
                                <td><?php echo esc_html( $name ); ?></td>
                                <td>
                                    <input type="number" name="tax_config[<?php echo esc_attr( $code ); ?>][iva]"
                                           value="<?php echo esc_attr( $iva ); ?>" min="0" max="100" step="0.01"
                                           class="small-text" style="width:70px;">
                                </td>
                                <td>
                                    <input type="number" name="tax_config[<?php echo esc_attr( $code ); ?>][percepciones]"
                                           value="<?php echo esc_attr( $perc ); ?>" min="0" max="100" step="0.01"
                                           class="small-text" style="width:70px;">
                                </td>
                                <td>
                                    <input type="number" name="tax_config[<?php echo esc_attr( $code ); ?>][iibb]"
                                           value="<?php echo esc_attr( $iibb ); ?>" min="0" max="100" step="0.01"
                                           class="small-text" style="width:70px;">
                                </td>
                                <td style="color:#50575e; font-size:12px;">
                                    <?php echo esc_html( number_format( $iva + $perc + $iibb, 2 ) ); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:15px;">
                        <button type="submit" class="button button-primary">💾 Guardar Configuración</button>
                    </p>
                </form>

            <?php elseif ( $tab === 'report' ) : ?>

                <?php
                $start    = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : date( 'Y-m-01' );
                $end      = isset( $_GET['end'] )   ? sanitize_text_field( wp_unslash( $_GET['end'] ) )   : date( 'Y-m-d' );
                $default_statuses = array( 'wc-completed', 'wc-processing' );
                $statuses = isset( $_GET['statuses'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['statuses'] ) ) : $default_statuses;
                $all_statuses = array(
                    'wc-completed'  => '✅ Completado',
                    'wc-processing' => '🔄 En proceso',
                    'wc-on-hold'    => '⏸ En espera',
                    'wc-pending'    => '⏳ Pendiente',
                );
                $statuses_qs = implode( '', array_map( function( $s ) { return '&statuses[]=' . rawurlencode( $s ); }, $statuses ) );
                $export_url  = esc_url( admin_url( "admin-post.php?action=wbi_export_dynamic&report_type=taxes_summary&start={$start}&end={$end}{$statuses_qs}" ) );
                ?>
                <h2 style="margin-top:0;">📊 Reporte de Impuestos por Provincia</h2>

                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
                    <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="wbi-taxes">
                        <input type="hidden" name="tab" value="report">
                        <strong>📅 Periodo:</strong>
                        <input type="date" name="start" value="<?php echo esc_attr( $start ); ?>">
                        al
                        <input type="date" name="end"   value="<?php echo esc_attr( $end ); ?>">
                        <strong>Estados:</strong>
                        <select name="statuses[]" multiple size="4" style="height:72px; min-width:140px;"
                                title="Ctrl/Cmd para múltiple">
                            <?php foreach ( $all_statuses as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"
                                        <?php echo in_array( $val, $statuses, true ) ? 'selected' : ''; ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button button-primary">Filtrar</button>
                    </form>
                    <a href="<?php echo $export_url; ?>" class="button">📥 Exportar CSV</a>
                </div>

                <?php
                $data = $this->engine->get_tax_summary( $start, $end, $statuses );
                if ( ! empty( $data ) ) :
                    $total_rev  = array_sum( array_column( (array) $data, 'total' ) );
                    $total_iva  = array_sum( array_column( (array) $data, 'iva' ) );
                    $total_perc = array_sum( array_column( (array) $data, 'percepciones' ) );
                    $total_iibb = array_sum( array_column( (array) $data, 'iibb' ) );
                ?>
                <table class="widefat striped wbi-sortable">
                    <thead>
                        <tr>
                            <th>Cód.</th>
                            <th>Provincia</th>
                            <th style="text-align:right;">Pedidos</th>
                            <th style="text-align:right;">Total Facturado</th>
                            <th style="text-align:right;">IVA Estimado</th>
                            <th style="text-align:right;">Percepciones</th>
                            <th style="text-align:right;">IIBB</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $data as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $row->province ); ?></code></td>
                            <td><?php echo esc_html( $row->province_name ); ?></td>
                            <td style="text-align:right;"><?php echo intval( $row->orders ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $row->total ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $row->iva ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $row->percepciones ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $row->iibb ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700; background:#f0f0f1;">
                            <td colspan="3">TOTALES</td>
                            <td style="text-align:right;"><?php echo wc_price( $total_rev ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $total_iva ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $total_perc ); ?></td>
                            <td style="text-align:right;"><?php echo wc_price( $total_iibb ); ?></td>
                        </tr>
                    </tfoot>
                </table>
                <?php else : ?>
                    <p style="color:#646970;">No se encontraron datos de impuestos para el período seleccionado.</p>
                <?php endif; ?>

            <?php endif; ?>

            </div><!-- end content -->
        </div>
        <?php
    }
}
