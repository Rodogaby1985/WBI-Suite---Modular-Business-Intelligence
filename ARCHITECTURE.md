# 🧠 wooErp (WBI Suite) — Arquitectura del Plugin

**Plugin Name:** wooErp — Suite de Gestión para WooCommerce  
**Versión:** 9.0.0  
**Autor:** Rodrigo Castañera  
**Última actualización:** 2026-03-25

---

## Tabla de Contenidos

1. [Metadata del Plugin](#1-metadata-del-plugin)
2. [Patrón de Arquitectura Core](#2-patrón-de-arquitectura-core)
3. [Sistema de Carga de Módulos](#3-sistema-de-carga-de-módulos)
4. [Registro Completo de Módulos](#4-registro-completo-de-módulos)
   - [🏢 Comercial](#-comercial)
   - [📊 Inteligencia de Negocio](#-inteligencia-de-negocio)
   - [📦 Operaciones](#-operaciones)
   - [💰 Finanzas](#-finanzas)
   - [🔗 Integraciones](#-integraciones)
   - [📁 Datos](#-datos)
5. [Notas Arquitecturales Especiales](#5-notas-arquitecturales-especiales)
6. [Arquitectura de UI Admin](#6-arquitectura-de-ui-admin)
7. [Árbol de Archivos](#7-árbol-de-archivos)

---

## 1. Metadata del Plugin

| Campo | Valor |
|-------|-------|
| **Plugin Name** | `wooErp — Suite de Gestión para WooCommerce` |
| **Versión** | `9.0.0` |
| **Autor** | Rodrigo Castañera |
| **Entry point** | `wbi-suite.php` |
| **Opciones DB** | `wbi_modules_settings` (tabla `wp_options`) |
| **Total módulos** | 24 toggleables + sub-módulos auto-cargados |

---

## 2. Patrón de Arquitectura Core

### Clase principal: `WBI_Suite_Loader`

El plugin sigue un patrón de **orquestador central** (`WBI_Suite_Loader`) que controla el ciclo de vida de todos los módulos.

### Secuencia de Boot

```
1. WordPress carga wbi-suite.php
2. new WBI_Suite_Loader()
3.   └─ get_option('wbi_modules_settings')
4.   └─ Registra hooks: admin_init, admin_menu, admin_notices, admin_enqueue_scripts
5.   └─ load_modules()
6.       └─ SIEMPRE: require includes/class-wbi-license.php → new WBI_License_Manager()
7.       └─ IF !license_active → STOP (solo muestra página de licencia, ningún módulo carga)
8.       └─ IF license_active → carga cada módulo según su toggle (file_exists() guard)
```

### License Gate

`WBI_License_Manager` (`includes/class-wbi-license.php`) es **siempre** el primer archivo cargado. Si la licencia no está activa, **ningún módulo** se carga.

| Plan | Código | Descripción |
|------|--------|-------------|
| Trial 3 días | `T3` | Período de prueba |
| Mensual | `M1` | Suscripción mensual |
| Anual | `A1` | Suscripción anual |
| Lifetime | `LF` | Licencia de por vida |

---

## 3. Sistema de Carga de Módulos

El sistema utiliza **toggles basados en opciones de WordPress** con guardas `file_exists()`. Cada módulo sigue este patrón:

```php
// En WBI_Suite_Loader::load_modules()
if ( ! empty( $settings['wbi_enable_<modulo>'] ) ) {
    $file = plugin_dir_path( __FILE__ ) . 'includes/class-wbi-<modulo>.php';
    if ( file_exists( $file ) ) {
        require_once $file;
        new WBI_<Modulo>_Module();
    }
}
```

Cada módulo tiene:

- **Toggle key** — clave en el array `wbi_modules_settings` (ej: `wbi_enable_b2b`)
- **Archivo de clase** — en `includes/` (ej: `class-wbi-b2b.php`)
- **Clase PHP** — instanciada al activarse (ej: `WBI_B2B_Module`)

---

## 4. Registro Completo de Módulos

### 🏢 Comercial

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 1 | `wbi_enable_b2b` | `WBI_B2B_Module` | `class-wbi-b2b.php` | Modo Mayorista B2B — Roles, precios ocultos, aprobación de clientes |
| 2 | `wbi_enable_pricelists` | `WBI_Pricelists_Module` | `class-wbi-pricelists.php` | Listas de Precios por cliente/rol/grupo |
| 3 | `wbi_enable_costs` | `WBI_Costs_Module` | `class-wbi-costs.php` | Costos y Márgenes por producto |
| 4 | `wbi_enable_abandoned_carts` | `WBI_Abandoned_Carts_Module` | `class-wbi-abandoned-carts.php` | Carritos Abandonados con recovery automático |
| 5 | `wbi_enable_checkout_validator` | `WBI_Checkout_Validator` | `class-wbi-checkout-validator.php` | Validación de Código Postal vs Provincia (Argentina) |
| 6 | `wbi_enable_crm` | `WBI_CRM_Module` | `class-wbi-crm.php` | CRM / Pipeline de Ventas tipo Kanban |

---

### 📊 Inteligencia de Negocio

> **Nota:** Todos los sub-módulos de esta sección requieren que `wbi_enable_dashboard` esté activo.

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 7 | `wbi_enable_dashboard` | `WBI_Dashboard_View` | `class-wbi-dashboard.php` | Dashboard ejecutivo principal |
| — | *(auto)* | `WBI_Metrics_Engine` | `class-wbi-metrics.php` | Motor de Cálculos — base para todos los reportes |
| — | *(auto)* | `WBI_Export_Module` | `class-wbi-export.php` | Exportación CSV |
| — | *(auto)* | `WBI_Report_Sales` | `class-wbi-report-sales.php` | Reporte de Ventas |
| — | *(auto)* | `WBI_Report_Clients` | `class-wbi-report-clients.php` | Reporte de Clientes |
| — | *(auto)* | `WBI_Report_Products` | `class-wbi-report-products.php` | Reporte de Productos & Stock |
| — | *(auto)* | `WBI_Stock_Alerts` | `class-wbi-stock-alerts.php` | Alertas de Stock bajo mínimo |
| — | *(auto)* | `WBI_Email_Reports` | `class-wbi-email-reports.php` | Reportes Automáticos por Email |
| 8 | `wbi_enable_scoring` | `WBI_Scoring_Module` | `class-wbi-scoring.php` | Scoring RFM de Clientes (cron diario) |

---

### 📦 Operaciones

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 9 | `wbi_enable_barcode` | `WBI_Barcode_Module` | `class-wbi-barcode.php` | Generación de Códigos de Barra EAN/UPC |
| 10 | `wbi_enable_picking` | `WBI_Picking_Module` | `class-wbi-picking.php` | Picking & Armado de pedidos con escaneo |
| 11 | `wbi_enable_remitos` | *(unificado)* | `class-wbi-documents.php` | Remitos PDF vinculados a pedidos |
| 12 | `wbi_enable_suppliers` | `WBI_Suppliers_Module` | `class-wbi-suppliers.php` | Gestión de Proveedores |
| 13 | `wbi_enable_reorder` | `WBI_Reorder_Module` | `class-wbi-reorder.php` | Reglas de Reabastecimiento (cron cada 12 horas) |
| 14 | `wbi_enable_purchase` | `WBI_Purchase_Module` | `class-wbi-purchase.php` | Órdenes de Compra a proveedores |

---

### 💰 Finanzas

> **Nota:** Los módulos financieros se cargan dentro del bloque del dashboard (requieren `wbi_enable_dashboard`).

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 15 | `wbi_enable_invoice` | *(unificado)* | `class-wbi-documents.php` | Facturación AFIP tipo A/B/C |
| 16 | `wbi_enable_credit_notes` | `WBI_Credit_Notes_Module` | `class-wbi-credit-notes.php` | Notas de Crédito y Débito |
| 17 | `wbi_enable_taxes` | `WBI_Taxes_Module` | `class-wbi-taxes.php` | IVA, percepciones e impuestos internos |
| 18 | `wbi_enable_cashflow` | `WBI_Cashflow_Module` | `class-wbi-cashflow.php` | Flujo de Caja |
| 19 | `wbi_enable_accounting_reports` | `WBI_Accounting_Reports_Module` | `class-wbi-accounting-reports.php` | Libro IVA, Estado de Resultados |

---

### 🔗 Integraciones

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 20 | `wbi_enable_whatsapp` | `WBI_Whatsapp_Module` | `class-wbi-whatsapp.php` | Notificaciones via WhatsApp |
| 21 | `wbi_enable_api` | `WBI_API_Module` | `class-wbi-api.php` | Endpoints REST API personalizados |
| 22 | `wbi_enable_notifications` | `WBI_Notifications_Module` | `class-wbi-notifications.php` | Centro de alertas unificado |
| 23 | `wbi_enable_email_marketing` | `WBI_Email_Marketing_Module` | `class-wbi-email-marketing.php` | Campañas masivas de email |

---

### 📁 Datos

| # | Toggle Key | Clase | Archivo | Descripción |
|---|-----------|-------|---------|-------------|
| 24 | `wbi_enable_data` | `WBI_Data_Module` | `class-wbi-data.php` | Campos extra, origen de venta, taxonomías personalizadas |

---

## 5. Notas Arquitecturales Especiales

### Módulo Unificado de Documentos (O+L)

`wbi_enable_invoice` **o** `wbi_enable_remitos` — ambos toggles cargan el mismo archivo `class-wbi-documents.php` e instancian `WBI_Documents_Module`. El módulo unificado maneja tanto facturas AFIP como remitos PDF sin duplicar código.

```php
// Ambos toggles apuntan al mismo archivo/clase
if ( ! empty($settings['wbi_enable_invoice']) || ! empty($settings['wbi_enable_remitos']) ) {
    require_once 'includes/class-wbi-documents.php';
    new WBI_Documents_Module();
}
```

> Los archivos `class-wbi-invoice.php` y `class-wbi-remitos.php` se mantienen en `includes/` por compatibilidad hacia atrás (**legacy**).

### Sub-módulos Auto-cargados del Dashboard

Cuando `wbi_enable_dashboard` está activo, los siguientes módulos se cargan **automáticamente** sin necesitar toggles independientes:

- `WBI_Metrics_Engine` — motor de cálculos base
- `WBI_Export_Module` — exportación CSV
- `WBI_Report_Sales` — reporte de ventas
- `WBI_Report_Clients` — reporte de clientes
- `WBI_Report_Products` — reporte de productos & stock
- `WBI_Stock_Alerts` — alertas de stock
- `WBI_Email_Reports` — reportes automáticos por email

### Módulos Financieros Anidados

Los siguientes módulos requieren que `wbi_enable_dashboard` esté activo para cargarse:

- `wbi_enable_costs`, `wbi_enable_suppliers`, `wbi_enable_purchase`
- `wbi_enable_scoring`, `wbi_enable_taxes`, `wbi_enable_cashflow`
- `wbi_enable_credit_notes`, `wbi_enable_notifications`
- `wbi_enable_api`, `wbi_enable_accounting_reports`

### Cron Jobs

| Módulo | Evento WP-Cron | Frecuencia | Limpieza |
|--------|---------------|------------|---------|
| `WBI_Scoring_Module` | `wbi_scoring_cron` | Diario | `deactivate()` hook del plugin |
| `WBI_Reorder_Module` | `wbi_reorder_cron` | Cada 12 horas | `deactivate()` hook del plugin |

### Rol Personalizado

En la **activación del plugin** se crea el rol de WordPress:

| Rol | Slug | Capabilities |
|-----|------|-------------|
| Armador WBI | `wbi_armador` | `read` |

---

## 6. Arquitectura de UI Admin

### Menús de Administración

| Página | Tipo de Menú | Slug | Dashicon |
|--------|-------------|------|---------|
| Configuración de Módulos | Sub-menú WooCommerce | `wbi-settings` | — |
| Gestión de Licencia | Menú de nivel superior | `wbi-license` | `dashicons-lock` |

### Interfaz de Configuración de Módulos

- **Card-based grid**: Los módulos se presentan en tarjetas agrupadas por categoría
- **Toggle switches**: Cada módulo tiene un toggle on/off visual
- **Sistema de permisos**: Matriz de roles de WordPress × módulos (control de acceso por módulo)

### Assets de Administración

| Archivo | Descripción | Carga |
|---------|-------------|-------|
| `assets/admin.css` | Estilos principales del admin | Solo en páginas WBI (`wbi-*`) |
| `assets/css/` | CSS adicional por módulo | Según necesidad |
| `assets/js/` | JavaScript adicional | Según necesidad |

### Ordenamiento de Tablas (JS Inline)

Se registra en `admin_footer` un script de ordenamiento inline con soporte para:

- **Números AR**: formato `$1.234,56` (separador de miles `.`, decimal `,`)
- **Fechas**: formato `dd/mm/yyyy`
- **Texto**: ordenamiento local con `localeCompare('es')`

---

## 7. Árbol de Archivos

```
wbi-suite.php                          # Entry point & WBI_Suite_Loader
assets/
├── admin.css                          # Estilos de administración
├── css/                               # CSS adicional
└── js/                                # JavaScript adicional
includes/
├── class-wbi-license.php              # License Manager (siempre cargado primero)
├── class-wbi-b2b.php                  # Módulo B2B Mayorista
├── class-wbi-data.php                 # Módulo de Datos
├── class-wbi-metrics.php              # Motor de Métricas
├── class-wbi-dashboard.php            # Dashboard Ejecutivo
├── class-wbi-export.php               # Exportación CSV
├── class-wbi-report-sales.php         # Reporte de Ventas
├── class-wbi-report-clients.php       # Reporte de Clientes
├── class-wbi-report-products.php      # Reporte de Productos & Stock
├── class-wbi-stock-alerts.php         # Alertas de Stock
├── class-wbi-email-reports.php        # Reportes por Email
├── class-wbi-costs.php                # Costos y Márgenes
├── class-wbi-suppliers.php            # Gestión de Proveedores
├── class-wbi-purchase.php             # Órdenes de Compra
├── class-wbi-scoring.php              # Scoring RFM (cron diario)
├── class-wbi-taxes.php                # Gestión de Impuestos
├── class-wbi-cashflow.php             # Flujo de Caja
├── class-wbi-documents.php            # Módulo Unificado: Facturas + Remitos
├── class-wbi-invoice.php              # (legacy) Facturación anterior
├── class-wbi-remitos.php              # (legacy) Remitos anterior
├── class-wbi-credit-notes.php         # Notas de Crédito/Débito
├── class-wbi-notifications.php        # Centro de Notificaciones
├── class-wbi-api.php                  # API REST
├── class-wbi-barcode.php              # Códigos de Barra EAN/UPC
├── class-wbi-picking.php              # Picking & Armado
├── class-wbi-pricelists.php           # Listas de Precios
├── class-wbi-whatsapp.php             # Notificaciones WhatsApp
├── class-wbi-abandoned-carts.php      # Carritos Abandonados
├── class-wbi-checkout-validator.php   # Validador de Checkout (CP vs Provincia)
├── class-wbi-accounting-reports.php   # Reportes Contables (Libro IVA, etc.)
├── class-wbi-email-marketing.php      # Email Marketing Masivo
├── class-wbi-reorder.php              # Reglas de Reabastecimiento (cron 12h)
└── class-wbi-crm.php                  # CRM / Pipeline de Ventas
```

---

*Documentación generada a partir del código fuente de `wbi-suite.php` e `includes/`. Última actualización: 2026-03-25.*
