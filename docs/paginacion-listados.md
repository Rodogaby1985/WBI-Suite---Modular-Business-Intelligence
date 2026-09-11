# Paginación de listados de datos

## Pantallas afectadas

1. **Caja POS → Sesiones** (`wbi-pos-cash`)
   - Antes: límite fijo de 200 sesiones.
   - Ahora: paginación por páginas con conteo total.

2. **Email Marketing → Suscriptores** (`wbi-email-marketing&action=subscribers`)
   - Antes: límite fijo de 200 suscriptores.
   - Ahora: paginación por páginas con filtros persistentes.

3. **Email Marketing → Reporte de campaña (suscriptores que abrieron)** (`wbi-email-marketing&action=report`)
   - Antes: límite fijo de 100 registros.
   - Ahora: paginación por páginas con total de registros.

4. **Proveedores → Productos asignados (metabox)** (`post.php` de proveedor)
   - Antes: límite fijo de 50 productos.
   - Ahora: paginación por páginas en el metabox.

5. **WhatsApp → Log de notificaciones** (`wbi-whatsapp`)
   - Antes: límite fijo de 50 pedidos con log.
   - Ahora: paginación por páginas sobre pedidos con log en rango de fechas.

## Enfoque implementado

- La paginación se resolvió en backend con:
  - lectura de parámetros de página y tamaño de página,
  - cálculo de `offset`,
  - consulta de `COUNT(*)`/`COUNT(DISTINCT ...)` para total.
- Se mantuvieron los filtros existentes en todas las transiciones de página.
- Se evitó cargar listados completos en frontend para mantener rendimiento.

## Parámetros soportados

- `paged`: número de página (mínimo 1).
- `per_page`: cantidad por página con valores permitidos `10, 25, 50, 100`.

> Nota: en contexto WordPress admin se usa `paged` como convención de navegación para no colisionar con el parámetro `page` del slug de pantalla.

## Decisiones técnicas

- Se aplicó un patrón único de paginación en listados afectados:
  - **Anterior / Siguiente**
  - **números de página**
  - **indicador “Página X de Y”**
  - **selector “por página”**
- Se conservaron mensajes de estado vacío cuando no hay resultados.
- En WhatsApp, el total se calcula por **pedidos con log** dentro del rango de fechas, manteniendo la estructura actual de datos del módulo.
