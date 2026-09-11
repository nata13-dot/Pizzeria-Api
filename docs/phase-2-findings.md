# Hallazgos de FASE 2

## Consultas optimizadas

| Endpoint | Antes | Después | Contrato |
|---|---|---|---|
| `reports/products` | Hidrataba todos los `order_items`, pedidos, variantes, productos y categorías; agrupaba y asignaba descuentos en PHP. | Un `SELECT` agrupado con `SUM` y `CASE`; sólo hidrata las filas finales. | Sin cambios. |
| `reports/profit` | Hidrataba todos los lotes usados para calcular el costo ponderado. | El costo ponderado se calcula con `SUM(initial_quantity * unit_cost) / SUM(initial_quantity)` agrupado por ingrediente. La parte por artículo permanece en PHP porque debe conservar IDs de ingredientes sin costo y la identidad histórica del artículo. | Sin cambios. |
| `reports/times` | Hidrataba pedidos y todo su historial, luego buscaba cada transición en colecciones. | Una consulta agrega la primera fecha por estado con `MIN(CASE WHEN ...)`; PHP sólo calcula diferencias y forma la respuesta. | Sin cambios. |
| `reports/inventory` | Hidrataba todos los movimientos y ajustes del rango para agruparlos en PHP. | SQL agrupa consumos, devoluciones, costos y mermas. Los lotes siguen cargándose porque forman parte de la respuesta detallada y cada ingrediente tiene un umbral de caducidad distinto. | Sin cambios. |

En términos de consultas del controlador: productos pasa aproximadamente de cinco consultas de datos a una; tiempos de dos a una; inventario pasa de cinco a seis consultas, pero deja de hidratar cada movimiento y ajuste (dos agregaciones de merma sustituyen una consulta de filas completas); profit mantiene su número de consultas, pero devuelve un registro de costo por ingrediente en vez de todos sus lotes. El beneficio crece con el histórico; latencia y filas examinadas reales deben confirmarse en producción con `EXPLAIN ANALYZE` si la política operativa lo permite, o con los `EXPLAIN FORMAT=JSON` no destructivos preparados.

## Pedidos e índices

- `order_date` es `DATE`. En MariaDB se usa comparación directa, permitiendo el índice. SQLite conserva `whereDate` únicamente para compatibilidad de la suite, porque su serialización de Eloquent guarda fecha y hora.
- Listado diario: ya está cubierto por `(branch_id, order_date, daily_number)` y por `(branch_id, order_date, status)` cuando hay estado. No se propone otro índice.
- Cocina: el plan real hizo `ALL` sobre 41 filas y filesort. Se propone `(branch_id, status, scheduled_at, created_at)`; reduce el conjunto por sucursal/estado y alinea las columnas de orden, aunque `status IN (...)` y `scheduled_at IS NULL` pueden conservar un filesort pequeño.
- Programados: el índice actual `(scheduled_at, status)` permite un rango temporal, pero no aprovecha sucursal como prefijo. Candidato pendiente: `(branch_id, status, scheduled_at)`.
- Reparto activo: el plan real hizo `ALL` sobre 41 filas y filesort. Comparte `(branch_id, status, scheduled_at, created_at)` con cocina. `type='delivery'` queda como filtro residual; añadir además `(branch_id,type,status,scheduled_at)` duplicaría gran parte del costo de escritura por un volumen actual muy bajo.
- Pagos pendientes vencidos: el plan real hizo `ALL` sobre 41 filas. Se propone `(status, pending_expires_at)`; no está cubierto por `(scheduled_at, status)`.
- Programados y reparto programado mantienen `(scheduled_at,status)`: ambos examinaron una fila.

## FEFO

La consulta real filtra explícitamente por sucursal, además del `ingredient_id` implícito en la relación, existencia positiva y fecha no caducada; ordena lotes con caducidad primero, después `expires_at` y `received_at`, y bloquea las filas. Se añadió el predicado redundante de sucursal para aislamiento defensivo y para que ambos índices comparados tengan un prefijo utilizable.

- Actual `(branch_id, ingredient_id, available_quantity)`: localiza existencia positiva, pero el rango sobre `available_quantity` impide usar columnas posteriores para ordenar.
- Candidato `(branch_id, ingredient_id, expires_at, received_at)`: favorece filtro temporal y orden FEFO, dejando `available_quantity > 0` como filtro residual. La expresión `expires_at IS NULL` puede conservar un filesort porque MariaDB ordena `NULL` antes en ascendente y FEFO los quiere al final.
- Decisión: producción tiene actualmente un lote por ingrediente y el plan examina una fila mediante la FK de `ingredient_id`. El candidato FEFO queda pendiente y no fue incluido en la propuesta.

## Movimientos de inventario

La cancelación busca `reference_type`, `reference_id`, `type='sale'` y cantidad negativa. Las relaciones y búsquedas genéricas por pedido utilizan el prefijo `(reference_type, reference_id)`. Sustituir el índice morph actual por `(reference_type, reference_id,type)` conservaría esas búsquedas y cubriría mejor cancelaciones; agregarlo sin retirar el anterior sería redundante. Las consultas por ingrediente/rango ya están cubiertas por `(branch_id,ingredient_id,created_at)`.

## Alertas

- Listado activo: candidato pendiente `(branch_id,resolved_at,created_at)`.
- Alertas de stock: `(ingredient_id,inventory_batch_id,type,resolved_at)` coincide con ingrediente, lote nulo, tipo y estado activo.
- Alertas de caducidad por lote: el candidato anterior no tiene prefijo aprovechable porque la consulta comienza con `inventory_batch_id`. Para ella sería más útil `(inventory_batch_id,type,resolved_at)`.
- Crear ambos puede ser excesivo para una tabla pequeña. Deben decidirse con cardinalidad y planes de producción. Ninguno fue creado.

## Índices redundantes confirmados

Los índices personalizados siguientes son funcionalmente idénticos a los índices de una sola columna que MariaDB crea/requiere para sus claves foráneas:

- `order_items_order_index` duplica `order_items_order_id_foreign`.
- `order_items_variant_index` duplica `order_items_product_variant_id_foreign`.
- `order_items_combo_index` duplica `order_items_combo_id_foreign`.
- `batches_purchase_item_index` duplica `inventory_batches_purchase_item_id_foreign`.
- `movements_batch_index` duplica `inventory_movements_inventory_batch_id_foreign`.

La propuesta elimina únicamente los cinco índices personalizados, conserva todos los índices de FK y agrega los dos índices mínimos de `orders` respaldados por los planes reales.

## Tamaño de tablas

- `products`: `image_data_uri` es `MEDIUMTEXT` y contiene la imagen binaria codificada como base64 dentro de cada fila. Base64 añade aproximadamente 33% y la columna también viaja en varias respuestas. Además, `Product` está auditado: crear o cambiar la imagen puede copiar el Data URI a `audit_logs.new_values` y/o `old_values`.
- `audit_logs`: el observador guarda los atributos completos al crear y los valores anteriores/nuevos modificados al actualizar para numerosos modelos. No existe retención automática visible. Productos con imágenes son el principal riesgo de payload individual anormal.
- `order_documents`: documentos de cliente se almacenan como archivos y la tabla guarda `path`; comandas de cocina y reparto guardan HTML completo en `content` (`LONGTEXT`). La generación equivalente evita duplicados idénticos nuevos, pero el histórico previo y las distintas versiones pueden acumular contenido.

No se cambió almacenamiento, retención ni contenido en esta fase.

## Lectura de los EXPLAIN

Las consultas exactas están en `docs/phase-2-mariadb-analysis.sql`. Para cada plan deben revisarse `key`, `possible_keys`, `rows`, `filtered`, archivos temporales y filesort:

- Si no usa el índice esperado pero `rows` es pequeño, no justifica añadir otro índice.
- Si usa `ALL`, examina una fracción importante de la tabla o hace filesort/tabla temporal sobre muchas filas, ejecutar `ANALYZE TABLE` sería una acción aparte y no autorizada en esta fase; primero comparta el plan y cardinalidades.
- Si el optimizador elige un índice distinto con menos filas estimadas, comparar el prefijo efectivo antes de concluir que falta un índice.
- La migración de redundantes sólo debe promoverse después de confirmar con `information_schema.STATISTICS` que cada índice de FK permanece presente y tiene exactamente la misma columna y orden.
