# wooErp — Suite de Gestión para WooCommerce (WBI Suite)

> **Plugin Name:** wooErp — Suite de Gestión para WooCommerce  
> **Versión actual:** 9.0.35  
> **Autor:** Rodrigo Castañera  
> **Compatibilidad:** WordPress + WooCommerce  

---

## ¿Qué es wooErp?

**wooErp** (WBI Suite) convierte tu instalación de WooCommerce en un ERP completo nativo. Sin servidores externos, sin integraciones que se rompen, sin consultoras. Se instala como un plugin de WordPress estándar y expone **31 módulos activables de forma independiente**, organizados en 7 categorías funcionales.

La suite está orientada a tiendas que necesitan operar seriamente: mayoristas B2B, depósitos con picking, facturación fiscal, gestión financiera, y análisis de inteligencia de negocio — todo dentro del mismo WordPress.

---

## Instalación y activación

### Requisitos

- WordPress 5.6 o superior
- WooCommerce 5.0 o superior
- PHP 7.4 o superior

### Instalación

1. Subí la carpeta del plugin a `wp-content/plugins/`.
2. Activá el plugin desde **Plugins → Plugins instalados**.
3. Aparecerá un ítem **wooErp** bajo el menú de WooCommerce en el admin.
4. Ingresá tu clave de licencia para activar los módulos.

### Activación de licencia

Sin licencia activa, **ningún módulo funciona**. El plugin muestra únicamente la pantalla de activación de licencia. Una vez ingresada la clave válida, todos los módulos configurados quedan disponibles.

La licencia se gestiona desde **WooCommerce → wooErp → Configuración** (pestaña de licencia).

### Activación por módulo

Desde **WooCommerce → wooErp → Configuración**, cada módulo tiene su propio toggle. Activá solo los que necesitás — el resto no carga código.

El módulo de **Campos de Registro** (provincia, localidad, teléfono en el registro de clientes) se carga siempre, independientemente de la configuración.

---

## Módulos disponibles

Los 31 módulos se agrupan en 7 categorías:

---

### 🏢 Comercial

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **Modo Mayorista B2B** | `wbi_enable_b2b` | Roles mayoristas, precios ocultos al público general y aprobación manual de nuevos clientes con acceso restringido por rol. Incluye opción de **auto-aprobación** (`wbi_b2b_auto_approve`) para que los clientes queden habilitados automáticamente al registrarse, sin intervención manual. |
| **Listas de Precios** | `wbi_enable_pricelists` | Precios diferenciados por cliente, rol o grupo — ideal para distribuidores, revendedores y clientes VIP. |
| **Precio Promo** | `wbi_enable_promo_pricing` | Precio financiado, precio con descuento por transferencia bancaria y ajuste automático en el checkout según el método de pago seleccionado. |
| **Costos y Márgenes** | `wbi_enable_costs` | Costo de adquisición por producto con cálculo automático de margen bruto y análisis de rentabilidad. |
| **Carritos Abandonados** | `wbi_enable_abandoned_carts` | Recuperación de ventas perdidas con seguimiento automático por email y WhatsApp. |
| **Validación de Checkout** | `wbi_enable_checkout_validator` | Valida que el código postal ingresado coincida con la provincia seleccionada — reduce errores de envío en Argentina. |
| **CRM / Pipeline de Ventas** | `wbi_enable_crm` | Pipeline Kanban con leads, actividades, forecast, scoring y conversión directa a clientes WooCommerce. |

---

### 📊 Inteligencia de Negocio

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **Dashboard BI Suite** | `wbi_enable_dashboard` | Dashboard ejecutivo con KPIs en tiempo real: ventas, ticket promedio, conversión, productos top y alertas de stock. Incluye reportes de ventas, clientes y productos, exportación CSV y alertas de stock. |
| **Scoring de Clientes (RFM)** | `wbi_enable_scoring` | Scoring automático diario por Recencia, Frecuencia y Monto (RFM) — segmentá tu base de clientes sin esfuerzo manual. |
| **Reportes Contables** | `wbi_enable_accounting_reports` | Libro IVA, Estado de Resultados, Posición IVA y análisis de rentabilidad por período. |

---

### 📦 Operaciones

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **Códigos de Barra** | `wbi_enable_barcode` | Gestión de códigos EAN/UPC por producto con generación e impresión de etiquetas. |
| **Picking & Armado** | `wbi_enable_picking` | Flujo completo de preparación de pedidos con escaneo de códigos de barra — ideal para depósitos con volumen. |
| **MobApp Envíos** | `wbi_enable_mobapp_shipping` | Método de envío WooCommerce con tarifas para los principales transportistas argentinos: Andreani, Correo Argentino, OCA, Urbano y Flash. Integra el motor de tarifas de la app MobApp Envíos. |
| **Transporte Multiopciones** | `wbi_enable_multi_shipping` | Método de envío con selección de transportista o servicio de micro/encomienda personalizado. Permite al cliente elegir entre múltiples opciones de transporte en el checkout. |
| **Remitos** | `wbi_enable_remitos` | Generación de remitos PDF vinculados a pedidos WooCommerce con numeración correlativa automática. |
| **Proveedores** | `wbi_enable_suppliers` | Gestión de proveedores con vinculación directa a productos del catálogo y registro de condiciones comerciales. |
| **Reglas de Reabastecimiento** | `wbi_enable_reorder` | Punto de reorden automático con generación de órdenes de compra cuando el stock cae por debajo del mínimo configurado. |
| **Órdenes de Compra** | `wbi_enable_purchase` | Gestión completa de órdenes de compra: creación, aprobación, recepción de mercadería y actualización automática de stock. |
| **Empleados / RRHH** | `wbi_enable_employees` | Gestión integral de empleados, departamentos, contratos, habilidades, horarios de trabajo y reclutamiento. Inspirado en el módulo HR de Odoo, adaptado a WordPress. |
| **POS / Mostrador** | `wbi_enable_pos` | Tomador de pedidos en caja con pagos mixtos (efectivo, tarjeta, transferencia, cuenta corriente), apertura/cierre de caja por cajero/turno, movimientos (ingresos, egresos, retiros, depósitos), arqueo de efectivo, sesiones con diferencia registrada y exportación CSV. Facturación AFIP integrada. Permisos: admin ve todo, cajero solo su caja. |

---

### 💰 Finanzas

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **Facturación AFIP** | `wbi_enable_invoice` | Facturación tipo A/B/C con formato fiscal argentino AFIP — nativa, sin módulos adicionales. |
| **Notas de Crédito/Débito** | `wbi_enable_credit_notes` | Emisión de notas de crédito y débito (NC/ND) vinculadas a facturas AFIP tipo A/B/C. |
| **Pagos Offline Avanzados** | `wbi_enable_offline_payments` | Gateway de pago manual configurable con múltiples cuentas bancarias variables, vencimiento automático de órdenes impagadas y notificación por WhatsApp al confirmar la transferencia. Basado en el plugin `woo-pagos-offline-avanzados`. |
| **Gestión de Impuestos** | `wbi_enable_taxes` | Cálculo de IVA, percepciones e impuestos internos. Configuración avanzada de tasas y reglas impositivas. |
| **Flujo de Caja** | `wbi_enable_cashflow` | Proyección de flujo de caja y análisis financiero con vista por período. |

---

### 🔗 Integraciones

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **WhatsApp** | `wbi_enable_whatsapp` | Notificaciones automáticas al cliente por WhatsApp ante cambios de estado de pedido, envío y entrega. |
| **Notificaciones** | `wbi_enable_notifications` | Centro de alertas unificado con badge en el admin de WordPress, clasificación por prioridad y lectura masiva. |
| **API REST** | `wbi_enable_api` | Endpoints REST para integrar con apps externas, ERPs de terceros o aplicaciones móviles propias. |
| **Email Marketing** | `wbi_enable_email_marketing` | Campañas masivas de email: diseño de templates, gestión de suscriptores y métricas de apertura y clic. |

---

### 📁 Datos

| Módulo | Clave de activación | Descripción |
|--------|---------------------|-------------|
| **Modelo de Datos Extra** | `wbi_enable_data` | Campos adicionales para pedidos y clientes: origen de venta, taxonomías personalizadas y atributos propios del negocio. |
| **Campos Personalizados** | `wbi_enable_custom_fields` | Campos custom en registro y checkout con validación de formato, prevención de duplicados y configuración desde el admin. |

---

### ✅ Siempre activo

| Módulo | Descripción |
|--------|-------------|
| **Campos de Registro** | Agrega campos de provincia, localidad y teléfono al formulario de registro de clientes. Se carga siempre, sin necesidad de activación. |

---

## Resumen funcional por área

| Área | Módulos clave |
|------|--------------|
| **B2B / Mayoristas** | Modo Mayorista B2B, Listas de Precios, CRM, Scoring RFM |
| **Precios y Descuentos** | Precio Promo, Listas de Precios, Costos y Márgenes |
| **Pagos** | Pagos Offline Avanzados, POS / Mostrador |
| **Envíos y Logística** | MobApp Envíos, Transporte Multiopciones, Validación de Checkout |
| **Stock y Compras** | Órdenes de Compra, Proveedores, Reglas de Reabastecimiento, Alertas (via Dashboard BI) |
| **Picking / Depósito** | Picking & Armado, Códigos de Barra, Remitos |
| **Inteligencia de Negocio** | Dashboard BI Suite, Scoring RFM, Reportes Contables |
| **Facturación y Fiscal** | Facturación AFIP, Notas de Crédito/Débito, Gestión de Impuestos |
| **Finanzas** | Flujo de Caja, Reportes Contables |
| **Comunicación** | WhatsApp, Email Marketing, Notificaciones |
| **Datos y API** | Modelo de Datos Extra, Campos Personalizados, API REST |
| **RRHH** | Empleados / RRHH |

---

## Arquitectura de la suite

### Estructura de archivos

```
wbi-suite.php                        ← Plugin principal y loader central
includes/
  class-wbi-license.php              ← Gestión de licencias
  class-wbi-registration-fields.php  ← Campos de registro (siempre activo)
  class-wbi-b2b.php                  ← Módulo B2B
  class-wbi-pricelists.php           ← Listas de precios
  class-wbi-promo-pricing.php        ← Precio Promo
  class-wbi-costs.php                ← Costos y márgenes
  class-wbi-abandoned-carts.php      ← Carritos abandonados
  class-wbi-checkout-validator.php   ← Validación de checkout
  class-wbi-crm.php                  ← CRM / Pipeline
  class-wbi-metrics.php              ← Motor de métricas (base del Dashboard BI)
  class-wbi-dashboard.php            ← Dashboard BI Suite
  class-wbi-export.php               ← Exportación CSV
  class-wbi-report-sales.php         ← Reporte de ventas
  class-wbi-report-clients.php       ← Reporte de clientes
  class-wbi-report-products.php      ← Reporte de productos
  class-wbi-stock-alerts.php         ← Alertas de stock
  class-wbi-email-reports.php        ← Reporte por email
  class-wbi-scoring.php              ← Scoring RFM
  class-wbi-accounting-reports.php   ← Reportes contables
  class-wbi-barcode.php              ← Códigos de barra
  class-wbi-picking.php              ← Picking & Armado
  class-wbi-mobapp-shipping.php      ← MobApp Envíos (wrapper)
  class-wbi-multi-shipping.php       ← Transporte Multiopciones (wrapper)
  class-wbi-remitos.php              ← Remitos
  class-wbi-suppliers.php            ← Proveedores
  class-wbi-reorder.php              ← Reglas de reabastecimiento
  class-wbi-purchase.php             ← Órdenes de compra
  class-wbi-employees.php            ← Empleados / RRHH
  class-wbi-pos.php                  ← POS / Mostrador
  class-wbi-pos-cash-movements.php   ← Movimientos de caja POS
  class-wbi-pos-cash-admin.php       ← Admin de caja POS
  class-wbi-documents.php            ← Documentos (factura + remito)
  class-wbi-invoice.php              ← Facturación AFIP
  class-wbi-credit-notes.php         ← Notas de crédito/débito
  class-wbi-advanced-offline-payments.php ← Pagos Offline Avanzados (wrapper)
  class-wbi-taxes.php                ← Gestión de impuestos
  class-wbi-cashflow.php             ← Flujo de caja
  class-wbi-whatsapp.php             ← Notificaciones WhatsApp
  class-wbi-notifications.php        ← Centro de notificaciones
  class-wbi-api.php                  ← API REST
  class-wbi-email-marketing.php      ← Email Marketing
  class-wbi-data.php                 ← Modelo de datos extra
  class-wbi-custom-fields.php        ← Campos personalizados
modules/
  woo-pagos-offline-avanzados/       ← Plugin fuente: Pagos Offline Avanzados
  woo-precio-promo/                  ← Plugin fuente: Precio Promo
  mobapp-envios/                     ← Plugin fuente: MobApp Envíos
  transporte-multiopciones/          ← Plugin fuente: Transporte Multiopciones
assets/
  admin.css / css/ js/               ← Estilos y scripts del admin
landing.html                         ← Landing page principal (marketing)
landing-erp-externo.html             ← Landing page orientada a integradores/B2B
```

### Loader central (`WBI_Suite_Loader`)

El archivo `wbi-suite.php` contiene la clase `WBI_Suite_Loader` que:

1. **Verifica la licencia** antes de cargar cualquier módulo. Sin licencia activa, solo muestra la pantalla de activación.
2. **Lee las opciones** guardadas en `wbi_modules_settings` (WordPress options).
3. **Carga condicionalmente** cada módulo según su toggle de configuración.
4. **Registra los campos de configuración** y el menú de administración bajo WooCommerce.
5. **Expone la lista de módulos** vía `get_wbi_module_list()` para el sistema de permisos por usuario.

### Módulos integrados como wrappers

Los cuatro módulos recientemente integrados (`woo-pagos-offline-avanzados`, `woo-precio-promo`, `mobapp-envios`, `transporte-multiopciones`) están en la carpeta `modules/` y son cargados mediante clases wrapper en `includes/`. Este patrón:

- Mantiene el código fuente del plugin externo sin modificar.
- Permite controlar la inicialización (hooks, dependencias de WooCommerce, cron jobs) desde el wrapper.
- Facilita la actualización independiente de cada módulo.

---

## Permisos por usuario

La suite soporta un sistema de permisos por módulo y por usuario. Un **Superadmin** puede asignar a cada usuario qué módulos puede ver y usar, independientemente del rol de WordPress.

La configuración de permisos está disponible en **WooCommerce → wooErp → Configuración** (sección de permisos).

---

## Dependencias de WordPress / WooCommerce

- **WooCommerce** es requerido para la mayoría de los módulos funcionales (B2B, Listas de Precios, Pagos, Envíos, etc.). Los módulos MobApp Envíos y Transporte Multiopciones verifican la presencia de WooCommerce en `plugins_loaded` y emiten un aviso admin si no está activo.
- **AFIP (WSFE/CAE):** La facturación electrónica con AFIP está contemplada en el módulo de Facturación AFIP.
- **WhatsApp:** El módulo de notificaciones WhatsApp requiere configuración de un proveedor de mensajería (webhook o API externa).

---

## Notas de uso y administración

- **Menú admin:** Todos los módulos activos aparecen como ítems bajo el menú principal de WooCommerce o como submenús de wooErp, según el módulo.
- **Compatibilidad:** La suite no reemplaza las pantallas estándar de WooCommerce; las extiende con funcionalidades adicionales.
- **Validación PHP:** El código puede validarse con `php -l` sobre todos los archivos `.php` del plugin.
- **Actualizaciones:** Al actualizar la suite, los módulos externos en `modules/` pueden actualizarse de forma independiente sin tocar el código del loader.
- Para generar clave, entrás al wp-admin de la suite y abrís la pantalla de licencias con el parámetro secreto wbi_gen=castanera2026. Luego elegís el plan en plan=.

Ejemplos típicos:

.../wp-admin/admin.php?page=wbi-license&wbi_gen=castanera2026&plan=T3
.../wp-admin/admin.php?page=wbi-license&wbi_gen=castanera2026&plan=M1
.../wp-admin/admin.php?page=wbi-license&wbi_gen=castanera2026&plan=A1
.../wp-admin/admin.php?page=wbi-license&wbi_gen=castanera2026&plan=LF
Planes:

T3
M1
A1
LF

---

## Comparativa wooErp vs soluciones externas

| Aspecto | wooErp (WBI Suite) | ERP externo (ej. Odoo) |
|---------|-------------------|------------------------|
| Tipo | Plugin WordPress nativo | Plataforma externa |
| Instalación | 1 clic desde WordPress | Servidor propio o SaaS |
| Sincronización | No necesaria (misma BD) | Requiere conector/cron |
| Datos en tiempo real | ✅ Siempre | Depende del sync |
| Costo por módulo | ✅ Incluido en licencia | Módulos a precio adicional |
| Argentina-ready | ✅ AFIP, provincias, transportistas | Variable |

---

## Changelog resumido

- **v9.0.35** — Integración de Precio Promo, Pagos Offline Avanzados, MobApp Envíos y Transporte Multiopciones como módulos de suite. README consolidado y landings actualizadas.
- **v9.0.13** — Módulo de Empleados / RRHH.
- **v9.0.x** — Caja POS con movimientos de caja, arqueo y permisos por cajero.
- **Versiones previas** — CRM, Email Marketing, Scoring RFM, Notas de Crédito/Débito, Reportes Contables.
