<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Report_Products {

    private $engine;

    public function __construct() {
        $this->engine = WBI_Metrics_Engine::instance();
        add_action( 'admin_menu', array( $this, 'register' ), 100 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register() {
        add_submenu_page( 'wbi-dashboard-view', 'Productos & Stock', '<span class="dashicons dashicons-archive" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Productos & Stock', 'manage_options', 'wbi-products-report', array( $this, 'render' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wbi-products-report' ) === false ) return;
        wp_enqueue_script( 'wbi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', false );
    }

    public function render() {
        $allowed_tabs = array( 'stock', 'committed', 'dormant', 'best', 'worst' );
        $tab          = WBI_Admin_Query_Helper::get_enum( $_GET, 'tab', $allowed_tabs, 'stock' );
        $date_range = WBI_Admin_Query_Helper::normalize_date_range_with_meta(
            $_GET,
            'start',
            'end',
            WBI_Admin_Query_Helper::get_site_date_ymd( 'first day of january' ),
            WBI_Admin_Query_Helper::get_site_date_ymd()
        );
        $start = $date_range['from'];
        $end   = $date_range['to'];
        $default_statuses = array('wc-completed', 'wc-processing');
        $statuses = WBI_Admin_Query_Helper::get_string_array( $_GET, 'statuses', array_keys( array(
            'wc-completed' => true,
            'wc-processing' => true,
            'wc-on-hold' => true,
            'wc-pending' => true,
        ) ) );
        if ( empty( $statuses ) ) {
            $statuses = $default_statuses;
        }
        $allowed_per_page = array( 10, 25, 50, 100 );
        $requested_per_page = WBI_Admin_Query_Helper::get_absint( $_GET, 'per_page', 25 );
        $per_page = in_array( $requested_per_page, $allowed_per_page, true ) ? $requested_per_page : 25;
        $current_page = max( 1, WBI_Admin_Query_Helper::get_absint( $_GET, 'paged', 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;
        $total_rows   = 0;
        $total_pages  = 1;

        $all_statuses = array(
            'wc-completed'  => '✅ Completado',
            'wc-processing' => '🔄 En proceso',
            'wc-on-hold'    => '⏸ En espera',
            'wc-pending'    => '⏳ Pendiente',
        );

        // Mapeo para saber qué reporte pedir al exportador
        $export_map = [
            'stock'     => 'stock_real',
            'committed' => 'stock_committed',
            'dormant'   => 'stock_dormant',
            'best'      => 'best_sellers',
            'worst'     => 'worst_sellers'
        ];
        $export_type = $export_map[$tab] ?? 'stock_real';
        $export_url = WBI_Admin_Query_Helper::build_url(
            admin_url( 'admin-post.php' ),
            array(
                'action'      => 'wbi_export_dynamic',
                'report_type' => $export_type,
                'start'       => $start,
                'end'         => $end,
                'statuses'    => $statuses,
                '_wpnonce'    => wp_create_nonce( 'wbi_export_dynamic' ),
            )
        );
        $reset_url = admin_url( 'admin.php?page=wbi-products-report' );
        $tabs      = array(
            'stock'     => array( 'label' => 'Stock real', 'url' => add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => 'stock' ), admin_url( 'admin.php' ) ) ),
            'committed' => array( 'label' => 'Stock comprometido', 'url' => add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => 'committed' ), admin_url( 'admin.php' ) ) ),
            'dormant'   => array( 'label' => 'Stock dormido (+90d)', 'url' => add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => 'dormant' ), admin_url( 'admin.php' ) ) ),
            'best'      => array( 'label' => 'Más vendidos', 'url' => add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => 'best' ), admin_url( 'admin.php' ) ) ),
            'worst'     => array( 'label' => 'Menos vendidos', 'url' => add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => 'worst' ), admin_url( 'admin.php' ) ) ),
        );

        ?>
        <?php WBI_Admin_Shell::open_page(); ?>
            <?php if ( $date_range['has_error'] ) : ?>
                <?php WBI_Admin_Shell::render_notice( esc_html__( 'El rango de fechas enviado no es válido o estaba invertido. Se aplicó el rango por defecto.', 'wbi-suite' ), 'warning' ); ?>
            <?php endif; ?>
            <?php
            WBI_Admin_Shell::render_header(
                array(
                    'title'       => 'Productos y stock',
                    'description' => 'Vista administrativa unificada para inventario actual, stock comprometido y desempeño comercial de productos.',
                    'actions'     => array(
                        array(
                            'url'        => $export_url,
                            'label'      => 'Exportar CSV',
                            'class'      => 'wbi-btn wbi-btn-primary',
                            'aria_label' => 'Exportar la tabla actual de productos y stock a CSV',
                        ),
                        array(
                            'url'   => $reset_url,
                            'label' => 'Restablecer',
                            'class' => 'wbi-btn',
                        ),
                    ),
                )
            );
            WBI_Admin_Shell::render_tabs( $tabs, $tab, array( 'label' => 'Secciones del reporte de productos y stock' ) );
            ?>

            <!-- FILTRO DE FECHAS: SOLO PARA MÁS/MENOS VENDIDOS -->
            <?php if( $tab == 'best' || $tab == 'worst' ): ?>
            <form method="get" class="wbi-filter-panel">
                <div class="wbi-filter-grid">
                    <input type="hidden" name="page" value="wbi-products-report">
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-products-start">Desde</label>
                        <input id="wbi-products-start" type="date" name="start" value="<?php echo esc_attr( $start ); ?>">
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-products-end">Hasta</label>
                        <input id="wbi-products-end" type="date" name="end" value="<?php echo esc_attr( $end ); ?>">
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <label for="wbi-products-statuses">Estados del pedido</label>
                        <select id="wbi-products-statuses" name="statuses[]" multiple size="4" title="Mantené Ctrl/Cmd para seleccionar múltiples">
                            <?php foreach ( $all_statuses as $val => $label ) : ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php echo in_array($val, $statuses, true) ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wbi-filter-field wbi-col-3">
                        <div class="wbi-filter-actions">
                            <button class="wbi-btn wbi-btn-primary" type="submit">Actualizar</button>
                            <a class="wbi-btn" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wbi-products-report', 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>">Limpiar</a>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <section class="wbi-card">
                <?php
                if($tab=='stock'){
                    $total_rows = $this->engine->count_realtime_stock();
                    $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
                    if ( $current_page > $total_pages ) {
                        $current_page = $total_pages;
                        $offset       = ( $current_page - 1 ) * $per_page;
                    }
                    $data = $this->engine->get_realtime_stock( $per_page, $offset );
                    echo '<p class="wbi-page-summary">Inventario físico actual registrado en el sistema.</p>';
                    echo '<form method="get" class="wbi-filter-bar">';
                    echo '<input type="hidden" name="page" value="wbi-products-report">';
                    echo '<input type="hidden" name="tab" value="stock">';
                    echo '<input type="hidden" name="start" value="' . esc_attr( $start ) . '">';
                    echo '<input type="hidden" name="end" value="' . esc_attr( $end ) . '">';
                    foreach ( $statuses as $status_value ) {
                        echo '<input type="hidden" name="statuses[]" value="' . esc_attr( $status_value ) . '">';
                    }
                    echo '<label for="wbi-stock-per-page">Por página</label>';
                    echo '<select id="wbi-stock-per-page" name="per_page">';
                    foreach ( $allowed_per_page as $pp ) {
                        echo '<option value="' . esc_attr( $pp ) . '" ' . selected( $per_page, $pp, false ) . '>' . esc_html( $pp ) . '</option>';
                    }
                    echo '</select>';
                    echo '<button class="wbi-btn" type="submit">Aplicar</button>';
                    echo '</form>';
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Producto</th><th data-align="right">Stock actual</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->post_title) . "</td><td data-align='right'><span class='wbi-badge wbi-badge-primary'>" . intval($d->stock) . "</span></td></tr>";
                    } else {
                        echo '<tr><td colspan="2">No hay productos con stock registrado.</td></tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='committed'){
                    $total_rows = $this->engine->count_committed_stock();
                    $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
                    if ( $current_page > $total_pages ) {
                        $current_page = $total_pages;
                        $offset       = ( $current_page - 1 ) * $per_page;
                    }
                    $data = $this->engine->get_committed_stock( $per_page, $offset );
                    echo '<p class="wbi-page-summary">Productos reservados en pedidos pendientes de envío.</p>';
                    echo '<form method="get" class="wbi-filter-bar">';
                    echo '<input type="hidden" name="page" value="wbi-products-report">';
                    echo '<input type="hidden" name="tab" value="committed">';
                    echo '<input type="hidden" name="start" value="' . esc_attr( $start ) . '">';
                    echo '<input type="hidden" name="end" value="' . esc_attr( $end ) . '">';
                    foreach ( $statuses as $status_value ) {
                        echo '<input type="hidden" name="statuses[]" value="' . esc_attr( $status_value ) . '">';
                    }
                    echo '<label for="wbi-committed-per-page">Por página</label>';
                    echo '<select id="wbi-committed-per-page" name="per_page">';
                    foreach ( $allowed_per_page as $pp ) {
                        echo '<option value="' . esc_attr( $pp ) . '" ' . selected( $per_page, $pp, false ) . '>' . esc_html( $pp ) . '</option>';
                    }
                    echo '</select>';
                    echo '<button class="wbi-btn" type="submit">Aplicar</button>';
                    echo '</form>';
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Producto</th><th data-align="right">Cantidad</th><th>Pedido</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td data-align='right'>" . intval($d->qty) . "</td><td><a href='post.php?post=" . intval($d->order_id) . "&action=edit'>#" . intval($d->order_id) . "</a></td></tr>";
                    } else {
                        echo '<tr><td colspan="3">No hay stock comprometido actualmente.</td></tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='dormant'){
                    $total_rows = $this->engine->count_dormant_stock();
                    $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
                    if ( $current_page > $total_pages ) {
                        $current_page = $total_pages;
                        $offset       = ( $current_page - 1 ) * $per_page;
                    }
                    $data = $this->engine->get_dormant_stock( $per_page, $offset );
                    echo '<p class="wbi-page-summary">Productos con stock positivo sin movimiento en los últimos 90 días.</p>';
                    echo '<form method="get" class="wbi-filter-bar">';
                    echo '<input type="hidden" name="page" value="wbi-products-report">';
                    echo '<input type="hidden" name="tab" value="dormant">';
                    echo '<input type="hidden" name="start" value="' . esc_attr( $start ) . '">';
                    echo '<input type="hidden" name="end" value="' . esc_attr( $end ) . '">';
                    foreach ( $statuses as $status_value ) {
                        echo '<input type="hidden" name="statuses[]" value="' . esc_attr( $status_value ) . '">';
                    }
                    echo '<label for="wbi-dormant-per-page">Por página</label>';
                    echo '<select id="wbi-dormant-per-page" name="per_page">';
                    foreach ( $allowed_per_page as $pp ) {
                        echo '<option value="' . esc_attr( $pp ) . '" ' . selected( $per_page, $pp, false ) . '>' . esc_html( $pp ) . '</option>';
                    }
                    echo '</select>';
                    echo '<button class="wbi-btn" type="submit">Aplicar</button>';
                    echo '</form>';
                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Producto</th><th data-align="right">Stock inmovilizado</th><th>Último movimiento</th></tr></thead><tbody>';
                    if ( ! empty( $data ) ) {
                        foreach($data as $d) echo "<tr><td>" . esc_html($d->post_title) . "</td><td data-align='right'>" . intval($d->stock) . "</td><td>" . esc_html( date_i18n( 'd/m/Y', strtotime($d->post_modified) ) ) . "</td></tr>";
                    } else {
                        echo '<tr><td colspan="3">No hay productos con stock dormido.</td></tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='best'){
                    $data = $this->engine->get_best_sellers($start, $end, $statuses);
                    echo "<p class='wbi-page-summary'>Ranking del <strong>" . esc_html($start) . "</strong> al <strong>" . esc_html($end) . "</strong>.</p>";

                    if ( $data ) {
                        $prod_names = wp_json_encode( array_map( function($r){ return $r->name; }, $data ) );
                        $prod_qtys  = wp_json_encode( array_map( function($r){ return intval($r->qty); }, $data ) );
                        echo '<div class="wbi-chart-container"><canvas id="wbiBestChart" aria-label="Gráfico de barras de productos más vendidos"></canvas></div>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiBestChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $prod_names . ', datasets:[{label:"Unidades",data:' . $prod_qtys . ',backgroundColor:"rgba(0,163,42,0.7)",borderColor:"#00a32a",borderWidth:1}]}, options:{indexAxis:"y", responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}}});
                        })();
                        </script>';
                    }

                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Producto</th><th data-align="right">Unidades vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td data-align='right'><strong>" . intval($d->qty) . "</strong></td></tr>";
                    else echo "<tr><td colspan=2>Sin ventas en este periodo.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                } elseif($tab=='worst'){
                    $data = $this->engine->get_least_sold($start, $end, $statuses);
                    echo "<p class='wbi-page-summary'>Productos con menor salida del <strong>" . esc_html($start) . "</strong> al <strong>" . esc_html($end) . "</strong> (pero con al menos 1 venta).</p>";

                    if ( $data ) {
                        $prod_names = wp_json_encode( array_map( function($r){ return $r->name; }, $data ) );
                        $prod_qtys  = wp_json_encode( array_map( function($r){ return intval($r->qty); }, $data ) );
                        echo '<div class="wbi-chart-container"><canvas id="wbiWorstChart" aria-label="Gráfico de barras de productos menos vendidos"></canvas></div>';
                        echo '<script>
                        (function(){
                            var ctx = document.getElementById("wbiWorstChart");
                            if(ctx && typeof Chart !== "undefined") new Chart(ctx, {type:"bar", data:{labels:' . $prod_names . ', datasets:[{label:"Unidades",data:' . $prod_qtys . ',backgroundColor:"rgba(214,54,56,0.7)",borderColor:"#d63638",borderWidth:1}]}, options:{indexAxis:"y", responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true}}}});
                        })();
                        </script>';
                    }

                    echo '<div class="wbi-table-responsive">'; 
                    echo '<table class="wbi-table wbi-sortable"><thead><tr><th>Producto</th><th data-align="right">Unidades vendidas</th></tr></thead><tbody>';
                    if($data) foreach($data as $d) echo "<tr><td>" . esc_html($d->name) . "</td><td data-align='right'>" . intval($d->qty) . "</td></tr>";
                    else echo "<tr><td colspan=2>Sin datos.</td></tr>";
                    echo '</tbody></table>';
                    echo '</div>';
                }
                ?>
                <?php if ( in_array( $tab, array( 'stock', 'committed', 'dormant' ), true ) ) : ?>
                    <?php
                    $pagination = paginate_links( array(
                        'base'      => add_query_arg(
                            array(
                                'page'     => 'wbi-products-report',
                                'tab'      => $tab,
                                'start'    => $start,
                                'end'      => $end,
                                'statuses' => $statuses,
                                'per_page' => $per_page,
                                'paged'    => '%#%',
                            ),
                            admin_url( 'admin.php' )
                        ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => max( 1, $total_pages ),
                        'prev_text' => '« Anterior',
                        'next_text' => 'Siguiente »',
                        'type'      => 'list',
                    ) );
                    ?>
                    <?php WBI_Admin_Shell::render_pagination( $pagination, sprintf( 'Página %1$d de %2$d · Total: %3$d', $current_page, max( 1, $total_pages ), (int) $total_rows ) ); ?>
                <?php endif; ?>
            </section>
        <?php WBI_Admin_Shell::close_page(); ?>
        <?php
    }
}