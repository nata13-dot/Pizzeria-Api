-- FASE 2: diagnóstico no destructivo para MariaDB 10.11.
-- Sustituir 1 y las fechas por una sucursal/rango representativos.

-- 1. Esquema, motores, tamaños e índices reales.
SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH,
       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_mb
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('orders','order_items','inventory_batches','inventory_movements','alerts','products','audit_logs','order_documents')
ORDER BY DATA_LENGTH + INDEX_LENGTH DESC;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, CARDINALITY
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('orders','order_items','inventory_batches','inventory_movements','alerts')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 2. Listado diario. Esperado: orders_branch_date_status_index o
-- el índice (branch_id, order_date, daily_number); sin ALL/filesort masivo.
EXPLAIN FORMAT=JSON
SELECT * FROM orders
WHERE branch_id = 1 AND order_date = '2026-09-05'
ORDER BY order_date DESC, daily_number DESC LIMIT 30;

-- 3. Cocina. El índice actual branch/date/status no ayuda sin order_date.
-- Revisar rows y filesort; si es alto, evaluar (branch_id,status,scheduled_at,created_at).
EXPLAIN FORMAT=JSON
SELECT id, branch_id, daily_number, status, type, scheduled_at, notes, created_at
FROM orders
WHERE branch_id = 1 AND status IN ('kitchen_pending','preparing','prepared')
ORDER BY scheduled_at IS NULL, scheduled_at, created_at;

-- 4. Programados para despacho. Candidato esperado si se aprueba:
-- (branch_id,status,scheduled_at). El actual (scheduled_at,status) puede usar rango
-- por scheduled_at, pero deja branch_id/status como filtros residuales.
EXPLAIN FORMAT=JSON
SELECT * FROM orders
WHERE branch_id = 1
  AND status = 'confirmed'
  AND scheduled_at IS NOT NULL
  AND scheduled_at <= '2026-09-10 18:00:00'
ORDER BY scheduled_at;

-- 5. Reparto operativo. Revisar ambos brazos por separado porque el OR puede
-- provocar index_merge o table scan.
EXPLAIN FORMAT=JSON
SELECT * FROM orders
WHERE branch_id = 1 AND type = 'delivery' AND status IN ('ready','on_way')
ORDER BY scheduled_at;

EXPLAIN FORMAT=JSON
SELECT * FROM orders
WHERE branch_id = 1 AND type = 'delivery'
  AND scheduled_at IS NOT NULL
  AND scheduled_at <= '2026-09-10 18:00:00'
  AND status NOT IN ('draft','pending_payment','cancelled','delivered')
ORDER BY scheduled_at;

-- 6. Pagos pendientes expirados. Ningún índice actual cubre pending_expires_at.
-- Si rows es alto, candidato: (status,pending_expires_at).
EXPLAIN FORMAT=JSON
SELECT * FROM orders
WHERE status = 'pending_payment'
  AND pending_expires_at <= '2026-09-10 18:00:00';

-- 7. FEFO real. Comparar mediante EXPLAIN antes de decidir entre el índice
-- actual y (branch_id,ingredient_id,expires_at,received_at).
EXPLAIN FORMAT=JSON
SELECT * FROM inventory_batches
WHERE branch_id = 1 AND ingredient_id = 1
  AND available_quantity > 0
  AND (expires_at IS NULL OR expires_at >= '2026-09-10')
ORDER BY expires_at IS NULL, expires_at, received_at
FOR UPDATE;

-- Distribución necesaria para interpretar FEFO.
SELECT branch_id, ingredient_id,
       COUNT(*) AS batches,
       SUM(available_quantity > 0) AS available_batches,
       SUM(expires_at IS NULL) AS without_expiry,
       COUNT(DISTINCT expires_at) AS expiry_dates
FROM inventory_batches
GROUP BY branch_id, ingredient_id
ORDER BY batches DESC LIMIT 100;

-- 8. Movimientos de cancelación/reversión por pedido. El índice esperado hoy
-- es inventory_movements_reference_type_reference_id_index. Si examina muchos
-- movimientos del mismo pedido, evaluar reemplazarlo por (reference_type,reference_id,type).
EXPLAIN FORMAT=JSON
SELECT * FROM inventory_movements
WHERE reference_type = 'App\\Models\\Order'
  AND reference_id = 1
  AND type = 'sale'
  AND quantity < 0;

EXPLAIN FORMAT=JSON
SELECT * FROM inventory_movements
WHERE reference_type = 'App\\Models\\Order' AND reference_id = 1;

-- Movimientos por ingrediente/rango. Esperado:
-- movements_branch_ingredient_date_index.
EXPLAIN FORMAT=JSON
SELECT ingredient_id, type, quantity, inventory_batch_id
FROM inventory_movements
WHERE branch_id = 1 AND ingredient_id = 1
  AND created_at >= '2026-09-01 06:00:00'
  AND created_at <  '2026-10-01 06:00:00';

-- 9. Alertas activas del listado. Candidato:
-- (branch_id,resolved_at,created_at). Si no reduce rows, revisar cardinalidad
-- de branch_id/resolved_at y el volumen real antes de crearlo.
EXPLAIN FORMAT=JSON
SELECT * FROM alerts
WHERE branch_id = 1 AND resolved_at IS NULL
ORDER BY created_at DESC;

-- refreshAlerts de stock. El candidato
-- (ingredient_id,inventory_batch_id,type,resolved_at) sí cubre este patrón.
EXPLAIN FORMAT=JSON
SELECT * FROM alerts
WHERE ingredient_id = 1 AND inventory_batch_id IS NULL
  AND type IN ('low_stock','critical_stock') AND resolved_at IS NULL;

-- refreshAlerts de lote. El candidato anterior NO tiene prefijo útil porque
-- ingredient_id no aparece en el predicado. Evaluar por separado
-- (inventory_batch_id,type,resolved_at).
EXPLAIN FORMAT=JSON
SELECT * FROM alerts
WHERE inventory_batch_id = 1
  AND type IN ('expired','expiring') AND resolved_at IS NULL;

-- 10. Agregación de productos. Esperado: índice diario/status de orders,
-- FK de order_items.order_id y PK/FK de variantes/productos.
EXPLAIN FORMAT=JSON
SELECT oi.product_variant_id, oi.combo_id, oi.name,
       SUM(oi.quantity), SUM(oi.total)
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.branch_id = 1
  AND o.order_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND o.status NOT IN ('draft','pending_payment','cancelled')
GROUP BY oi.product_variant_id, oi.combo_id, oi.name;

-- 11. Historial para tiempos. Esperado: orders_branch_date_status_index para
-- pedidos y order_history_order_date_index para el join.
EXPLAIN FORMAT=JSON
SELECT o.id,
       MIN(CASE WHEN h.to_status='kitchen_pending' THEN h.created_at END),
       MIN(CASE WHEN h.to_status='prepared' THEN h.created_at END),
       MIN(CASE WHEN h.to_status='delivered' THEN h.created_at END)
FROM orders o
LEFT JOIN order_status_histories h ON h.order_id = o.id
WHERE o.branch_id = 1
  AND o.order_date BETWEEN '2026-09-01' AND '2026-09-30'
  AND o.status NOT IN ('draft','pending_payment','cancelled')
  AND EXISTS (
      SELECT 1 FROM order_status_histories hx
      WHERE hx.order_id=o.id AND hx.to_status IN ('prepared','delivered')
  )
GROUP BY o.id;

-- 12. Tamaño lógico de columnas grandes. Sólo lectura.
SELECT COUNT(*) AS products,
       SUM(image_data_uri IS NOT NULL) AS products_with_image,
       SUM(COALESCE(OCTET_LENGTH(image_data_uri),0)) AS image_bytes,
       MAX(COALESCE(OCTET_LENGTH(image_data_uri),0)) AS largest_image_bytes
FROM products;

SELECT COUNT(*) AS audit_rows,
       SUM(COALESCE(OCTET_LENGTH(old_values),0) + COALESCE(OCTET_LENGTH(new_values),0) + COALESCE(OCTET_LENGTH(comment),0)) AS payload_bytes,
       MAX(COALESCE(OCTET_LENGTH(old_values),0) + COALESCE(OCTET_LENGTH(new_values),0)) AS largest_payload_bytes
FROM audit_logs;

SELECT auditable_type, action, COUNT(*) AS rows_count,
       SUM(COALESCE(OCTET_LENGTH(old_values),0) + COALESCE(OCTET_LENGTH(new_values),0)) AS payload_bytes
FROM audit_logs GROUP BY auditable_type, action ORDER BY payload_bytes DESC;

SELECT type, COUNT(*) AS documents,
       SUM(content IS NOT NULL) AS inline_documents,
       SUM(path IS NOT NULL) AS file_documents,
       SUM(COALESCE(OCTET_LENGTH(content),0)) AS inline_bytes,
       MAX(COALESCE(OCTET_LENGTH(content),0)) AS largest_inline_bytes
FROM order_documents GROUP BY type ORDER BY inline_bytes DESC;
