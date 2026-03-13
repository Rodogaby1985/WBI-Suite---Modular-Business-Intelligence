<?php
/**
 * Plugin Name: WBI Suite - Modular Business Intelligence
 * Description: Suite modular para B2B, Estadísticas y Gestión de Stock.
 * Version: 3.1.0
 * Author: Rodrigo Castañera
 */


if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Suite_Loader {

    private $options;

    public function __construct() {
        // Cargar opciones guardadas en la base de datos
        $this->options = get_option( 'wbi_modules_settings' );

        // Process license actions
        add_action( 'admin_init', array( $this, 'handle_license_action' ) );

        // Admin Menu para Configuración (Aparece bajo WooCommerce)
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Admin notice and redirect when license is not active
        add_action( 'admin_notices', array( $this, 'license_admin_notice' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_to_license' ) );

        // Cargar Módulos Activos según configuración
        $this->load_modules();
    }

    public function load_modules() {
        // ALWAYS load the license manager first
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-license.php';

        // If license is NOT active, don't load any modules
        if ( ! WBI_License_Manager::is_active() ) {
            return; // Stop here — only the license activation page will show
        }

        // 1. Módulo B2B (Roles, Precios Ocultos, Aprobación)
        if ( ! empty( $this->options['wbi_enable_b2b'] ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-b2b.php';
            new WBI_B2B_Module();
        }

        // 2. Módulo de Datos (Campos extra: Origen de Venta, Taxonomías)
        if ( ! empty( $this->options['wbi_enable_data'] ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-data.php';
            new WBI_Data_Module();
        }

        // 3. Suite de Métricas & Reportes
        if ( ! empty( $this->options['wbi_enable_dashboard'] ) ) {
            
            // A. Motor de Cálculos (Base para todo lo demás)
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-metrics.php';
            
            // B. Dashboard Principal (Resumen Ejecutivo)
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-dashboard.php';
            new WBI_Dashboard_View();
            
            // C. Módulo de Exportación CSV
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-export.php';
            new WBI_Export_Module();

            // D. Reporte Detallado: VENTAS
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-sales.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-sales.php';
                new WBI_Report_Sales();
            }

            // E. Reporte Detallado: CLIENTES
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-clients.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-clients.php';
                new WBI_Report_Clients();
            }

            // F. Reporte Detallado: PRODUCTOS & STOCK
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-products.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-report-products.php';
                new WBI_Report_Products();
            }

            // G. Sistema de Alertas de Stock
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-stock-alerts.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-stock-alerts.php';
                new WBI_Stock_Alerts();
            }

            // H. Reportes Automáticos por Email
            if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-reports.php' ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'includes/class-wbi-email-reports.php';
                new WBI_Email_Reports();
            }
        }
    }

    // --- CONFIGURACIÓN EN WP-ADMIN ---

    public function add_settings_page() {
        // License page — always visible
        add_menu_page(
            'WBI Suite',
            'WBI Suite',
            'manage_options',
            'wbi-license',
            array( $this, 'render_license_page' ),
            'dashicons-lock',
            58
        );

        // Only show config submenu if license is active
        if ( WBI_License_Manager::is_active() ) {
            add_submenu_page(
                'woocommerce',
                'WBI Config',
                'WBI Config',
                'manage_options',
                'wbi-settings',
                array( $this, 'render_settings_page' )
            );
        }
    }

    public function handle_license_action() {
        if ( ! isset( $_POST['wbi_license_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! wp_verify_nonce( $_POST['_wbi_license_nonce'] ?? '', 'wbi_license_nonce' ) ) return;

        $action = sanitize_text_field( $_POST['wbi_license_action'] );

        if ( $action === 'activate' ) {
            $key = sanitize_text_field( isset( $_POST['wbi_license_key'] ) ? $_POST['wbi_license_key'] : '' );
            if ( WBI_License_Manager::activate( $key ) ) {
                add_settings_error( 'wbi_license', 'activated', '✅ Licencia activada correctamente. ¡Bienvenido a WBI Suite!', 'success' );
            } else {
                add_settings_error( 'wbi_license', 'invalid', '❌ Clave de licencia inválida. Verificá el formato e intentá nuevamente.', 'error' );
            }
        } elseif ( $action === 'deactivate' ) {
            WBI_License_Manager::deactivate();
            add_settings_error( 'wbi_license', 'deactivated', '🔓 Licencia desactivada.', 'updated' );
        }
    }

    public function license_admin_notice() {
        if ( WBI_License_Manager::is_active() ) return;

        // Only show to admins
        if ( ! current_user_can( 'manage_options' ) ) return;

        $license_url = admin_url( 'admin.php?page=wbi-license' );
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>🔒 WBI Suite:</strong> El plugin requiere una licencia válida para funcionar. ';
        echo '<a href="' . esc_url( $license_url ) . '">Activar licencia aquí</a>.</p>';
        echo '</div>';
    }

    public function maybe_redirect_to_license() {
        if ( WBI_License_Manager::is_active() ) return;

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        // If trying to access any WBI page that's not the license page
        if ( strpos( $page, 'wbi-' ) === 0 && $page !== 'wbi-license' ) {
            wp_redirect( admin_url( 'admin.php?page=wbi-license' ) );
            exit;
        }
    }

    public function render_license_page() {
        $is_active = WBI_License_Manager::is_active();
        ?>
        <div class="wrap">
            <h1>🔐 WBI Suite — Licencia</h1>
            <?php settings_errors( 'wbi_license' ); ?>

            <div style="background:#fff; padding:30px; border:1px solid #c3c4c7; max-width:600px; margin-top:20px;">

                <?php if ( $is_active ) : ?>
                    <!-- LICENSE ACTIVE STATE -->
                    <div style="text-align:center; padding:20px 0;">
                        <div style="font-size:48px; margin-bottom:10px;">✅</div>
                        <h2 style="color:#00a32a; margin:0;">Licencia Activa</h2>
                        <p style="color:#50575e; font-size:14px;">
                            Clave: <code><?php echo esc_html( WBI_License_Manager::get_masked_key() ); ?></code>
                        </p>
                        <p style="color:#50575e;">
                            Activada: <?php echo esc_html( get_option( 'wbi_license_activated_at', 'N/A' ) ); ?>
                        </p>
                    </div>

                    <hr>

                    <p>Todos los módulos están habilitados. Podés configurarlos desde
                       <a href="<?php echo esc_url( admin_url( 'admin.php?page=wbi-settings' ) ); ?>">WBI Config</a>.
                    </p>

                    <form method="post" style="margin-top:15px;">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="deactivate">
                        <button type="submit" class="button"
                                onclick="return confirm('¿Estás seguro? Se desactivará la licencia y los módulos dejarán de funcionar.');">
                            🔓 Desactivar Licencia
                        </button>
                    </form>

                <?php else : ?>
                    <!-- LICENSE INACTIVE STATE -->
                    <div style="text-align:center; padding:20px 0;">
                        <div style="font-size:48px; margin-bottom:10px;">🔒</div>
                        <h2 style="color:#d63638; margin:0;">Licencia Requerida</h2>
                        <p style="color:#50575e; font-size:14px;">
                            Ingresá tu clave de licencia para activar WBI Suite y acceder a todos los módulos.
                        </p>
                    </div>

                    <form method="post">
                        <?php wp_nonce_field( 'wbi_license_nonce', '_wbi_license_nonce' ); ?>
                        <input type="hidden" name="wbi_license_action" value="activate">

                        <table class="form-table">
                            <tr>
                                <th><label for="wbi_license_key">Clave de Licencia</label></th>
                                <td>
                                    <input type="text" id="wbi_license_key" name="wbi_license_key"
                                           placeholder="WBI-XXXX-XXXX-XXXX-XXXX"
                                           class="regular-text"
                                           style="font-family:monospace; font-size:16px; letter-spacing:1px; text-transform:uppercase;"
                                           maxlength="23"
                                           required>
                                    <p class="description">Formato: WBI-XXXX-XXXX-XXXX-XXXX</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-hero">
                                🔑 Activar Licencia
                            </button>
                        </p>
                    </form>

                    <hr>
                    <p style="color:#50575e; font-size:12px;">
                        ¿No tenés una licencia? Contactá al desarrollador para adquirir una.<br>
                        <strong>WBI Suite</strong> — Suite Modular de Business Intelligence para WooCommerce.
                    </p>

                <?php endif; ?>
            </div>
        </div>
        <?php
        // Secret key generator for the plugin author
        if ( isset( $_GET['wbi_gen'] ) && $_GET['wbi_gen'] === 'castanera2026' && current_user_can( 'manage_options' ) ) {
            $new_key = WBI_License_Manager::generate_key();
            echo '<div style="background:#fef8e7; border:1px solid #d4a900; padding:15px; margin-top:20px; max-width:600px;">';
            echo '<h3>🔑 Generador de Claves (Herramienta del Desarrollador)</h3>';
            echo '<p>Nueva clave generada:</p>';
            echo '<input type="text" value="' . esc_attr( $new_key ) . '" class="regular-text" style="font-family:monospace; font-size:18px;" readonly onclick="this.select();">';
            echo '<p class="description">Copiá esta clave y entrégala a tu cliente.</p>';
            echo '</div>';
        }
    }

    public function register_settings() {
        register_setting( 'wbi_group', 'wbi_modules_settings' );
        
        add_settings_section( 'wbi_main_section', 'Módulos Disponibles', null, 'wbi-settings' );
        
        add_settings_field( 'wbi_enable_b2b', 'Modo Mayorista B2B', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_b2b'] );
        add_settings_field( 'wbi_enable_data', 'Modelo de Datos Extra (Origen)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_data'] );
        add_settings_field( 'wbi_enable_dashboard', 'Suite de BI (Dashboard + Reportes + Stock)', array($this, 'checkbox_field'), 'wbi-settings', 'wbi_main_section', ['id' => 'wbi_enable_dashboard'] );
    }

    public function checkbox_field( $args ) {
        $id = $args['id'];
        $checked = isset( $this->options[$id] ) ? checked( $this->options[$id], 1, false ) : '';
        echo "<input type='checkbox' name='wbi_modules_settings[$id]' value='1' $checked /> <b>Activar</b>";
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Configuración WBI Suite</h1>
            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; max-width:800px;">
                <p>Selecciona qué módulos deseas activar. Desmarcar un módulo desactivará su código por completo para mejorar el rendimiento.</p>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'wbi_group' );
                    do_settings_sections( 'wbi-settings' );
                    submit_button();
                    ?>
                </form>
            </div>
        </div>
        <?php
    }
}

// Iniciar Plugin
new WBI_Suite_Loader();

// Inyectar CSS/JS de ordenamiento de tablas en todas las páginas WBI
add_action( 'admin_footer', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    // Solo en páginas del plugin (el ID de pantalla contiene 'wbi')
    if ( strpos( $screen->id, 'wbi' ) === false ) return;
    ?>
    <style>
    .wbi-sortable thead th { cursor: pointer; user-select: none; white-space: nowrap; }
    .wbi-sortable thead th:hover { background-color: #f0f0f1; }
    .wbi-sortable thead th.wbi-sort-asc::after  { content: ' \25b2'; font-size: 10px; opacity: 0.7; }
    .wbi-sortable thead th.wbi-sort-desc::after { content: ' \25bc'; font-size: 10px; opacity: 0.7; }
    </style>
    <script>
    (function() {
        function wbiParseNum(str) {
            // Remove currency symbols/spaces, then handle thousands/decimal separators.
            // Supports formats like "$1.234,56" (AR) or "1,234.56" (US)
            var s = str.replace(/[^\d,.\-]/g, '');
            if (s.indexOf('.') !== -1 && s.indexOf(',') !== -1) {
                // Both separators present: assume last one is decimal (e.g. 1.234,56 -> 1234.56)
                var lastDot   = s.lastIndexOf('.');
                var lastComma = s.lastIndexOf(',');
                if (lastComma > lastDot) {
                    s = s.replace(/\./g, '').replace(',', '.');
                } else {
                    s = s.replace(/,/g, '');
                }
            } else if (s.indexOf(',') !== -1) {
                s = s.replace(',', '.');
            }
            return parseFloat(s);
        }
        function wbiSortByCol(table, colIdx, asc) {
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b) {
                var aCells = a.querySelectorAll('td');
                var bCells = b.querySelectorAll('td');
                if (!aCells[colIdx] || !bCells[colIdx]) return 0;
                var aText = aCells[colIdx].textContent.trim();
                var bText = bCells[colIdx].textContent.trim();
                var aNum = wbiParseNum(aText);
                var bNum = wbiParseNum(bText);
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return asc ? aNum - bNum : bNum - aNum;
                }
                // Try date in dd/mm/yyyy format
                var dParts = aText.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                var eParts = bText.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                if (dParts && eParts) {
                    var aDate = new Date(dParts[3], dParts[2]-1, dParts[1]);
                    var bDate = new Date(eParts[3], eParts[2]-1, eParts[1]);
                    return asc ? aDate - bDate : bDate - aDate;
                }
                return asc ? aText.localeCompare(bText, 'es') : bText.localeCompare(aText, 'es');
            });
            rows.forEach(function(row) { tbody.appendChild(row); });
        }
        function wbiInitSortable() {
            document.querySelectorAll('table.wbi-sortable').forEach(function(table) {
                var headers = table.querySelectorAll('thead tr:first-child th');
                headers.forEach(function(th, colIdx) {
                    th.setAttribute('tabindex', '0');
                    th.setAttribute('role', 'button');
                    function doSort() {
                        var isAsc = th.classList.contains('wbi-sort-asc');
                        headers.forEach(function(h) {
                            h.classList.remove('wbi-sort-asc', 'wbi-sort-desc');
                        });
                        th.classList.add(isAsc ? 'wbi-sort-desc' : 'wbi-sort-asc');
                        wbiSortByCol(table, colIdx, !isAsc);
                    }
                    th.addEventListener('click', doSort);
                    th.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            doSort();
                        }
                    });
                });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', wbiInitSortable);
        } else {
            wbiInitSortable();
        }
    })();
    </script>
    <?php
} );