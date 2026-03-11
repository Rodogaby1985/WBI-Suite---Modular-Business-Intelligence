<?php
/**
 * Plugin Name: WBI Suite - Modular Business Intelligence
 * Description: Suite modular para B2B, Estadísticas y Gestión de Stock.
 * Version: 2.1.0
 * Author: Rodrigo Castañera
 */


if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Suite_Loader {

    private $options;

    public function __construct() {
        // Cargar opciones guardadas en la base de datos
        $this->options = get_option( 'wbi_modules_settings' );

        // Admin Menu para Configuración (Aparece bajo WooCommerce)
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Cargar Módulos Activos según configuración
        $this->load_modules();
    }

    public function load_modules() {
        
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
        }
    }

    // --- CONFIGURACIÓN EN WP-ADMIN ---
    
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce', 
            'WBI Config', 
            'WBI Config', 
            'manage_options', 
            'wbi-settings', 
            array( $this, 'render_settings_page' )
        );
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