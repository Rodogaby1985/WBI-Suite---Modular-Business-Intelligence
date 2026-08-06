# Módulo: Pedido Rápido Mayorista Público

Permite a los clientes agregar productos al carrito directamente desde el catálogo (listados de tienda, categorías, etiquetas), sin necesidad de entrar a la página de detalle del producto.

## Características

### Card de catálogo
- Reemplazo del loop add-to-cart por `form.cart` + `woocommerce_quantity_input()` + botón **"Agregar"**.
- Línea secundaria configurable: SKU, dimensiones, cantidad de colores.
- Fila compacta cantidad + botón, alineada para Woo/Flatsome.
- Feedback inmediato: mensaje de estado por card + toast flotante.

### Selector de variantes
Cuando un producto variable es procesado:

- **Sin variantes:** agrega directo sin pasos extra.
- **Con variantes:** usa selector configurable `inline` (chips) o `modal` (dropdown en card).
- Requiere `variation_id` válido para agregar cuando la cantidad es mayor a 0.
- Si una línea variable no tiene variante válida, se omite en agregado masivo y se informa al usuario.

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
| `wbi_pwoq_global_add_enabled` | boolean | Muestra la barra fija inferior para agregar todas las selecciones al carrito |
| `wbi_pwoq_initial_qty_zero` | boolean | Inicializa las cantidades visibles en 0 |
| `wbi_pwoq_hide_native_add_to_cart` | boolean | Oculta controles nativos y deja solo la card de pedido rápido en loop |
| `wbi_pwoq_force_reload_on_fragment_fail` | boolean | Fuerza recarga de página si falla actualización de fragmentos del mini-cart |

Si `wbi_enable_public_wholesale_quick_order = false`, el módulo no carga y el catálogo mantiene su comportamiento estándar.

## Pasos de prueba manual

### Producto simple (sin variantes)
1. Habilitar el módulo en *WooErp > Configuración*.
2. Ir al catálogo de la tienda.
3. Verificar que aparece el campo de cantidad y el botón "Agregar al pedido". Si `wbi_pwoq_initial_qty_zero` está activo, la cantidad debe iniciar en 0.
4. Ingresar una cantidad válida y hacer clic en el botón.
5. Confirmar toast de éxito y actualización del contador flotante.

### Producto variable — modo “modal” (dropdown en card)
1. Configurar `wbi_pwoq_variant_selector_mode = modal`.
2. Ir al catálogo.
3. Verificar dropdowns de atributos dentro de la card.
4. Seleccionar color y talla/medida; confirmar que el campo de cantidad se actualiza con el mínimo/paso de la variante.
5. Ingresar cantidad y confirmar.
6. Verificar toast de éxito y actualización inmediata del mini-cart/header cart.

### Producto variable — modo Inline
1. Cambiar a `wbi_pwoq_variant_selector_mode = inline`.
2. Verificar que los chips aparecen directamente en la card.
3. Repetir pasos 4–6 del caso anterior.

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
2. Cargar cantidades en múltiples cards del catálogo.
3. Verificar barra fija inferior full-width con resumen de selección y CTA "AGREGAR SELECCIONADOS AL CARRITO".
4. Confirmar que agrega en lote todas las líneas válidas.
5. Confirmar que líneas variables sin variante válida se omiten con mensaje claro.

### Ocultar botón nativo
1. Activar `wbi_pwoq_hide_native_add_to_cart`.
2. Ir al shop/categoría con el módulo habilitado.
3. Verificar que el loop solo muestra la UI del pedido rápido y no el botón nativo de WooCommerce.
