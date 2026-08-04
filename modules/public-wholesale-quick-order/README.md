# Módulo: Pedido Rápido Mayorista Público

Permite a los clientes agregar productos al carrito directamente desde el catálogo (listados de tienda, categorías, etiquetas), sin necesidad de entrar a la página de detalle del producto.

## Características

### Card de catálogo
- Línea secundaria configurable: SKU, dimensiones, cantidad de colores.
- Cantidad editable con validación de mínimo y múltiplos.
- Botón principal: **"Agregar al pedido"**.
- Feedback inmediato: mensaje de estado en la card + toast flotante.
- Resumen de carrito en píldora flotante.

### Selector de variantes
Cuando un producto variable es procesado:

- **Sin variantes:** agrega directo sin pasos extra.
- **Con variantes:** abre un selector (configurable como `inline` o `modal`):
  - Chips por atributo (color, talla, medida…).
  - Combinaciones sin stock deshabilitadas automáticamente.
  - Si solo existe una opción válida por atributo, se preselecciona.
  - Campo de cantidad con mínimo/paso configurados desde meta del producto.

### Validaciones mayoristas
- Mínimo de unidades por producto/variante (meta: `_wbi_min_qty`).
- Múltiplos de empaque (meta: `_wbi_pack_multiple`).
- Mensajes orientados al cliente:
  - "Mínimo: X unidades"
  - "Te faltan X para completar el mínimo"
  - "Elegí múltiplos de X unidades para este producto."

## Configuración (WooErp > Configuración)

| Opción | Tipo | Descripción |
|---|---|---|
| `wbi_enable_public_wholesale_quick_order` | boolean | Activa/desactiva todo el módulo |
| `wbi_pwoq_variant_selector_mode` | `modal` \| `inline` | Modo del selector de variantes |
| `wbi_pwoq_show_sku` | boolean | Muestra el SKU en la card |
| `wbi_pwoq_show_dimensions` | boolean | Muestra dimensiones del producto |
| `wbi_pwoq_show_color_count` | boolean | Muestra cantidad de colores (atributo `pa_color`) |
| `wbi_pwoq_enforce_min_qty` | boolean | Aplica validación de mínimo |
| `wbi_pwoq_enforce_pack_multiples` | boolean | Aplica validación de múltiplos de empaque |
| `wbi_pwoq_global_add_enabled` | boolean | Muestra una barra fija inferior para agregar todas las selecciones al carrito |
| `wbi_pwoq_initial_qty_zero` | boolean | Inicializa las cantidades visibles en 0 |
| `wbi_pwoq_hide_native_add_to_cart` | boolean | Oculta los botones nativos de WooCommerce en el loop |

Si `wbi_enable_public_wholesale_quick_order = false`, el módulo no carga y el catálogo mantiene su comportamiento estándar.

## Pasos de prueba manual

### Producto simple (sin variantes)
1. Habilitar el módulo en *WooErp > Configuración*.
2. Ir al catálogo de la tienda.
3. Verificar que aparece el campo de cantidad y el botón "Agregar al pedido". Si `wbi_pwoq_initial_qty_zero` está activo, la cantidad debe iniciar en 0.
4. Ingresar una cantidad válida y hacer clic en el botón.
5. Confirmar toast de éxito y actualización del contador flotante.

### Producto variable — modo Modal
1. Configurar `wbi_pwoq_variant_selector_mode = modal`.
2. Ir al catálogo.
3. Hacer clic en "Agregar al pedido" en un producto variable.
4. Verificar que se abre el modal con chips de atributos.
5. Seleccionar color y talla/medida; confirmar que el campo de cantidad se actualiza con el mínimo de la variante.
6. Ingresar cantidad y confirmar.
7. Verificar toast de éxito y cierre automático del modal.
8. Verificar que combinaciones sin stock aparecen deshabilitadas.

### Producto variable — modo Inline
1. Cambiar a `wbi_pwoq_variant_selector_mode = inline`.
2. Verificar que los chips aparecen directamente en la card.
3. Repetir pasos 5–8 del caso anterior.

### Validaciones mayoristas
1. Definir `_wbi_min_qty = 12` y `_wbi_pack_multiple = 6` en un producto.
2. Intentar agregar 5 unidades → debe mostrar "Te faltan 7 para completar el mínimo."
3. Intentar agregar 13 unidades → debe mostrar "Elegí múltiplos de 6 unidades."
4. Ingresar 12 → debe agregarse correctamente.

### Toggle global
1. Desactivar `wbi_enable_public_wholesale_quick_order`.
2. Verificar que no se carga ningún script/estilo del módulo y que el catálogo no muestra controles de pedido rápido.

## Archivos principales

- `includes/class-wbi-public-wholesale-quick-order.php` — Lógica PHP del módulo.
- `assets/css/wbi-public-wholesale-quick-order.css` — Estilos de la card, chips, modal y toast.
- `assets/js/wbi-public-wholesale-quick-order.js` — Comportamiento interactivo del cliente.
- `modules/public-wholesale-quick-order/public-wholesale-quick-order.php` — Bootstrap del módulo.

### Barra global
1. Activar `wbi_pwoq_global_add_enabled`.
2. Cargar cantidades válidas en múltiples cards del catálogo.
3. Verificar que aparece la barra fija inferior con el CTA "AGREGAR SELECCIONADOS AL CARRITO".
4. Confirmar que agrega cada selección respetando mínimo, múltiplos y selector de variantes activo.

### Ocultar botón nativo
1. Activar `wbi_pwoq_hide_native_add_to_cart`.
2. Ir al shop/categoría con el módulo habilitado.
3. Verificar que el loop solo muestra la UI del pedido rápido y no el botón nativo de WooCommerce.
