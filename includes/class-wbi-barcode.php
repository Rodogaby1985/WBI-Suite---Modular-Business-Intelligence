<?php
/**
 * WBI Barcode Module
 * Gestión de Códigos de Barra (EAN/UPC) para productos WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WBI_Barcode_Module {

    public function __construct() {
        // Product fields — Simple products
        add_action( 'woocommerce_product_options_sku', array( $this, 'add_barcode_field' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_barcode_field' ) );

        // Product fields — Variations
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_barcode_field' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_barcode_field' ), 10, 2 );

        // Admin column in product list
        add_filter( 'manage_edit-product_columns', array( $this, 'add_barcode_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_barcode_column' ), 10, 2 );

        // Admin menu — Barcode management page
        add_action( 'admin_menu', array( $this, 'register_page' ), 100 );

        // AJAX handler for barcode lookup
        add_action( 'wp_ajax_wbi_barcode_lookup', array( $this, 'ajax_barcode_lookup' ) );

        // AJAX handler for quick barcode assignment
        add_action( 'wp_ajax_wbi_barcode_assign', array( $this, 'ajax_barcode_assign' ) );

        // CSV export/import handlers
        add_action( 'admin_post_wbi_barcode_export', array( $this, 'handle_barcode_export' ) );
        add_action( 'wp_ajax_wbi_barcode_import', array( $this, 'handle_barcode_import' ) );
    }

    // -------------------------------------------------------------------------
    // Product field — Simple
    // -------------------------------------------------------------------------

    public function add_barcode_field() {
        woocommerce_wp_text_input( array(
            'id'          => '_wbi_barcode',
            'label'       => '📊 Código de Barra (EAN/UPC)',
            'placeholder' => 'Ej: 7790001000012',
            'desc_tip'    => true,
            'description' => 'Ingresá el código de barra del proveedor. Se usa para escaneo en picking y facturación.',
        ) );
    }

    public function save_barcode_field( $post_id ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        $barcode = isset( $_POST['_wbi_barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['_wbi_barcode'] ) ) : '';
        update_post_meta( $post_id, '_wbi_barcode', $barcode );
    }

    // -------------------------------------------------------------------------
    // Product field — Variations
    // -------------------------------------------------------------------------

    public function add_variation_barcode_field( $loop, $variation_data, $variation ) {
        woocommerce_wp_text_input( array(
            'id'            => "_wbi_barcode_var_{$loop}",
            'name'          => "_wbi_barcode_var[{$loop}]",
            'label'         => '📊 Código de Barra',
            'placeholder'   => 'Código de barra de esta variación',
            'desc_tip'      => true,
            'description'   => 'Código de barra específico para esta variación.',
            'value'         => get_post_meta( $variation->ID, '_wbi_barcode', true ),
            'wrapper_class' => 'form-row form-row-first',
        ) );
    }

    public function save_variation_barcode_field( $variation_id, $loop ) {
        if ( ! current_user_can( 'edit_post', $variation_id ) ) return;
        $barcodes = isset( $_POST['_wbi_barcode_var'] ) ? (array) $_POST['_wbi_barcode_var'] : array();
        $barcode  = isset( $barcodes[ $loop ] ) ? sanitize_text_field( wp_unslash( $barcodes[ $loop ] ) ) : '';
        update_post_meta( $variation_id, '_wbi_barcode', $barcode );
    }

    // -------------------------------------------------------------------------
    // Admin column in product list
    // -------------------------------------------------------------------------

    public function add_barcode_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $val ) {
            $new[ $key ] = $val;
            if ( $key === 'sku' ) {
                $new['wbi_barcode'] = '📊 Código Barra';
            }
        }
        return $new;
    }

    public function render_barcode_column( $column, $post_id ) {
        if ( 'wbi_barcode' === $column ) {
            $barcode = get_post_meta( $post_id, '_wbi_barcode', true );
            echo $barcode ? '<code>' . esc_html( $barcode ) . '</code>' : '<span style="color:#aaa;">—</span>';
        }
    }

    // -------------------------------------------------------------------------
    // AJAX — Barcode lookup
    // -------------------------------------------------------------------------

    public function ajax_barcode_lookup() {
        check_ajax_referer( 'wbi_barcode_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $barcode = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';
        if ( empty( $barcode ) ) wp_send_json_error( 'Código vacío' );

        global $wpdb;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_parent,
                    pm_barcode.meta_value as barcode,
                    pm_sku.meta_value as sku,
                    pm_stock.meta_value as stock,
                    pm_price.meta_value as price
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_barcode ON p.ID = pm_barcode.post_id AND pm_barcode.meta_key = '_wbi_barcode'
             LEFT JOIN {$wpdb->postmeta} pm_sku   ON p.ID = pm_sku.post_id   AND pm_sku.meta_key   = '_sku'
             LEFT JOIN {$wpdb->postmeta} pm_stock  ON p.ID = pm_stock.post_id  AND pm_stock.meta_key  = '_stock'
             LEFT JOIN {$wpdb->postmeta} pm_price  ON p.ID = pm_price.post_id  AND pm_price.meta_key  = '_price'
             WHERE pm_barcode.meta_value = %s
               AND p.post_status = 'publish'
               AND p.post_type IN ('product', 'product_variation')
             LIMIT 1",
            $barcode
        ) );

        if ( empty( $results ) ) {
            wp_send_json_error( 'Producto no encontrado para código: ' . $barcode );
        }

        $product = $results[0];
        $name    = $product->post_title;

        if ( 'product_variation' === $product->post_type && $product->post_parent ) {
            $parent_title  = get_the_title( $product->post_parent );
            $variation_obj = wc_get_product( $product->ID );
            $attrs         = $variation_obj ? $variation_obj->get_variation_attributes() : array();
            $attr_str      = implode( ', ', array_values( $attrs ) );
            $name          = $parent_title . ( $attr_str ? ' — ' . $attr_str : '' );
        }

        // Thumbnail
        $lookup_id = ( 'product_variation' === $product->post_type && $product->post_parent )
            ? $product->post_parent
            : $product->ID;
        $thumb = get_the_post_thumbnail_url( $lookup_id, 'thumbnail' );

        wp_send_json_success( array(
            'product_id'   => intval( 'product_variation' === $product->post_type ? $product->post_parent : $product->ID ),
            'variation_id' => 'product_variation' === $product->post_type ? intval( $product->ID ) : 0,
            'name'         => $name,
            'barcode'      => $product->barcode,
            'sku'          => $product->sku ?: '',
            'stock'        => $product->stock,
            'price'        => $product->price,
            'thumbnail'    => $thumb ?: '',
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX — Quick barcode assignment
    // -------------------------------------------------------------------------

    public function ajax_barcode_assign() {
        check_ajax_referer( 'wbi_barcode_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );

        $product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
        $barcode    = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';

        if ( ! $product_id || empty( $barcode ) ) wp_send_json_error( 'Datos incompletos' );

        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wbi_barcode' AND meta_value = %s AND post_id != %d LIMIT 1",
            $barcode, $product_id
        ) );

        if ( $existing ) {
            wp_send_json_error( 'Este código ya está asignado al producto #' . $existing . ' (' . get_the_title( $existing ) . ')' );
        }

        update_post_meta( $product_id, '_wbi_barcode', $barcode );
        wp_send_json_success( array( 'message' => 'Código asignado correctamente' ) );
    }

    // -------------------------------------------------------------------------
    // Admin page — Gestión de Códigos de Barra
    // -------------------------------------------------------------------------

    public function register_page() {
        add_submenu_page(
            'wbi-dashboard-view',
            'Códigos de Barra',
            '<span class="dashicons dashicons-tag" style="font-size:16px;line-height:1.5;vertical-align:middle;margin-right:4px;"></span> Códigos de Barra',
            'manage_woocommerce',
            'wbi-barcodes',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'scanner';
        $nonce      = wp_create_nonce( 'wbi_barcode_nonce' );
        ?>
        <div class="wrap">
            <h1>Gestión de Códigos de Barra</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wbi-barcodes&tab=scanner"
                   class="nav-tab <?php echo 'scanner' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    Escáner
                </a>
                <a href="?page=wbi-barcodes&tab=missing"
                   class="nav-tab <?php echo 'missing' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    Productos sin Código
                </a>
                <a href="?page=wbi-barcodes&tab=all"
                   class="nav-tab <?php echo 'all' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    Todos los Códigos
                </a>
                <a href="?page=wbi-barcodes&tab=import_export"
                   class="nav-tab <?php echo 'import_export' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    Importar/Exportar
                </a>
            </nav>

            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:0;">
                <?php
                if ( 'scanner' === $active_tab ) {
                    $this->render_tab_scanner( $nonce );
                } elseif ( 'missing' === $active_tab ) {
                    $this->render_tab_missing( $nonce );
                } elseif ( 'import_export' === $active_tab ) {
                    $this->render_tab_import_export();
                } else {
                    $this->render_tab_all();
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ---- Tab: Escáner -------------------------------------------------------

    private function render_tab_scanner( $nonce ) {
        ?>
        <h2>🔍 Escáner de Códigos de Barra</h2>
        <p>Escaneá o escribí un código de barra para buscar el producto correspondiente.</p>

        <div style="max-width:600px;">
            <div style="display:flex; gap:10px; margin-bottom:20px;">
                <input type="text" id="wbi-barcode-input"
                       placeholder="Escanea o escribí el código..."
                       style="font-size:22px; font-family:monospace; padding:10px; flex:1; border:2px solid #2271b1;"
                       autofocus autocomplete="off" />
                <button id="wbi-barcode-search" class="button button-primary" style="font-size:16px; padding:8px 16px;">
                    Buscar
                </button>
            </div>

            <div id="wbi-barcode-result" style="display:none; padding:15px; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:20px;"></div>

            <div id="wbi-scan-history" style="display:none;">
                <h3>📋 Historial de Escaneos (sesión)</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th>SKU</th>
                            <th>Stock</th>
                            <th>Precio</th>
                        </tr>
                    </thead>
                    <tbody id="wbi-history-body"></tbody>
                </table>
            </div>
        </div>

        <script>
        (function() {
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var history = [];
            var MAX_HISTORY = 20;

            var input   = document.getElementById('wbi-barcode-input');
            var result  = document.getElementById('wbi-barcode-result');
            var histDiv = document.getElementById('wbi-scan-history');
            var histBody = document.getElementById('wbi-history-body');

            function doLookup() {
                var code = input.value.trim();
                if ( ! code ) return;
                input.disabled = true;

                var data = new FormData();
                data.append('action', 'wbi_barcode_lookup');
                data.append('nonce', nonce);
                data.append('barcode', code);

                fetch(ajaxurl, { method:'POST', body:data })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        input.disabled = false;
                        input.value = '';
                        input.focus();

                        if ( res.success ) {
                            var d = res.data;
                            var thumb = d.thumbnail
                                ? '<img src="'+d.thumbnail+'" style="width:60px;height:60px;object-fit:cover;border-radius:4px;margin-right:12px;vertical-align:middle;">'
                                : '';
                            result.style.display = 'block';
                            result.style.borderColor = '#00a32a';
                            result.innerHTML =
                                '<div style="display:flex;align-items:center;">' + thumb +
                                '<div>' +
                                '<strong style="font-size:16px;">' + escHtml(d.name) + '</strong><br>' +
                                '<span>SKU: ' + escHtml(d.sku || '—') + ' &nbsp;|&nbsp; ' +
                                'Stock: <strong>' + escHtml(String(d.stock ?? '—')) + '</strong> &nbsp;|&nbsp; ' +
                                'Precio: <strong>$' + escHtml(String(d.price ?? '—')) + '</strong></span>' +
                                '</div></div>';

                            // Add to history (most recent first)
                            history.unshift({ code: code, name: d.name, sku: d.sku, stock: d.stock, price: d.price });
                            if ( history.length > MAX_HISTORY ) history.pop();
                            renderHistory();
                        } else {
                            result.style.display = 'block';
                            result.style.borderColor = '#d63638';
                            result.innerHTML = '<span style="color:#d63638;">❌ ' + escHtml(res.data) + '</span>';
                        }
                    })
                    .catch(function() {
                        input.disabled = false;
                        input.focus();
                    });
            }

            function renderHistory() {
                if ( history.length === 0 ) { histDiv.style.display = 'none'; return; }
                histDiv.style.display = 'block';
                histBody.innerHTML = history.map(function(h) {
                    return '<tr>' +
                        '<td><code>' + escHtml(h.code) + '</code></td>' +
                        '<td>' + escHtml(h.name) + '</td>' +
                        '<td>' + escHtml(h.sku || '—') + '</td>' +
                        '<td>' + escHtml(String(h.stock ?? '—')) + '</td>' +
                        '<td>$' + escHtml(String(h.price ?? '—')) + '</td>' +
                        '</tr>';
                }).join('');
            }

            function escHtml(str) {
                return String(str)
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;');
            }

            document.getElementById('wbi-barcode-search').addEventListener('click', doLookup);
            input.addEventListener('keydown', function(e) {
                if ( e.key === 'Enter' ) { e.preventDefault(); doLookup(); }
            });
        })();
        </script>
        <?php
    }

    // ---- Tab: Productos sin Código ------------------------------------------

    private function render_tab_missing( $nonce ) {
        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

        $query = new WP_Query( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => '_wbi_barcode', 'compare' => 'NOT EXISTS' ),
                array( 'key' => '_wbi_barcode', 'value' => '', 'compare' => '=' ),
            ),
        ) );
        $product_ids = $query->posts;
        $total       = (int) $query->found_posts;
        ?>
        <h2>⚠️ Productos sin Código de Barra
            <span style="background:#d63638;color:#fff;border-radius:12px;padding:2px 10px;font-size:14px;margin-left:8px;">
                <?php echo intval( $total ); ?>
            </span>
        </h2>

        <?php if ( 0 === $total ) : ?>
            <p style="color:#00a32a;">✅ ¡Todos los productos tienen código de barra asignado!</p>
        <?php else : ?>
            <?php
            $offset = ( $paged - 1 ) * $per_page;
            $from   = $offset + 1;
            $to     = min( $offset + $per_page, $total );
            echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' productos.</p>';
            ?>
            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Stock</th>
                        <th>Asignar Código</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $product_ids as $pid ) :
                        $product = wc_get_product( $pid );
                        if ( ! $product ) continue;
                    ?>
                    <tr data-product-id="<?php echo intval( $pid ); ?>">
                        <td><?php echo esc_html( $product->get_name() ); ?></td>
                        <td><?php echo esc_html( $product->get_sku() ?: '—' ); ?></td>
                        <td><?php echo intval( $product->get_stock_quantity() ); ?></td>
                        <td>
                            <input type="text"
                                   class="wbi-assign-input"
                                   placeholder="Código de barra..."
                                   style="font-family:monospace; width:180px;"
                                   data-product-id="<?php echo intval( $pid ); ?>" />
                            <button class="button wbi-assign-btn" data-product-id="<?php echo intval( $pid ); ?>">
                                Asignar
                            </button>
                            <span class="wbi-assign-msg" style="margin-left:6px;"></span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank" class="button button-small">
                                ✏️ Editar
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // Pagination links
            if ( $total > $per_page ) {
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => ceil( $total / $per_page ),
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                if ( $pagination ) {
                    echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;">' . $pagination . '</div></div>';
                }
            }
            ?>
        <?php endif; ?>

        <script>
        (function() {
            var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';

            document.querySelectorAll('.wbi-assign-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var pid   = btn.getAttribute('data-product-id');
                    var row   = btn.closest('tr');
                    var input = row.querySelector('.wbi-assign-input');
                    var msg   = row.querySelector('.wbi-assign-msg');
                    var code  = input.value.trim();

                    if ( ! code ) { msg.style.color='#d63638'; msg.textContent='Ingresá un código'; return; }

                    var data = new FormData();
                    data.append('action', 'wbi_barcode_assign');
                    data.append('nonce', nonce);
                    data.append('product_id', pid);
                    data.append('barcode', code);

                    btn.disabled = true;
                    fetch(ajaxurl, { method:'POST', body:data })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            btn.disabled = false;
                            if ( res.success ) {
                                msg.style.color = '#00a32a';
                                msg.textContent = '✅ ' + res.data.message;
                                row.style.opacity = '0.4';
                                setTimeout(function(){ row.remove(); }, 1500);
                            } else {
                                msg.style.color = '#d63638';
                                msg.textContent = '❌ ' + res.data;
                            }
                        });
                });
            });

            // Allow Enter key on assign inputs
            document.querySelectorAll('.wbi-assign-input').forEach(function(inp) {
                inp.addEventListener('keydown', function(e) {
                    if ( e.key === 'Enter' ) {
                        e.preventDefault();
                        inp.closest('tr').querySelector('.wbi-assign-btn').click();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // ---- Tab: Todos los Códigos ---------------------------------------------

    private function render_tab_all() {
        $per_page = 20;
        $paged    = max( 1, intval( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

        $query = new WP_Query( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_wbi_barcode',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
        ) );
        $product_ids = $query->posts;
        $total       = (int) $query->found_posts;
        ?>
        <h2>📋 Todos los Códigos de Barra
            <span style="background:#2271b1;color:#fff;border-radius:12px;padding:2px 10px;font-size:14px;margin-left:8px;">
                <?php echo intval( $total ); ?>
            </span>
        </h2>

        <?php if ( 0 === $total ) : ?>
            <p>Aún no hay productos con código de barra asignado.</p>
        <?php else : ?>
            <?php
            $offset = ( $paged - 1 ) * $per_page;
            $from   = $offset + 1;
            $to     = min( $offset + $per_page, $total );
            echo '<p style="color:#50575e;">Mostrando ' . intval( $from ) . '–' . intval( $to ) . ' de ' . intval( $total ) . ' productos.</p>';
            ?>
            <table class="widefat striped wbi-sortable">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Código de Barra</th>
                        <th>Stock</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $product_ids as $pid ) :
                        $product = wc_get_product( $pid );
                        if ( ! $product ) continue;
                        $barcode = get_post_meta( $pid, '_wbi_barcode', true );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $product->get_name() ); ?></td>
                        <td><?php echo esc_html( $product->get_sku() ?: '—' ); ?></td>
                        <td><code><?php echo esc_html( $barcode ); ?></code></td>
                        <td><?php echo intval( $product->get_stock_quantity() ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank" class="button button-small">
                                ✏️ Editar
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // Pagination links
            if ( $total > $per_page ) {
                $pagination = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => ceil( $total / $per_page ),
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                if ( $pagination ) {
                    echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px;">' . $pagination . '</div></div>';
                }
            }
            ?>
        <?php endif; ?>
        <?php
    }

    // ---- Tab: Importar/Exportar ---------------------------------------------

    private function render_tab_import_export() {
        $export_url = add_query_arg( array(
            'action'   => 'wbi_barcode_export',
            '_wpnonce' => wp_create_nonce( 'wbi_barcode_export' ),
        ), admin_url( 'admin-post.php' ) );
        $import_nonce = wp_create_nonce( 'wbi_barcode_import' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        ?>
        <h2>Importar / Exportar Códigos de Barra</h2>

        <div style="display:flex; gap:30px; flex-wrap:wrap;">

            <!-- Export -->
            <div style="flex:1; min-width:280px; border:1px solid #ccd0d4; padding:20px; border-radius:4px;">
                <h3 style="margin-top:0;">Exportar CSV</h3>
                <p>Descargá todos los productos con sus códigos de barra en formato CSV.</p>
                <p>Columnas: <code>product_id, sku, name, barcode</code></p>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">Exportar CSV</a>
            </div>

            <!-- Import -->
            <div style="flex:1; min-width:280px; border:1px solid #ccd0d4; padding:20px; border-radius:4px;">
                <h3 style="margin-top:0;">Importar CSV</h3>
                <p>El archivo debe tener columnas: <code>product_id</code> (o SKU) y <code>barcode</code>. La primera fila se ignora (encabezado).</p>
                <div id="wbi-import-result" style="display:none; margin-bottom:10px;"></div>
                <input type="file" id="wbi-barcode-csv-file" accept=".csv" style="margin-bottom:10px; display:block;">
                <button id="wbi-barcode-import-btn" class="button button-primary">Importar CSV</button>
            </div>
        </div>

        <script>
        (function() {
            var importBtn  = document.getElementById('wbi-barcode-import-btn');
            var fileInput  = document.getElementById('wbi-barcode-csv-file');
            var resultDiv  = document.getElementById('wbi-import-result');

            if ( ! importBtn ) return;

            importBtn.addEventListener('click', function() {
                var file = fileInput.files[0];
                if ( ! file ) { alert('Seleccioná un archivo CSV primero.'); return; }

                importBtn.disabled = true;
                importBtn.textContent = 'Importando...';

                var form = new FormData();
                form.append('action', 'wbi_barcode_import');
                form.append('nonce', '<?php echo esc_js( $import_nonce ); ?>');
                form.append('wbi_csv_file', file);

                fetch('<?php echo esc_js( $ajax_url ); ?>', { method:'POST', body:form })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        importBtn.disabled = false;
                        importBtn.textContent = 'Importar CSV';
                        resultDiv.style.display = 'block';
                        if ( res.success ) {
                            resultDiv.style.background = '#d1fae5';
                            resultDiv.style.border = '1px solid #00a32a';
                            resultDiv.style.padding = '10px';
                            resultDiv.style.borderRadius = '4px';
                            var html = '<strong>✅ Importación completada</strong><br>';
                            html += 'Registros importados: <strong>' + res.data.imported + '</strong>';
                            if ( res.data.errors && res.data.errors.length ) {
                                html += '<br>Errores (' + res.data.errors.length + '): ' + res.data.errors.slice(0,5).join(', ');
                            }
                            resultDiv.innerHTML = html;
                        } else {
                            resultDiv.style.background = '#fce8e8';
                            resultDiv.style.border = '1px solid #d63638';
                            resultDiv.style.padding = '10px';
                            resultDiv.style.borderRadius = '4px';
                            resultDiv.innerHTML = '<strong>❌ Error:</strong> ' + ( res.data || 'Error desconocido' );
                        }
                    })
                    .catch(function() {
                        importBtn.disabled = false;
                        importBtn.textContent = 'Importar CSV';
                        alert('Error de red al importar.');
                    });
            });
        })();
        </script>
        <?php
    }

    // ---- CSV Export handler -------------------------------------------------

    public function handle_barcode_export() {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'wbi_barcode_export' ) ) wp_die( 'Nonce inválido' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos' );

        $products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wbi-barcodes-export-' . date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'product_id', 'sku', 'name', 'barcode' ) );
        foreach ( $products as $pid ) {
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;
            fputcsv( $out, array( $pid, $p->get_sku(), $p->get_name(), get_post_meta( $pid, '_wbi_barcode', true ) ) );
        }
        fclose( $out );
        exit;
    }

    // ---- CSV Import handler -------------------------------------------------

    public function handle_barcode_import() {
        check_ajax_referer( 'wbi_barcode_import', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Sin permisos' );
        if ( empty( $_FILES['wbi_csv_file'] ) ) wp_send_json_error( 'No se recibió archivo' );

        $file   = $_FILES['wbi_csv_file']['tmp_name'];
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) wp_send_json_error( 'No se pudo leer el archivo' );

        $imported = 0;
        $errors   = array();
        $header   = fgetcsv( $handle ); // skip/read header row

        // Detect column positions from header
        $id_col      = 0;
        $barcode_col = 1;
        if ( is_array( $header ) ) {
            $header_lower = array_map( 'strtolower', $header );
            $barcode_pos  = array_search( 'barcode', $header_lower, true );
            if ( false !== $barcode_pos ) {
                $barcode_col = intval( $barcode_pos );
                // Use 'product_id' col if available, otherwise col 0
                $id_pos = array_search( 'product_id', $header_lower, true );
                if ( false !== $id_pos ) {
                    $id_col = intval( $id_pos );
                }
            }
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) <= max( $id_col, $barcode_col ) ) continue;
            $sku_or_id = sanitize_text_field( trim( $row[ $id_col ] ) );
            $barcode   = sanitize_text_field( trim( $row[ $barcode_col ] ) );
            if ( empty( $sku_or_id ) ) continue;
            // Try numeric ID first; if product not found, fall back to SKU lookup.
            if ( is_numeric( $sku_or_id ) ) {
                $pid = intval( $sku_or_id );
                if ( ! wc_get_product( $pid ) ) {
                    $pid = wc_get_product_id_by_sku( $sku_or_id );
                }
            } else {
                $pid = wc_get_product_id_by_sku( $sku_or_id );
            }
            if ( ! $pid ) {
                $errors[] = 'SKU/ID no encontrado: ' . $sku_or_id;
                continue;
            }
            update_post_meta( $pid, '_wbi_barcode', $barcode );
            $imported++;
        }
        fclose( $handle );
        wp_send_json_success( array( 'imported' => $imported, 'errors' => $errors ) );
    }
}
