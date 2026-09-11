# WooErp / WBI Suite — UI Design System Administrativo

## Objetivo
Definir una base visual reutilizable, moderna y consistente para pantallas administrativas de WooErp/WBI Suite, manteniendo compatibilidad con WordPress y sin afectar otros screens fuera del plugin.

## Alcance y scoping
- Estilos base en `assets/admin.css`.
- Componentes prefijados con `wbi-`.
- Aplicación recomendada sobre wrappers `.wbi-wrap` / `.wbi-page`.
- Evitar selectores globales sin prefijo.

## Tokens
Definidos en `:root` con variables `--wbi-*`:
- **Color**: primario, éxito, advertencia, peligro, info, superficies, fondo, bordes, texto, foco.
- **Espaciado**: `--wbi-space-*` para ritmo vertical/horizontal.
- **Forma**: `--wbi-radius`, `--wbi-radius-sm`, `--wbi-radius-xs`.
- **Elevación**: `--wbi-shadow`, `--wbi-shadow-md`, `--wbi-shadow-lg`.
- **Sizing**: `--wbi-size-control`, `--wbi-size-control-sm`.
- **Tipografía**: `--wbi-font`.

## Componentes reutilizables

### 1) Layout de página
- `.wbi-page`
- `.wbi-page-header`
- `.wbi-page-title`
- `.wbi-page-description`
- `.wbi-page-actions`

### 2) Superficies y KPI
- `.wbi-card`, `.wbi-card-header`, `.wbi-card-title`, `.wbi-card-subtitle`
- Variantes semánticas: `.wbi-card.green|blue|orange|red|indigo`
- KPI: `.wbi-stat-card`, `.wbi-number`, `.wbi-label`, `.wbi-compare-value`

### 3) Filtros y grillas de campos
- `.wbi-filter-panel`
- `.wbi-filter-grid`
- `.wbi-filter-field`
- `.wbi-filter-actions`
- Columnas responsive: `.wbi-col-2|3|4|6`

### 4) Botones y acciones
- Base: `.wbi-btn`
- Variantes: `.wbi-btn-primary`, `.wbi-btn-success`, `.wbi-btn-danger`, `.wbi-btn-link`
- Estados: `:hover`, `:focus-visible`, `[disabled]`, `[aria-busy="true"]`, `.is-loading`

### 5) Badges de estado
- `.wbi-badge`
- Variantes: `.wbi-badge-success|warning|danger|info|primary|muted`

### 6) Tablas y contenedor responsive
- `.wbi-table-responsive`
- `.wbi-table`, `.wbi-sortable`
- Alineación: `th/td[data-align="right"]`

### 7) Paginación
- `.wbi-pagination`
- Compatibilidad con `paginate_links()` de WordPress
- Estilo de `.page-numbers` y estado `.current`

### 8) Alertas y notices
- `.wbi-alert`
- Variantes: `.wbi-alert-success|warning|danger|info`

### 9) Estados de UI
- `.wbi-state`
- Variantes: `.wbi-state-empty|loading|error|disabled`
- Indicador: `.wbi-spinner`

## Accesibilidad
- Foco visible consistente con `:focus-visible` dentro de `.wbi-wrap`.
- Etiquetas textuales obligatorias en filtros/formularios.
- Estados semánticos no dependen solo del color (texto + badge/label).
- Mantener estructura tabular para datos densos administrativos.

## Responsive
- Mobile-first.
- En móvil: controles full-width, densidad reducida, tabs con scroll horizontal.
- Grillas de filtros adaptables por breakpoint.

## Implementación representativa en este PR
Superficie piloto: **Dashboard Ejecutivo** (`includes/class-wbi-dashboard.php`):
- Nuevo header de página (`wbi-page-*`).
- Filtros en panel/grid responsivo.
- Tarjetas, tablas y contenedores de gráficos usando componentes reutilizables.
- Paginación representativa para tablas de productos (con `paginate_links`).
- Reducción de estilos inline en esa pantalla.

## Patrón recomendado para migración gradual (issue #153)
1. Envolver pantalla con `.wbi-wrap > .wbi-page`.
2. Migrar primero header + filtros (`wbi-page-*`, `wbi-filter-*`).
3. Migrar superficies a `.wbi-card` y variantes semánticas.
4. Normalizar tablas con `.wbi-table-responsive` + `.wbi-table`.
5. Incorporar `.wbi-pagination` con `paginate_links()` preservando filtros actuales.
6. Sustituir inline styles solo en la superficie tocada.
7. Preservar filtros/query params con allowlist explícita (no copiar `$_GET` completo) para evitar propagar parámetros no deseados entre pantallas.

Este patrón permite PRs pequeños por módulo sin refactor masivo ni cambios de lógica de negocio.
