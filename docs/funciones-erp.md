# Funciones del ERP — inventario funcional de wooErp

## Resumen ejecutivo

- **Módulos activables detectados en código:** 33
- **Funciones reales inventariadas:** 55
- **Fuente prioritaria para novedades:** tarea reciente de relevamiento (`eca018f9-18d4-4664-9065-dfd8a54ae577`) + commits funcionales verificados de agosto/septiembre de 2026.
- **Superficies revisadas:** loader central, clases en `includes/`, wrappers en `modules/`, hooks WooCommerce/WordPress, endpoints AJAX/REST, vistas admin, scripts JS y docs ya consolidadas en el repo.

## Metodología de relevamiento

1. Se tomó `wbi-suite.php` como punto de entrada para validar módulos activos, toggles, roles y pantallas transversales.
2. Se usó `docs/relevamiento-funcional.csv` y `docs/relevamiento-funcional.md` como inventario previo consolidado y se mantuvo únicamente información respaldada por código real.
3. Se reagruparon las 55 funciones en módulos comerciales legibles para la landing, sin eliminar funciones ni inventar capacidades nuevas.
4. Las novedades recientes se priorizaron con la tarea enlazada y se contrastaron contra SHAs ya verificados dentro del relevamiento funcional existente.

## Resumen por módulo comercial

| Módulo comercial | Funciones incluidas |
|---|---:|
| Ventas / B2B | 13 |
| Stock e inventario | 5 |
| Compras / proveedores | 3 |
| Reportes y estadísticas | 11 |
| Usuarios / permisos / administración | 7 |
| Configuración general e integraciones | 16 |

## Inventario completo por módulos del ERP

### Ventas / B2B

Gestiona venta mayorista, precios, CRM, checkout comercial y mostrador desde el mismo WooCommerce.

- **Aprobación manual o automática de mayoristas** (`Modo Mayorista B2B`) — Gestiona el alta de clientes mayoristas y su aprobación desde el admin. **Valor de negocio:** Controla quién accede a condiciones mayoristas reales.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-b2b.php (acciones de usuario/rol mayorista, profile hooks), wbi-suite.php flags `wbi_enable_b2b`, `wbi_b2b_auto_approve``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Ocultamiento de precios y compra para no autorizados** (`Modo Mayorista B2B`) — Aplica reglas para ocultar precios o impedir compra a usuarios fuera de los roles permitidos. **Valor de negocio:** Protege listas mayoristas y evita ventas fuera de política.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-b2b.php; wbi-suite.php migración de flags `wbi_b2b_enable_hide_prices` y autorizaciones B2B`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Monto mínimo mayorista jerárquico** (`Modo Mayorista B2B`) — Resuelve el mínimo por override de usuario, rol, lista de precios o fallback global antes de permitir el pedido. **Valor de negocio:** Formaliza políticas comerciales por segmento sin duplicar lógica.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-minimum-order-resolver.php; includes/class-wbi-b2b.php; includes/class-wbi-pricelists.php`
  - Commits/fuente reciente: 9b659da / f93aefe (agosto 2026)
- **Listas de precios por cliente, rol o grupo** (`Listas de Precios`) — Administra listas de precios y sus asignaciones desde una pantalla propia del backoffice. **Valor de negocio:** Permite segmentar precios sin replicar catálogos.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-pricelists.php (`admin_post_wbi_save_pricelist`, `admin_post_wbi_delete_pricelist`, submenu `wbi-pricelists`)`
  - Commits/fuente reciente: 9b659da (amplía mínimo por lista de precios)
- **Pedido rápido mayorista desde catálogo** (`Pedido rápido mayorista público`) — Habilita selección de variantes, cantidad y agregado rápido desde el loop de productos. **Valor de negocio:** Reduce fricción en pedidos grandes y acelera reposiciones.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-public-wholesale-quick-order.php (`wp_enqueue_scripts`), assets/js/wbi-public-wholesale-quick-order.js, modules/public-wholesale-quick-order/public-wholesale-quick-order.php`
  - Commits/fuente reciente: 717551b (agosto 2026)
- **Precio financiado y descuento por transferencia** (`Precio Promo`) — Muestra precio promocional y recalcula el checkout según forma de pago. **Valor de negocio:** Mejora conversión y comunica incentivos de cobro.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-promo-pricing.php; modules/woo-precio-promo/includes/class-checkout-fee.php; modules/woo-precio-promo/assets/js/checkout-refresh.js`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Costo por producto y cálculo de márgenes** (`Costos y Márgenes`) — Agrega campos de costo y umbral de alerta para analizar margen bruto. **Valor de negocio:** Da visibilidad de rentabilidad por producto.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-costs.php (hooks sobre producto y option `wbi_margin_alert_threshold`), submenu `wbi-costs``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Captura de contacto para carritos abandonados** (`Carritos Abandonados`) — Captura y actualiza email/teléfono de carritos con endpoints públicos autenticados por nonce. **Valor de negocio:** Genera base accionable para recuperar ventas.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-abandoned-carts.php (`wp_ajax_nopriv_wbi_capture_cart_contact`, `wp_ajax_nopriv_wbi_update_cart_data`), assets/js/wbi-abandoned-carts.js`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Recordatorios y recuperación de carritos** (`Carritos Abandonados`) — Programa tareas para marcar carritos abandonados, enviar recordatorios y limpiar expirados, con acciones manuales y masivas desde admin. **Valor de negocio:** Automatiza recuperación de ingresos sin seguimiento manual caso por caso.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-abandoned-carts.php (`wbi_mark_abandoned_carts`, `wbi_send_cart_reminders`, `wbi_cleanup_expired_carts`, AJAX `wbi_send_manual_reminder`, `wbi_bulk_send_reminders`)`
  - Commits/fuente reciente: d05aeae / fb64786 (agosto 2026)
- **Pipeline de leads y etapas CRM** (`CRM / Pipeline de Ventas`) — Crea, mueve y reordena leads por etapas en un pipeline propio. **Valor de negocio:** Da seguimiento comercial estructurado a oportunidades.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-crm.php (dbDelta tablas `wbi_crm_*`, AJAX `wbi_crm_move_lead`, `wbi_crm_reorder_stages`, submenu `wbi-crm`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Actividades CRM y conversión a cliente** (`CRM / Pipeline de Ventas`) — Registra actividades, marca leads como ganados/perdidos y convierte oportunidades a cliente. **Valor de negocio:** Acorta el pasaje de prospecto a operación real en WooCommerce.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-crm.php (AJAX `wbi_crm_add_activity`, `wbi_crm_complete_activity`, `wbi_crm_convert_customer`, `wbi_crm_mark_won`, `wbi_crm_mark_lost`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **POS con búsqueda y carga de productos** (`POS / Mostrador`) — Expone la interfaz de mostrador y el endpoint de búsqueda de productos para armar pedidos. **Valor de negocio:** Acelera ventas presenciales o asistidas.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-pos.php (menús `wbi-pos`, AJAX `wbi_pos_search_products`, `wbi_pos_create_order`), assets/pos.js, assets/pos.css`
  - Commits/fuente reciente: d3e9995 / 97ea980 (agosto 2026)
- **Alta rápida de clientes y ajustes en POS** (`POS / Mostrador`) — Permite buscar clientes ampliados, crear clientes inline y aplicar descuentos/recargos/envío/impuesto manual. **Valor de negocio:** Evita abandonar la operación para resolver excepciones comerciales.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-pos.php (AJAX `wbi_pos_search_customers`, `wbi_pos_create_customer` y lógica de adjustments), assets/pos.js`
  - Commits/fuente reciente: d6fec9b / 0e0869c / 2c866d3 (agosto 2026)

### Stock e inventario

Da trazabilidad a catálogo, depósito y preparación de pedidos con herramientas listas para operar.

- **Alertas de stock** (`Alertas de stock`) — Lista alertas de productos críticos y permite exportarlas desde admin. **Valor de negocio:** Disminuye quiebres de stock.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-stock-alerts.php (`admin_post_wbi_stock_export`, submenu `wbi-stock-alerts`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Asignación e importación de códigos de barra** (`Códigos de Barra`) — Permite lookup, asignación e importación de códigos EAN/UPC para productos. **Valor de negocio:** Prepara al catálogo para procesos de depósito y escaneo.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-barcode.php (AJAX `wbi_barcode_lookup`, `wbi_barcode_assign`, `wbi_barcode_import`, submenu `wbi-barcode`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **QR únicos por producto y etiquetas imprimibles** (`QR de Productos`) — Genera tokens QR, resuelve escaneo para POS/web y administra regeneración y backfill. **Valor de negocio:** Acelera toma de pedido y operaciones de escaneo.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-product-qr.php (AJAX `wbi_qr_pos_resolve`, `wbi_qr_regenerate`, `admin_post_wbi_qr_backfill`, submenu `wbi-product-qr`), assets/js/wbi-qr-admin.js`
  - Commits/fuente reciente: 08d868c / f844c0d / 8677405 (agosto 2026)
- **Picking con inicio, escaneo y cierre por pedido** (`Picking & Armado`) — Inicia picking, escanea ítems y completa pedidos desde panel dedicado. **Valor de negocio:** Reduce errores de preparación en depósito.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-picking.php (AJAX `wbi_picking_start`, `wbi_picking_scan`, `wbi_picking_complete`, menús `wbi-picking` y `wbi-picking-panel`)`
  - Commits/fuente reciente: 9fc9716 / 5b57eac (agosto 2026)
- **Edición manual y auditoría dentro del picking** (`Picking & Armado`) — Permite marcar ítems, editar cantidades, remover/agregar líneas y guardar notas del pedido. **Valor de negocio:** Hace flexible la preparación sin salir del flujo operativo.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-picking.php (AJAX `wbi_picking_mark_item`, `wbi_picking_edit_qty`, `wbi_picking_remove_item`, `wbi_picking_add_item`, `wbi_picking_order_notes`)`
  - Commits/fuente reciente: 9fc9716 (agosto 2026)

### Compras / proveedores

Ordena abastecimiento, recepción y relación con proveedores sin salir del ERP.

- **Maestro de proveedores** (`Proveedores`) — Registra proveedores como post type propio y los vincula con productos. **Valor de negocio:** Ordena abastecimiento y relación catálogo-proveedor.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-suppliers.php (`register_post_type wbi_supplier`, submenu `wbi-suppliers`, `wbi-supplier-products`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Reglas automáticas de reabastecimiento** (`Reglas de Reabastecimiento`) — Permite crear reglas, activarlas/desactivarlas, ejecutarlas manualmente o por cron y disparar compras. **Valor de negocio:** Reduce faltantes por monitoreo manual.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-reorder.php (`wbi_reorder_check`, AJAX `wbi_reorder_run_now`, `wbi_reorder_save_rule`, `wbi_reorder_toggle_active`, submenu `wbi-reorder`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Órdenes de compra y recepción de mercadería** (`Órdenes de Compra`) — Guarda órdenes de compra, cambia estados, busca productos y registra recepción. **Valor de negocio:** Cierra el circuito de abastecimiento dentro del plugin.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-purchase.php (dbDelta tablas de órdenes/ítems/recepciones, AJAX `wbi_purchase_save`, `wbi_purchase_receive`, `wbi_purchase_update_status`, submenu `wbi-purchase`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.

### Reportes y estadísticas

Concentra KPIs, reportes comerciales y lectura financiera para decidir con datos reales.

- **Dashboard ejecutivo de KPIs** (`Dashboard BI Suite`) — Expone tablero principal con métricas y accesos a análisis detallados. **Valor de negocio:** Centraliza visibilidad operativa y comercial.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-dashboard.php (menu `wbi-dashboard-view`), includes/class-wbi-metrics.php, wbi-suite.php carga del dashboard`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Reporte detallado de ventas** (`Reporte de ventas`) — Muestra análisis detallado de ventas como subpantalla del dashboard. **Valor de negocio:** Facilita lectura por período y desempeño comercial.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-report-sales.php (submenu `wbi-sales-report`), includes/class-wbi-export.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Análisis de clientes** (`Reporte de clientes`) — Expone ranking y detalle analítico de clientes como vista específica del dashboard. **Valor de negocio:** Ayuda a priorizar cuentas y segmentos de alto valor.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-report-clients.php (submenu `wbi-clients-report`), includes/class-wbi-metrics.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Análisis de productos y stock** (`Reporte de productos`) — Expone vista detallada de productos y stock dentro del bloque BI. **Valor de negocio:** Permite detectar rotación y productos críticos.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-report-products.php (submenu `wbi-products-report`), includes/class-wbi-metrics.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Exportaciones CSV del stack BI** (`Exportación CSV`) — Centraliza endpoints de exportación CSV para reportes de ventas, clientes y productos. **Valor de negocio:** Permite reutilizar información fuera del admin.
  - Estado: Activa interna/técnica
  - Evidencia: `includes/class-wbi-export.php (`admin_post_wbi_export_sales`, `admin_post_wbi_export_clients`, `admin_post_wbi_export_products`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Reportes periódicos por email** (`Email Reports`) — Programa el envío periódico de reportes y permite prueba manual por AJAX. **Valor de negocio:** Lleva KPIs a quienes no ingresan todos los días al backoffice.
  - Estado: Activa interna/técnica
  - Evidencia: `includes/class-wbi-email-reports.php (`wbi_send_scheduled_report`, AJAX `wbi_send_test_email`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Scoring RFM diario de clientes** (`Scoring de Clientes`) — Calcula scoring de clientes y ofrece recálculo y exportación desde el admin. **Valor de negocio:** Facilita segmentación comercial basada en comportamiento.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-scoring.php (`wbi_scoring_daily`, AJAX `wbi_scoring_recalc`, `admin_post_wbi_scoring_export`, submenu `wbi-scoring`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Reportes contables** (`Reportes Contables`) — Carga reportes contables y exporta CSV desde una pantalla propia. **Valor de negocio:** Une operación WooCommerce con lectura financiera.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-accounting-reports.php (AJAX `wbi_accrep_load_report`, `wbi_accrep_export_csv`, submenu `wbi-accounting-reports`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Remitos PDF por pedido** (`Documentos / Remitos`) — Genera remitos desde el pedido y los vincula al módulo unificado de documentos. **Valor de negocio:** Formaliza despacho y respaldo documental.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-remitos.php (AJAX `wbi_generate_remito`, admin-post handlers), includes/class-wbi-documents.php, submenu `wbi-documents``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Documentos comerciales unificados** (`Documentos`) — Unifica pantalla y handlers para factura y remito cuando alguno de ambos módulos está activo. **Valor de negocio:** Concentra documentación comercial del pedido.
  - Estado: Activa visible
  - Evidencia: `wbi-suite.php (carga condicional de `includes/class-wbi-documents.php`), includes/class-wbi-documents.php, submenu `wbi-documents``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Proyección de flujo de caja** (`Flujo de Caja`) — Expone reporte financiero específico desde una pantalla propia. **Valor de negocio:** Ayuda a anticipar liquidez operativa.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-cashflow.php (submenu `wbi-cashflow` y handlers de configuración/exportación)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.

### Usuarios / permisos / administración

Controla accesos, perfiles operativos, alta de usuarios y circuitos administrativos internos.

- **Activación y bloqueo por licencia** (`Licencia central`) — Sin licencia activa el loader no inicializa módulos funcionales y sólo expone la pantalla de activación. **Valor de negocio:** Protege el esquema comercial y evita uso parcial inconsistente de la suite.
  - Estado: Activa interna/técnica
  - Evidencia: `wbi-suite.php (WBI_Suite_Loader::load_modules), includes/class-wbi-license.php, menú admin page `wbi-license``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Superadmin WBI y permisos por usuario** (`Permisos WBI`) — Filtra menús por permisos WBI, transfiere superadmin y restringe vistas para roles de caja. **Valor de negocio:** Permite delegar funciones sin exponer todo el backoffice.
  - Estado: Activa visible
  - Evidencia: `wbi-suite.php (`filter_wbi_menus_by_permission`, `restrict_pos_role_menus`, `handle_wbi_superadmin_actions`, `get_wbi_module_list`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Roles operativos dedicados** (`Permisos WBI`) — Crea roles `wholesale_customer`, `wbi_armador`, `wbi_cashier` y `wbi_vendedor` con capacidades específicas. **Valor de negocio:** Alinea permisos con procesos de depósito, venta y mayorista.
  - Estado: Activa interna/técnica
  - Evidencia: `wbi-suite.php (`ensure_wbi_roles` y `add_role(...)`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Registro WooCommerce segmentado** (`Registro WooCommerce`) — Agrega campos al registro y permite asignar roles distintos según tipo de cliente y reglas de alta. **Valor de negocio:** Ordena el onboarding comercial sin procesos manuales fuera del sitio.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-registration-fields.php (`woocommerce_register_form`, `woocommerce_created_customer`); wbi-suite.php settings de registro`
  - Commits/fuente reciente: 89a4896 / c816927 / e0586b2 (agosto 2026)
- **Validación CP vs provincia en checkout** (`Validación de Checkout`) — Valida coherencia entre código postal y provincia durante la compra. **Valor de negocio:** Reduce errores de despacho y retrabajo logístico.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-checkout-validator.php; loader en wbi-suite.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Gestión integral de empleados y RRHH** (`Empleados / RRHH`) — Crea tablas y pantallas para empleados, departamentos, contratos, skills, ubicaciones y plantillas. **Valor de negocio:** Amplía la suite a procesos internos no comerciales.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-employees.php (dbDelta tablas `wbi_departments`, `wbi_employees`, `wbi_employee_contracts`, submenu `wbi-employees`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Caja POS: apertura, cierre, sesiones y movimientos** (`POS Caja`) — Registra estado de caja, movimientos y exportación de sesiones por CSV. **Valor de negocio:** Aporta trazabilidad de caja y control de arqueo.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-pos.php (AJAX `wbi_pos_open_cash`, `wbi_pos_close_cash`, `wbi_pos_add_movement`, `wbi_pos_get_movements`), includes/class-wbi-pos-cash-admin.php, includes/class-wbi-pos-cash-sessions.php, includes/class-wbi-pos-cash-movements.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.

### Configuración general e integraciones

Centraliza parametrización del ERP e integra logística, cobros, API y automatizaciones omnicanal.

- **Configuración centralizada de módulos** (`Configuración central`) — Permite activar o desactivar 33 módulos mediante la option `wbi_modules_settings` desde WooCommerce → wooErp Config. **Valor de negocio:** Reduce carga técnica al instalar sólo lo necesario en cada operación.
  - Estado: Activa visible
  - Evidencia: `wbi-suite.php (`get_module_toggle_keys`, `register_settings`, `render_settings_page`), submenu `wbi-settings``
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Recuperación defensiva de settings corruptos** (`Configuración central`) — Reconstruye o restaura el estado de activación de módulos si la option principal queda dañada. **Valor de negocio:** Evita caídas operativas por corrupción de configuración.
  - Estado: Activa interna/técnica
  - Evidencia: `wbi-suite.php (`maybe_recover_corrupted_module_states`, backup option/transient)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Tarifas de transportistas argentinos** (`MobApp Envíos`) — Expone métodos de envío y tarifas para Andreani, Correo Argentino, OCA, Urbano y Flash. **Valor de negocio:** Mejora cotización logística dentro del checkout.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-mobapp-shipping.php; modules/mobapp-envios/main.php (`woocommerce_shipping_init`, `woocommerce_shipping_methods`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Selección de transportista o micro** (`Transporte Multiopciones`) — Permite elegir transportista personalizado y guarda la selección en sesión, pedido, email y detalle. **Valor de negocio:** Adapta el checkout a logística B2B no estandarizada.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-multi-shipping.php; modules/transporte-multiopciones/MOBAPP-LOGISTICA-INTELIGENTE-TRANSPORTES-Y-MICROS-PERSONALIZADO.php (AJAX `mobapp_save_carrier` y hooks WooCommerce)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Facturación AFIP y configuración fiscal** (`Facturación AFIP`) — Configura datos fiscales y expone acciones administrativas del módulo de facturación. **Valor de negocio:** Acerca el circuito fiscal al mismo backoffice operativo.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-invoice.php (`admin_post_wbi_save_invoice_settings`, `admin_post_wbi_generate_invoice`, `admin_post_wbi_delete_invoice`, submenu asociado a documentos)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Notas de crédito y débito** (`Notas de Crédito / Débito`) — Permite guardar, autorizar, cancelar y copiar ítems de facturas para NC/ND. **Valor de negocio:** Completa correcciones fiscales sin salir del entorno WooCommerce.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-credit-notes.php (AJAX `wbi_cn_save`, `wbi_cn_authorize`, `wbi_cn_cancel`, `wbi_cn_search_invoices`, `wbi_cn_copy_invoice_items`, submenu `wbi-credit-notes`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Gateway de pagos offline avanzados** (`Pagos Offline Avanzados`) — Integra gateway manual con cuentas bancarias, assets frontend y verificación de expiración por cron. **Valor de negocio:** Ordena cobros por transferencia en operaciones B2B.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-advanced-offline-payments.php (`wpoa_check_expired_orders`), modules/woo-pagos-offline-avanzados/woo-pagos-offline.php, modules/woo-pagos-offline-avanzados/includes/class-wpoa-payment-gateway.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Configuración de impuestos** (`Gestión de Impuestos`) — Expone pantalla administrativa para definir parámetros del módulo impositivo. **Valor de negocio:** Centraliza parámetros fiscales específicos del negocio.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-taxes.php (`admin_post` del grupo de settings, submenu `wbi-taxes`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Notificaciones automáticas por WhatsApp** (`WhatsApp`) — Expone configuración del módulo y automatiza mensajes operativos hacia clientes. **Valor de negocio:** Acerca estados del pedido al canal de mayor respuesta.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-whatsapp.php (submenu `wbi-whatsapp`, settings del módulo), wbi-suite.php carga condicional`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Centro unificado de notificaciones** (`Notificaciones`) — Centraliza alertas operativas en una pantalla con badge de admin. **Valor de negocio:** Evita dispersión de eventos entre módulos.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-notifications.php (submenu `wbi-notifications`, hooks de badge/menu), wbi-suite.php`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **API REST de dashboard, productos, clientes, ventas, facturas y notificaciones** (`API REST`) — Publica endpoints GET bajo `wbi/v1` para métricas, ranking, stock, facturas y notificaciones. **Valor de negocio:** Permite conectar apps externas y explotar datos sin tocar la base directamente.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-api.php (`register_rest_route` para `/dashboard`, `/products/*`, `/customers/*`, `/sales/*`, `/invoices`, `/notifications`; submenu `wbi-api`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Base de suscriptores, plantillas e importación de email marketing** (`Email Marketing`) — Administra templates, suscriptores e importaciones desde el panel de campañas. **Valor de negocio:** Ordena activos comerciales para campañas propias.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-email-marketing.php (dbDelta tablas `campaigns`, `subscribers`, `templates`; AJAX `wbi_email_import_subscribers`, `wbi_email_save_template`, submenu `wbi-email-marketing`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Ejecución y control de campañas de email** (`Email Marketing`) — Permite guardar campañas, testear envío, iniciar, pausar y procesar batches por cron. **Valor de negocio:** Convierte datos del e-commerce en campañas accionables.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-email-marketing.php (`wbi_email_send_batch`, AJAX `wbi_email_save_campaign`, `wbi_email_send_test`, `wbi_email_start_campaign`, `wbi_email_pause_campaign`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Origen de venta y taxonomía de colección** (`Modelo de Datos Extra`) — Agrega selector de origen en pedidos y registra taxonomía extra para productos. **Valor de negocio:** Permite clasificar operaciones con metadatos del negocio.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-data.php (`woocommerce_admin_order_data_after_order_details`, `woocommerce_process_shop_order_meta`, `register_taxonomy coleccion`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Campos personalizados en registro** (`Campos Personalizados`) — Agrega campos configurables al alta de usuarios con validación y guardado. **Valor de negocio:** Captura datos comerciales propios desde el primer contacto.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-custom-fields.php (`woocommerce_register_form`, `woocommerce_registration_errors`, `woocommerce_created_customer`, submenu `wbi-custom-fields`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.
- **Campos personalizados en checkout, admin y email** (`Campos Personalizados`) — Inserta campos configurables en checkout y los persiste en pedido, usuario, admin y emails. **Valor de negocio:** Evita planillas paralelas para datos específicos del negocio.
  - Estado: Activa visible
  - Evidencia: `includes/class-wbi-custom-fields.php (`woocommerce_checkout_fields`, `woocommerce_checkout_process`, `woocommerce_checkout_update_order_meta`, `woocommerce_admin_order_data_after_billing_address`, `woocommerce_email_after_order_table`)`
  - Commits/fuente reciente: Sin commit puntual destacado en el relevamiento; función validada por código actual.

## Novedades recientes verificadas para comunicar en la landing

| Tipo | Fecha | SHA | Novedad |
|---|---|---|---|
| Mejora | 2026-08-26 | `9fc9716` | Picking reforzado con QR scan, imágenes, auto-complete y auditoría de cambios. |
| Nuevo | 2026-08-10 | `08d868c` | QR de productos con panel admin, backfill y etiquetas imprimibles para POS/web. |
| Nuevo | 2026-08-10 | `d6fec9b` | POS con alta rápida de clientes y ajustes manuales de pedido. |
| Mejora | 2026-08-10 | `d3e9995` | POS con catálogo paginado y búsqueda unificada para cargar productos más rápido. |
| Mejora | 2026-08-10 | `9b659da` | Mínimo mayorista jerárquico por usuario, rol, lista y fallback global. |
| Nuevo | 2026-08-10 | `89a4896` | Registro segmentado para clientes mayoristas y minoristas. |
| Mejora | 2026-08-06 | `d05aeae` | Recuperación de carritos estabilizada con links más confiables. |
| Mejora | 2026-09-11 | `13b3052` | La landing empezó a comunicar hallazgos reales del ERP a partir del relevamiento funcional. |

## Archivo fuente adicional

- `docs/relevamiento-funcional.csv`: tabla maestra de las 55 funciones.
- `docs/relevamiento-funcional.md`: versión detallada del relevamiento técnico previo que sirvió de base para este resumen comercial.
