# Relevamiento funcional de WBI Suite

## Resumen ejecutivo

- **Módulos activables detectados en código:** 33
- **Funciones inventariadas en este relevamiento:** 55
- **Macromódulos usados para consolidación:** 7
- **Fuente del relevamiento:** código PHP/JS real, loader central, wrappers en `modules/`, pantallas admin, endpoints AJAX/REST y commits recientes del repositorio.
- **Criterio de status:** `Activa visible`, `Activa interna/técnica`, `Parcial/en desarrollo`. En esta revisión no se detectaron funciones claramente incompletas expuestas por feature flags o TODOs públicos.

## Metodología usada

1. Se tomó `wbi-suite.php` como punto de entrada para detectar módulos cargados, toggles, roles y superficies de configuración.
2. Se revisaron clases en `includes/` para validar menús, hooks, endpoints AJAX, cron jobs, tablas y formularios.
3. Se revisaron wrappers y plugins internos en `modules/` para confirmar integraciones reales.
4. Se revisó el historial de commits de los últimos 6 meses para detectar novedades funcionales comunicables.
5. Se eliminaron duplicados funcionales agrupando por objetivo de negocio y macro-módulo.

## Mapa de módulos

| Macro-módulo | Funciones inventariadas | Observación |
|---|---:|---|
| Administración / Configuración | 11 | Incluye maestros, settings y backoffice transversal |
| Estadísticas / BI | 4 | Incluye dashboard, scoring y lectura financiera |
| Gestión de Stock | 7 | Cubre depósito, trazabilidad y abastecimiento |
| Integraciones | 8 | Integra logística, mensajería, API y marketing |
| Operación B2B / Ventas | 13 | Con foco en checkout, ventas asistidas y clientes mayoristas |
| Reportes / Exportaciones | 7 | Combina salidas analíticas y documentación comercial |
| Seguridad / Usuarios / Permisos | 5 | Incluye roles, acceso y validaciones operativas |

## Tabla completa de funcionalidades

| Función | Macro-módulo | Módulo | Tipo | Estado | Usuario objetivo | Descripción funcional | Beneficio de negocio | Evidencia técnica | Commits recientes |
|---|---|---|---|---|---|---|---|---|---|
| Activación y bloqueo por licencia | Seguridad / Usuarios / Permisos | Licencia central | seguridad | Activa interna/técnica | admin | Sin licencia activa el loader no inicializa módulos funcionales y sólo expone la pantalla de activación. | Protege el esquema comercial y evita uso parcial inconsistente de la suite. | wbi-suite.php (WBI_Suite_Loader::load_modules), includes/class-wbi-license.php, menú admin page `wbi-license` | — |
| Configuración centralizada de módulos | Administración / Configuración | Configuración central | configuración | Activa visible | admin | Permite activar o desactivar 33 módulos mediante la option `wbi_modules_settings` desde WooCommerce → wooErp Config. | Reduce carga técnica al instalar sólo lo necesario en cada operación. | wbi-suite.php (`get_module_toggle_keys`, `register_settings`, `render_settings_page`), submenu `wbi-settings` | — |
| Recuperación defensiva de settings corruptos | Administración / Configuración | Configuración central | configuración | Activa interna/técnica | admin | Reconstruye o restaura el estado de activación de módulos si la option principal queda dañada. | Evita caídas operativas por corrupción de configuración. | wbi-suite.php (`maybe_recover_corrupted_module_states`, backup option/transient) | — |
| Superadmin WBI y permisos por usuario | Seguridad / Usuarios / Permisos | Permisos WBI | seguridad | Activa visible | admin | Filtra menús por permisos WBI, transfiere superadmin y restringe vistas para roles de caja. | Permite delegar funciones sin exponer todo el backoffice. | wbi-suite.php (`filter_wbi_menus_by_permission`, `restrict_pos_role_menus`, `handle_wbi_superadmin_actions`, `get_wbi_module_list`) | — |
| Roles operativos dedicados | Seguridad / Usuarios / Permisos | Permisos WBI | seguridad | Activa interna/técnica | admin | Crea roles `wholesale_customer`, `wbi_armador`, `wbi_cashier` y `wbi_vendedor` con capacidades específicas. | Alinea permisos con procesos de depósito, venta y mayorista. | wbi-suite.php (`ensure_wbi_roles` y `add_role(...)`) | — |
| Registro WooCommerce segmentado | Seguridad / Usuarios / Permisos | Registro WooCommerce | configuración | Activa visible | cliente B2B / cliente minorista / admin | Agrega campos al registro y permite asignar roles distintos según tipo de cliente y reglas de alta. | Ordena el onboarding comercial sin procesos manuales fuera del sitio. | includes/class-wbi-registration-fields.php (`woocommerce_register_form`, `woocommerce_created_customer`); wbi-suite.php settings de registro | 89a4896 / c816927 / e0586b2 (agosto 2026) |
| Aprobación manual o automática de mayoristas | Operación B2B / Ventas | Modo Mayorista B2B | seguridad | Activa visible | admin comercial | Gestiona el alta de clientes mayoristas y su aprobación desde el admin. | Controla quién accede a condiciones mayoristas reales. | includes/class-wbi-b2b.php (acciones de usuario/rol mayorista, profile hooks), wbi-suite.php flags `wbi_enable_b2b`, `wbi_b2b_auto_approve` | — |
| Ocultamiento de precios y compra para no autorizados | Operación B2B / Ventas | Modo Mayorista B2B | seguridad | Activa visible | cliente B2B / visitante no autorizado | Aplica reglas para ocultar precios o impedir compra a usuarios fuera de los roles permitidos. | Protege listas mayoristas y evita ventas fuera de política. | includes/class-wbi-b2b.php; wbi-suite.php migración de flags `wbi_b2b_enable_hide_prices` y autorizaciones B2B | — |
| Monto mínimo mayorista jerárquico | Operación B2B / Ventas | Modo Mayorista B2B | configuración | Activa visible | admin comercial / cliente mayorista | Resuelve el mínimo por override de usuario, rol, lista de precios o fallback global antes de permitir el pedido. | Formaliza políticas comerciales por segmento sin duplicar lógica. | includes/class-wbi-minimum-order-resolver.php; includes/class-wbi-b2b.php; includes/class-wbi-pricelists.php | 9b659da / f93aefe (agosto 2026) |
| Listas de precios por cliente, rol o grupo | Operación B2B / Ventas | Listas de Precios | CRUD | Activa visible | admin comercial | Administra listas de precios y sus asignaciones desde una pantalla propia del backoffice. | Permite segmentar precios sin replicar catálogos. | includes/class-wbi-pricelists.php (`admin_post_wbi_save_pricelist`, `admin_post_wbi_delete_pricelist`, submenu `wbi-pricelists`) | 9b659da (amplía mínimo por lista de precios) |
| Pedido rápido mayorista desde catálogo | Operación B2B / Ventas | Pedido rápido mayorista público | automatización | Activa visible | cliente B2B | Habilita selección de variantes, cantidad y agregado rápido desde el loop de productos. | Reduce fricción en pedidos grandes y acelera reposiciones. | includes/class-wbi-public-wholesale-quick-order.php (`wp_enqueue_scripts`), assets/js/wbi-public-wholesale-quick-order.js, modules/public-wholesale-quick-order/public-wholesale-quick-order.php | 717551b (agosto 2026) |
| Precio financiado y descuento por transferencia | Operación B2B / Ventas | Precio Promo | configuración | Activa visible | admin comercial / cliente | Muestra precio promocional y recalcula el checkout según forma de pago. | Mejora conversión y comunica incentivos de cobro. | includes/class-wbi-promo-pricing.php; modules/woo-precio-promo/includes/class-checkout-fee.php; modules/woo-precio-promo/assets/js/checkout-refresh.js | — |
| Costo por producto y cálculo de márgenes | Operación B2B / Ventas | Costos y Márgenes | configuración | Activa visible | admin comercial / finanzas | Agrega campos de costo y umbral de alerta para analizar margen bruto. | Da visibilidad de rentabilidad por producto. | includes/class-wbi-costs.php (hooks sobre producto y option `wbi_margin_alert_threshold`), submenu `wbi-costs` | — |
| Captura de contacto para carritos abandonados | Operación B2B / Ventas | Carritos Abandonados | automatización | Activa visible | cliente / marketing / ventas | Captura y actualiza email/teléfono de carritos con endpoints públicos autenticados por nonce. | Genera base accionable para recuperar ventas. | includes/class-wbi-abandoned-carts.php (`wp_ajax_nopriv_wbi_capture_cart_contact`, `wp_ajax_nopriv_wbi_update_cart_data`), assets/js/wbi-abandoned-carts.js | — |
| Recordatorios y recuperación de carritos | Operación B2B / Ventas | Carritos Abandonados | automatización | Activa visible | ventas / marketing | Programa tareas para marcar carritos abandonados, enviar recordatorios y limpiar expirados, con acciones manuales y masivas desde admin. | Automatiza recuperación de ingresos sin seguimiento manual caso por caso. | includes/class-wbi-abandoned-carts.php (`wbi_mark_abandoned_carts`, `wbi_send_cart_reminders`, `wbi_cleanup_expired_carts`, AJAX `wbi_send_manual_reminder`, `wbi_bulk_send_reminders`) | d05aeae / fb64786 (agosto 2026) |
| Validación CP vs provincia en checkout | Seguridad / Usuarios / Permisos | Validación de Checkout | seguridad | Activa visible | cliente / operador | Valida coherencia entre código postal y provincia durante la compra. | Reduce errores de despacho y retrabajo logístico. | includes/class-wbi-checkout-validator.php; loader en wbi-suite.php | — |
| Pipeline de leads y etapas CRM | Operación B2B / Ventas | CRM / Pipeline de Ventas | CRUD | Activa visible | ventas | Crea, mueve y reordena leads por etapas en un pipeline propio. | Da seguimiento comercial estructurado a oportunidades. | includes/class-wbi-crm.php (dbDelta tablas `wbi_crm_*`, AJAX `wbi_crm_move_lead`, `wbi_crm_reorder_stages`, submenu `wbi-crm`) | — |
| Actividades CRM y conversión a cliente | Operación B2B / Ventas | CRM / Pipeline de Ventas | automatización | Activa visible | ventas | Registra actividades, marca leads como ganados/perdidos y convierte oportunidades a cliente. | Acorta el pasaje de prospecto a operación real en WooCommerce. | includes/class-wbi-crm.php (AJAX `wbi_crm_add_activity`, `wbi_crm_complete_activity`, `wbi_crm_convert_customer`, `wbi_crm_mark_won`, `wbi_crm_mark_lost`) | — |
| Dashboard ejecutivo de KPIs | Estadísticas / BI | Dashboard BI Suite | reporte | Activa visible | gerencia | Expone tablero principal con métricas y accesos a análisis detallados. | Centraliza visibilidad operativa y comercial. | includes/class-wbi-dashboard.php (menu `wbi-dashboard-view`), includes/class-wbi-metrics.php, wbi-suite.php carga del dashboard | — |
| Reporte detallado de ventas | Reportes / Exportaciones | Reporte de ventas | reporte | Activa visible | gerencia comercial | Muestra análisis detallado de ventas como subpantalla del dashboard. | Facilita lectura por período y desempeño comercial. | includes/class-wbi-report-sales.php (submenu `wbi-sales-report`), includes/class-wbi-export.php | — |
| Análisis de clientes | Reportes / Exportaciones | Reporte de clientes | reporte | Activa visible | ventas / gerencia | Expone ranking y detalle analítico de clientes como vista específica del dashboard. | Ayuda a priorizar cuentas y segmentos de alto valor. | includes/class-wbi-report-clients.php (submenu `wbi-clients-report`), includes/class-wbi-metrics.php | — |
| Análisis de productos y stock | Reportes / Exportaciones | Reporte de productos | reporte | Activa visible | stock / gerencia | Expone vista detallada de productos y stock dentro del bloque BI. | Permite detectar rotación y productos críticos. | includes/class-wbi-report-products.php (submenu `wbi-products-report`), includes/class-wbi-metrics.php | — |
| Alertas de stock | Gestión de Stock | Alertas de stock | reporte | Activa visible | stock / compras | Lista alertas de productos críticos y permite exportarlas desde admin. | Disminuye quiebres de stock. | includes/class-wbi-stock-alerts.php (`admin_post_wbi_stock_export`, submenu `wbi-stock-alerts`) | — |
| Exportaciones CSV del stack BI | Reportes / Exportaciones | Exportación CSV | reporte | Activa interna/técnica | gerencia / analista | Centraliza endpoints de exportación CSV para reportes de ventas, clientes y productos. | Permite reutilizar información fuera del admin. | includes/class-wbi-export.php (`admin_post_wbi_export_sales`, `admin_post_wbi_export_clients`, `admin_post_wbi_export_products`) | — |
| Reportes periódicos por email | Reportes / Exportaciones | Email Reports | automatización | Activa interna/técnica | admin / gerencia | Programa el envío periódico de reportes y permite prueba manual por AJAX. | Lleva KPIs a quienes no ingresan todos los días al backoffice. | includes/class-wbi-email-reports.php (`wbi_send_scheduled_report`, AJAX `wbi_send_test_email`) | — |
| Scoring RFM diario de clientes | Estadísticas / BI | Scoring de Clientes | automatización | Activa visible | ventas / marketing | Calcula scoring de clientes y ofrece recálculo y exportación desde el admin. | Facilita segmentación comercial basada en comportamiento. | includes/class-wbi-scoring.php (`wbi_scoring_daily`, AJAX `wbi_scoring_recalc`, `admin_post_wbi_scoring_export`, submenu `wbi-scoring`) | — |
| Reportes contables | Estadísticas / BI | Reportes Contables | reporte | Activa visible | contabilidad / dirección | Carga reportes contables y exporta CSV desde una pantalla propia. | Une operación WooCommerce con lectura financiera. | includes/class-wbi-accounting-reports.php (AJAX `wbi_accrep_load_report`, `wbi_accrep_export_csv`, submenu `wbi-accounting-reports`) | — |
| Asignación e importación de códigos de barra | Gestión de Stock | Códigos de Barra | configuración | Activa visible | stock / catálogo | Permite lookup, asignación e importación de códigos EAN/UPC para productos. | Prepara al catálogo para procesos de depósito y escaneo. | includes/class-wbi-barcode.php (AJAX `wbi_barcode_lookup`, `wbi_barcode_assign`, `wbi_barcode_import`, submenu `wbi-barcode`) | — |
| QR únicos por producto y etiquetas imprimibles | Gestión de Stock | QR de Productos | configuración | Activa visible | stock / POS / operaciones | Genera tokens QR, resuelve escaneo para POS/web y administra regeneración y backfill. | Acelera toma de pedido y operaciones de escaneo. | includes/class-wbi-product-qr.php (AJAX `wbi_qr_pos_resolve`, `wbi_qr_regenerate`, `admin_post_wbi_qr_backfill`, submenu `wbi-product-qr`), assets/js/wbi-qr-admin.js | 08d868c / f844c0d / 8677405 (agosto 2026) |
| Picking con inicio, escaneo y cierre por pedido | Gestión de Stock | Picking & Armado | CRUD | Activa visible | armador / supervisor | Inicia picking, escanea ítems y completa pedidos desde panel dedicado. | Reduce errores de preparación en depósito. | includes/class-wbi-picking.php (AJAX `wbi_picking_start`, `wbi_picking_scan`, `wbi_picking_complete`, menús `wbi-picking` y `wbi-picking-panel`) | 9fc9716 / 5b57eac (agosto 2026) |
| Edición manual y auditoría dentro del picking | Gestión de Stock | Picking & Armado | CRUD | Activa visible | armador / supervisor | Permite marcar ítems, editar cantidades, remover/agregar líneas y guardar notas del pedido. | Hace flexible la preparación sin salir del flujo operativo. | includes/class-wbi-picking.php (AJAX `wbi_picking_mark_item`, `wbi_picking_edit_qty`, `wbi_picking_remove_item`, `wbi_picking_add_item`, `wbi_picking_order_notes`) | 9fc9716 (agosto 2026) |
| Tarifas de transportistas argentinos | Integraciones | MobApp Envíos | integración | Activa visible | cliente / logística | Expone métodos de envío y tarifas para Andreani, Correo Argentino, OCA, Urbano y Flash. | Mejora cotización logística dentro del checkout. | includes/class-wbi-mobapp-shipping.php; modules/mobapp-envios/main.php (`woocommerce_shipping_init`, `woocommerce_shipping_methods`) | — |
| Selección de transportista o micro | Integraciones | Transporte Multiopciones | integración | Activa visible | cliente | Permite elegir transportista personalizado y guarda la selección en sesión, pedido, email y detalle. | Adapta el checkout a logística B2B no estandarizada. | includes/class-wbi-multi-shipping.php; modules/transporte-multiopciones/MOBAPP-LOGISTICA-INTELIGENTE-TRANSPORTES-Y-MICROS-PERSONALIZADO.php (AJAX `mobapp_save_carrier` y hooks WooCommerce) | — |
| Remitos PDF por pedido | Reportes / Exportaciones | Documentos / Remitos | CRUD | Activa visible | operaciones / administración | Genera remitos desde el pedido y los vincula al módulo unificado de documentos. | Formaliza despacho y respaldo documental. | includes/class-wbi-remitos.php (AJAX `wbi_generate_remito`, admin-post handlers), includes/class-wbi-documents.php, submenu `wbi-documents` | — |
| Maestro de proveedores | Administración / Configuración | Proveedores | CRUD | Activa visible | compras | Registra proveedores como post type propio y los vincula con productos. | Ordena abastecimiento y relación catálogo-proveedor. | includes/class-wbi-suppliers.php (`register_post_type wbi_supplier`, submenu `wbi-suppliers`, `wbi-supplier-products`) | — |
| Reglas automáticas de reabastecimiento | Gestión de Stock | Reglas de Reabastecimiento | automatización | Activa visible | compras / stock | Permite crear reglas, activarlas/desactivarlas, ejecutarlas manualmente o por cron y disparar compras. | Reduce faltantes por monitoreo manual. | includes/class-wbi-reorder.php (`wbi_reorder_check`, AJAX `wbi_reorder_run_now`, `wbi_reorder_save_rule`, `wbi_reorder_toggle_active`, submenu `wbi-reorder`) | — |
| Órdenes de compra y recepción de mercadería | Gestión de Stock | Órdenes de Compra | CRUD | Activa visible | compras / stock | Guarda órdenes de compra, cambia estados, busca productos y registra recepción. | Cierra el circuito de abastecimiento dentro del plugin. | includes/class-wbi-purchase.php (dbDelta tablas de órdenes/ítems/recepciones, AJAX `wbi_purchase_save`, `wbi_purchase_receive`, `wbi_purchase_update_status`, submenu `wbi-purchase`) | — |
| Gestión integral de empleados y RRHH | Administración / Configuración | Empleados / RRHH | CRUD | Activa visible | RRHH / admin | Crea tablas y pantallas para empleados, departamentos, contratos, skills, ubicaciones y plantillas. | Amplía la suite a procesos internos no comerciales. | includes/class-wbi-employees.php (dbDelta tablas `wbi_departments`, `wbi_employees`, `wbi_employee_contracts`, submenu `wbi-employees`) | — |
| POS con búsqueda y carga de productos | Operación B2B / Ventas | POS / Mostrador | CRUD | Activa visible | cajero / vendedor | Expone la interfaz de mostrador y el endpoint de búsqueda de productos para armar pedidos. | Acelera ventas presenciales o asistidas. | includes/class-wbi-pos.php (menús `wbi-pos`, AJAX `wbi_pos_search_products`, `wbi_pos_create_order`), assets/pos.js, assets/pos.css | d3e9995 / 97ea980 (agosto 2026) |
| Alta rápida de clientes y ajustes en POS | Operación B2B / Ventas | POS / Mostrador | CRUD | Activa visible | cajero / vendedor | Permite buscar clientes ampliados, crear clientes inline y aplicar descuentos/recargos/envío/impuesto manual. | Evita abandonar la operación para resolver excepciones comerciales. | includes/class-wbi-pos.php (AJAX `wbi_pos_search_customers`, `wbi_pos_create_customer` y lógica de adjustments), assets/pos.js | d6fec9b / 0e0869c / 2c866d3 (agosto 2026) |
| Caja POS: apertura, cierre, sesiones y movimientos | Administración / Configuración | POS Caja | CRUD | Activa visible | cajero / admin | Registra estado de caja, movimientos y exportación de sesiones por CSV. | Aporta trazabilidad de caja y control de arqueo. | includes/class-wbi-pos.php (AJAX `wbi_pos_open_cash`, `wbi_pos_close_cash`, `wbi_pos_add_movement`, `wbi_pos_get_movements`), includes/class-wbi-pos-cash-admin.php, includes/class-wbi-pos-cash-sessions.php, includes/class-wbi-pos-cash-movements.php | — |
| Facturación AFIP y configuración fiscal | Administración / Configuración | Facturación AFIP | configuración | Activa visible | administración / contabilidad | Configura datos fiscales y expone acciones administrativas del módulo de facturación. | Acerca el circuito fiscal al mismo backoffice operativo. | includes/class-wbi-invoice.php (`admin_post_wbi_save_invoice_settings`, `admin_post_wbi_generate_invoice`, `admin_post_wbi_delete_invoice`, submenu asociado a documentos) | — |
| Documentos comerciales unificados | Reportes / Exportaciones | Documentos | CRUD | Activa visible | administración / operaciones | Unifica pantalla y handlers para factura y remito cuando alguno de ambos módulos está activo. | Concentra documentación comercial del pedido. | wbi-suite.php (carga condicional de `includes/class-wbi-documents.php`), includes/class-wbi-documents.php, submenu `wbi-documents` | — |
| Notas de crédito y débito | Administración / Configuración | Notas de Crédito / Débito | CRUD | Activa visible | contabilidad | Permite guardar, autorizar, cancelar y copiar ítems de facturas para NC/ND. | Completa correcciones fiscales sin salir del entorno WooCommerce. | includes/class-wbi-credit-notes.php (AJAX `wbi_cn_save`, `wbi_cn_authorize`, `wbi_cn_cancel`, `wbi_cn_search_invoices`, `wbi_cn_copy_invoice_items`, submenu `wbi-credit-notes`) | — |
| Gateway de pagos offline avanzados | Integraciones | Pagos Offline Avanzados | integración | Activa visible | cliente / administración | Integra gateway manual con cuentas bancarias, assets frontend y verificación de expiración por cron. | Ordena cobros por transferencia en operaciones B2B. | includes/class-wbi-advanced-offline-payments.php (`wpoa_check_expired_orders`), modules/woo-pagos-offline-avanzados/woo-pagos-offline.php, modules/woo-pagos-offline-avanzados/includes/class-wpoa-payment-gateway.php | — |
| Configuración de impuestos | Administración / Configuración | Gestión de Impuestos | configuración | Activa visible | contabilidad / admin | Expone pantalla administrativa para definir parámetros del módulo impositivo. | Centraliza parámetros fiscales específicos del negocio. | includes/class-wbi-taxes.php (`admin_post` del grupo de settings, submenu `wbi-taxes`) | — |
| Proyección de flujo de caja | Estadísticas / BI | Flujo de Caja | reporte | Activa visible | dirección / finanzas | Expone reporte financiero específico desde una pantalla propia. | Ayuda a anticipar liquidez operativa. | includes/class-wbi-cashflow.php (submenu `wbi-cashflow` y handlers de configuración/exportación) | — |
| Notificaciones automáticas por WhatsApp | Integraciones | WhatsApp | integración | Activa visible | cliente / ventas | Expone configuración del módulo y automatiza mensajes operativos hacia clientes. | Acerca estados del pedido al canal de mayor respuesta. | includes/class-wbi-whatsapp.php (submenu `wbi-whatsapp`, settings del módulo), wbi-suite.php carga condicional | — |
| Centro unificado de notificaciones | Integraciones | Notificaciones | integración | Activa visible | admin / gerencia | Centraliza alertas operativas en una pantalla con badge de admin. | Evita dispersión de eventos entre módulos. | includes/class-wbi-notifications.php (submenu `wbi-notifications`, hooks de badge/menu), wbi-suite.php | — |
| API REST de dashboard, productos, clientes, ventas, facturas y notificaciones | Integraciones | API REST | integración | Activa visible | integrador / app externa | Publica endpoints GET bajo `wbi/v1` para métricas, ranking, stock, facturas y notificaciones. | Permite conectar apps externas y explotar datos sin tocar la base directamente. | includes/class-wbi-api.php (`register_rest_route` para `/dashboard`, `/products/*`, `/customers/*`, `/sales/*`, `/invoices`, `/notifications`; submenu `wbi-api`) | — |
| Base de suscriptores, plantillas e importación de email marketing | Integraciones | Email Marketing | CRUD | Activa visible | marketing | Administra templates, suscriptores e importaciones desde el panel de campañas. | Ordena activos comerciales para campañas propias. | includes/class-wbi-email-marketing.php (dbDelta tablas `campaigns`, `subscribers`, `templates`; AJAX `wbi_email_import_subscribers`, `wbi_email_save_template`, submenu `wbi-email-marketing`) | — |
| Ejecución y control de campañas de email | Integraciones | Email Marketing | automatización | Activa visible | marketing / ventas | Permite guardar campañas, testear envío, iniciar, pausar y procesar batches por cron. | Convierte datos del e-commerce en campañas accionables. | includes/class-wbi-email-marketing.php (`wbi_email_send_batch`, AJAX `wbi_email_save_campaign`, `wbi_email_send_test`, `wbi_email_start_campaign`, `wbi_email_pause_campaign`) | — |
| Origen de venta y taxonomía de colección | Administración / Configuración | Modelo de Datos Extra | configuración | Activa visible | admin / catálogo | Agrega selector de origen en pedidos y registra taxonomía extra para productos. | Permite clasificar operaciones con metadatos del negocio. | includes/class-wbi-data.php (`woocommerce_admin_order_data_after_order_details`, `woocommerce_process_shop_order_meta`, `register_taxonomy coleccion`) | — |
| Campos personalizados en registro | Administración / Configuración | Campos Personalizados | configuración | Activa visible | admin / cliente | Agrega campos configurables al alta de usuarios con validación y guardado. | Captura datos comerciales propios desde el primer contacto. | includes/class-wbi-custom-fields.php (`woocommerce_register_form`, `woocommerce_registration_errors`, `woocommerce_created_customer`, submenu `wbi-custom-fields`) | — |
| Campos personalizados en checkout, admin y email | Administración / Configuración | Campos Personalizados | configuración | Activa visible | admin / cliente / operaciones | Inserta campos configurables en checkout y los persiste en pedido, usuario, admin y emails. | Evita planillas paralelas para datos específicos del negocio. | includes/class-wbi-custom-fields.php (`woocommerce_checkout_fields`, `woocommerce_checkout_process`, `woocommerce_checkout_update_order_meta`, `woocommerce_admin_order_data_after_billing_address`, `woocommerce_email_after_order_table`) | — |

## Consolidación y clasificación

### Status detectados

| Status | Cantidad |
|---|---:|
| Activa interna/técnica | 5 |
| Activa visible | 50 |

### Tipos funcionales predominantes

| Tipo | Cantidad |
|---|---:|
| CRUD | 14 |
| automatización | 8 |
| configuración | 13 |
| integración | 6 |
| reporte | 8 |
| seguridad | 6 |

### Módulos con más superficie funcional relevada

| Módulo | Funciones inventariadas |
|---|---:|
| Modo Mayorista B2B | 3 |
| Configuración central | 2 |
| Permisos WBI | 2 |
| Carritos Abandonados | 2 |
| CRM / Pipeline de Ventas | 2 |
| Picking & Armado | 2 |
| POS / Mostrador | 2 |
| Email Marketing | 2 |
| Campos Personalizados | 2 |
| Licencia central | 1 |
| Registro WooCommerce | 1 |
| Listas de Precios | 1 |

## Novedades detectadas por commits recientes

| Fecha | SHA | Novedad funcional detectada | Evidencia técnica principal |
|---|---|---|---|
| 2026-08-26 | `9fc9716` | Picking: QR scan, imágenes de producto, auto-complete y edición/remoción/agregado con auditoría | includes/class-wbi-picking.php |
| 2026-08-10 | `08d868c` | QR de Productos: tokens, panel admin, etiquetas imprimibles e integración con POS | includes/class-wbi-product-qr.php; assets/js/wbi-qr-admin.js; assets/pos.js |
| 2026-08-10 | `d6fec9b` | POS: identificación de clientes, alta rápida inline y ajustes de pedido | includes/class-wbi-pos.php; assets/pos.js; assets/pos.css |
| 2026-08-10 | `d3e9995` | POS: dropdown de catálogo paginado con scroll infinito y endpoint unificado de búsqueda | includes/class-wbi-pos.php; assets/pos.js; assets/pos.css |
| 2026-08-10 | `9b659da` | B2B: mínimo de pedido jerárquico con prioridad por usuario/rol/lista de precios | includes/class-wbi-minimum-order-resolver.php; includes/class-wbi-b2b.php; includes/class-wbi-pricelists.php; wbi-suite.php |
| 2026-08-10 | `89a4896` | Registro WooCommerce segmentado con mapeo configurable mayorista/minorista | includes/class-wbi-registration-fields.php; wbi-suite.php; includes/class-wbi-b2b.php |
| 2026-08-06 | `d05aeae` | Estabilización de links de recuperación de carritos abandonados | includes/class-wbi-abandoned-carts.php |
| 2026-09-11 | `13b3052` | Landing comercial actualizada para comunicar funcionalidades reales y enfoque B2B | landing.html |

## Gaps detectados

- El marketing visible del repositorio venía comunicando 31 módulos, pero el loader actual detecta 33 toggles funcionales en `wbi-suite.php` (`get_module_toggle_keys`).
- La landing principal no resumía explícitamente módulos de seguridad/permisos, API REST, email marketing, impuestos, campos personalizados ni RRHH, aunque sí existen en código.
- Las novedades de landing mencionaban features recientes, pero sin relacionarlas de forma explícita con SHAs o ventanas temporales del historial.

## Notas de alcance

- Este inventario se apoya en superficies verificables del repositorio: clases cargadas por el loader, menús admin, hooks WooCommerce/WordPress, endpoints AJAX/REST, cron jobs y wrappers incluidos en `modules/`.
- Cuando una función comparte infraestructura con otra (por ejemplo exportaciones CSV o documentos unificados), se consolidó como función separada sólo si el beneficio de negocio o la evidencia técnica eran distinguibles.
- El archivo `docs/relevamiento-funcional.csv` contiene el mismo inventario en formato tabular para uso comercial y filtrado.
