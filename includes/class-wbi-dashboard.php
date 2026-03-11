<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Dashboard_View {

    private $engine;

    public function __construct() {
        $this->engine = new WBI_Metrics_Engine();
        // Prioridad 99 para ser el menú padre
        add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_page() {
        add_menu_page( 
            'BI Métricas', 
            'BI Métricas', 
            'manage_options', 
            'wbi-dashboard-view', 
            array( $this, 'render' ), 
            'dashicons-chart-area', 
            2 
        );
        remove_submenu_page( 'wbi-dashboard-view', 'wbi-dashboard-view' );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_wbi-dashboard-view' !== $hook ) return;
        
        wp_register_style( 'wbi-admin-css', false );
        wp_enqueue_style( 'wbi-admin-css' );
        // ESTILOS VISUALES COMPLETOS (RESTAURADOS)
        wp_add_inline_style( 'wbi-admin-css', "
            .wbi-wrap { max-width: 1250px; margin: 20px 20px 50px; }
            .wbi-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .wbi-section-title { font-size: 16px; margin: 35px 0 15px; border-bottom: 1px solid #ccc; padding-bottom: 8px; color:#2c3338; font-weight:600; }
            
            /* GRID SYSTEMS */
            .wbi-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
            .wbi-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 20px; }
            
            /* CARDS */
            .wbi-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 3px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .wbi-card.blue { border-top: 3px solid #2271b1; }
            .wbi-card.green { border-top: 3px solid #00a32a; }
            .wbi-card.red { border-top: 3px solid #d63638; }
            .wbi-card.orange { border-top: 3px solid #dba617; }
            
            /* TYPOGRAPHY */
            .wbi-number { font-size: 28px; font-weight: 700; color: #1d2327; margin: 10px 0 5px; line-height:1; }
            .wbi-label { text-transform: uppercase; font-size: 11px; color: #646970; font-weight: 600; letter-spacing: 0.5px; }
            
            /* TABLES */
            .wbi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .wbi-table th { text-align: left; border-bottom: 2px solid #f0f0f1; padding: 8px 4px; color: #50575e; font-size:11px; text-transform:uppercase; }
            .wbi-table td { border-bottom: 1px solid #f0f0f1; padding: 8px 4px; color: #3c434a; }
            .wbi-table tr:last-child td { border-bottom: none; }
            
            /* FILTERS BAR */
            .wbi-filter-bar { background: #fff; padding: 15px 20px; border: 1px solid #c3c4c7; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; border-radius: 3px; border-left: 4px solid #2271b1; }
            .wbi-date-inputs { display: inline-flex; gap: 10px; align-items: center; background: #f0f0f1; padding: 5px 10px; border-radius: 4px; }
        ");
    }

    /**
     * FUNCIÓN DE SEGURIDAD: Evita el error crítico si falta un dato
     */
    private function get_safe_count($data, $status) {
        if ( isset($data[$status]) && is_object($data[$status]) ) {
            return $data[$status]->count;
        }
        return 0;
    }

    public function render() {
        // --- 1. LÓGICA DE FECHAS (Restaurada) ---
        $range = isset($_GET['wbi_range']) ? sanitize_text_field($_GET['wbi_range']) : '30d';
        $end_date = date('Y-m-d'); 
        $start_date = date('Y-m-d', strtotime('-30 days'));

        if( $range === 'custom' && !empty($_GET['wbi_start']) && !empty($_GET['wbi_end']) ) {
            $start_date = sanitize_text_field($_GET['wbi_start']);
            $end_date = sanitize_text_field($_GET['wbi_end']);
        } else {
            switch($range) {
                case 'today': $start_date = date('Y-m-d'); break;
                case 'yesterday': $start_date = date('Y-m-d', strtotime('-1 day')); $end_date = $start_date; break;
                case '7d': $start_date = date('Y-m-d', strtotime('-7 days')); break;
                case 'this_month': $start_date = date('Y-m-01'); break;
                case 'last_month': $start_date = date('Y-m-01', strtotime('last month')); $end_date = date('Y-m-t', strtotime('last month')); break;
                case 'this_year': $start_date = date('Y-01-01'); break;
            }
        }

        // --- 2. OBTENER DATOS (Protegidos) ---
        $revenue = $this->engine->get_revenue($start_date, $end_date) ?: 0;
        $units = $this->engine->get_units_sold($start_date, $end_date) ?: 0;
        
        // Obtener Estados y procesarlos seguramente
        $status_raw = $this->engine->get_order_status_counts();
        $c_completed  = $this->get_safe_count($status_raw, 'wc-completed');
        $c_processing = $this->get_safe_count($status_raw, 'wc-processing');
        $c_hold       = $this->get_safe_count($status_raw, 'wc-on-hold');
        $c_cancelled  = $this->get_safe_count($status_raw, 'wc-cancelled');
        $c_failed     = $this->get_safe_count($status_raw, 'wc-failed');

        $least_sold = $this->engine->get_least_sold($start_date, $end_date);
        $best_sold = $this->engine->get_best_sellers($start_date, $end_date);

        ?>
        <div class="wrap wbi-wrap">
            
            <div class="wbi-header">
                <h1 style="margin:0;">BI Dashboard Ejecutivo</h1>
            </div>
            
            <!-- BARRA DE FILTROS VISUAL -->
            <form method="get" class="wbi-filter-bar">
                <input type="hidden" name="page" value="wbi-dashboard-view" />
                
                <label style="font-weight:600;">📅 Periodo Análisis:</label>
                <select name="wbi_range" id="wbi_range" onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php selected($range, 'today'); ?>>Hoy</option>
                    <option value="yesterday" <?php selected($range, 'yesterday'); ?>>Ayer</option>
                    <option value="7d" <?php selected($range, '7d'); ?>>7 Días</option>
                    <option value="30d" <?php selected($range, '30d'); ?>>30 Días</option>
                    <option value="this_month" <?php selected($range, 'this_month'); ?>>Este Mes</option>
                    <option value="last_month" <?php selected($range, 'last_month'); ?>>Mes Pasado</option>
                    <option value="this_year" <?php selected($range, 'this_year'); ?>>Este Año</option>
                    <option value="custom" <?php selected($range, 'custom'); ?>>Personalizado...</option>
                </select>

                <div id="wbi_custom_dates" class="wbi-date-inputs" style="display: <?php echo ($range === 'custom') ? 'inline-flex' : 'none'; ?>;">
                    <input type="date" name="wbi_start" value="<?php echo $start_date; ?>">
                    <span class="dashicons dashicons-arrow-right-alt"></span>
                    <input type="date" name="wbi_end" value="<?php echo $end_date; ?>">
                </div>

                <button type="submit" class="button button-primary">Aplicar Filtros</button>
            </form>

            <script>
                function toggleCustomDates(val) {
                    const box = document.getElementById('wbi_custom_dates');
                    box.style.display = (val === 'custom') ? 'inline-flex' : 'none';
                }
            </script>

            <!-- SECCIÓN 1: ESTADO GLOBAL PEDIDOS (DISEÑO GRID DE COLORES) -->
            <h2 class="wbi-section-title">📦 Estado Global de Pedidos</h2>
            <div class="wbi-grid-4">
                <div class="wbi-card green">
                    <div class="wbi-label">Completados</div>
                    <div class="wbi-number"><?php echo $c_completed; ?></div>
                </div>
                <div class="wbi-card blue">
                    <div class="wbi-label">En Proceso (Armado)</div>
                    <div class="wbi-number"><?php echo $c_processing; ?></div>
                </div>
                <div class="wbi-card orange">
                    <div class="wbi-label">En Espera (Pago/Stock)</div>
                    <div class="wbi-number"><?php echo $c_hold; ?></div>
                </div>
                <div class="wbi-card red">
                    <div class="wbi-label">Cancelados/Fallidos</div>
                    <div class="wbi-number"><?php echo ($c_cancelled + $c_failed); ?></div>
                </div>
            </div>

            <!-- SECCIÓN 2: RENDIMIENTO ECONÓMICO -->
            <h2 class="wbi-section-title">📈 Rendimiento (<?php echo date('d/m', strtotime($start_date)) . ' - ' . date('d/m', strtotime($end_date)); ?>)</h2>
            <div class="wbi-grid-4">
                 <div class="wbi-card blue">
                    <div class="wbi-label">Facturación Periodo</div>
                    <div class="wbi-number"><?php echo wc_price($revenue); ?></div>
                </div>
                <div class="wbi-card">
                    <div class="wbi-label">Unidades Vendidas</div>
                    <div class="wbi-number"><?php echo $units; ?></div>
                </div>
            </div>

            <!-- SECCIÓN 3: PRODUCTOS TOP/BOTTOM -->
            <div class="wbi-grid-2">
                <div class="wbi-card">
                    <h3 style="margin-top:0;">🔥 Productos Más Vendidos</h3>
                    <table class="wbi-table">
                        <thead><tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr></thead>
                        <tbody>
                            <?php if(!empty($best_sold) && is_array($best_sold)): foreach(array_slice($best_sold, 0, 5) as $p): ?>
                                <tr>
                                    <td><?php echo $p->name; ?></td>
                                    <td style="text-align:right;"><strong><?php echo $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else: echo "<tr><td colspan='2'>Sin ventas en este periodo</td></tr>"; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="wbi-card red">
                    <h3 style="margin-top:0;">⚠️ Productos con Menos Movimiento</h3>
                    <p style="font-size:12px; color:#666; margin-bottom:10px;">Bottom 5 (de los que tuvieron ventas)</p>
                    <table class="wbi-table">
                        <thead><tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr></thead>
                        <tbody>
                            <?php if(!empty($least_sold) && is_array($least_sold)): foreach(array_slice($least_sold, 0, 5) as $p): ?>
                                <tr>
                                    <td><?php echo $p->name; ?></td>
                                    <td style="text-align:right;"><strong><?php echo $p->qty; ?></strong></td>
                                </tr>
                            <?php endforeach; else: echo "<tr><td colspan='2'>Sin datos</td></tr>"; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }
}