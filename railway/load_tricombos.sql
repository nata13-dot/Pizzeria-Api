-- Pizzería POS · carga idempotente de los dos tricombos
-- Motor: PostgreSQL (Railway)
-- Usa las recetas existentes como fuente y escala automáticamente sus cantidades.
-- Si deseas precios promocionales, reemplaza NULL por el importe correspondiente.

BEGIN;

ALTER TABLE combo_items
    ADD COLUMN IF NOT EXISTS flavor_selection_count SMALLINT NULL;

DO $$
DECLARE
    v_precio_grande NUMERIC(12,2) := NULL; -- Ejemplo: 399.00
    v_precio_chico NUMERIC(12,2) := NULL;  -- Ejemplo: 129.00
    b RECORD;
    wings_product BIGINT; nuggets_product BIGINT; fries_product BIGINT;
    wings_source BIGINT; nuggets_source BIGINT; fries_source BIGINT;
    wings_source_amount NUMERIC; nuggets_source_amount NUMERIC; fries_source_amount NUMERIC;
    wings_20 BIGINT; wings_5 BIGINT; nuggets_18 BIGINT; nuggets_4 BIGINT; fries_500 BIGINT; fries_150 BIGINT;
    combo_large BIGINT; combo_small BIGINT; combo_wings_item BIGINT;
    calculated_large NUMERIC(12,2); calculated_small NUMERIC(12,2);
BEGIN
    FOR b IN SELECT id FROM branches LOOP
        SELECT id INTO wings_product FROM products WHERE branch_id = b.id AND type = 'wings' AND active = TRUE ORDER BY id LIMIT 1;
        SELECT id INTO nuggets_product FROM products WHERE branch_id = b.id AND type = 'nuggets' AND active = TRUE ORDER BY id LIMIT 1;
        SELECT id INTO fries_product FROM products WHERE branch_id = b.id AND type = 'fries' AND active = TRUE ORDER BY id LIMIT 1;

        IF wings_product IS NULL OR nuggets_product IS NULL OR fries_product IS NULL THEN
            RAISE EXCEPTION 'Sucursal %: primero deben existir productos activos de tipo wings, nuggets y fries.', b.id;
        END IF;

        SELECT pv.id, REPLACE(COALESCE(NULLIF(substring(pv.name FROM '([0-9]+([.,][0-9]+)?)'), ''), NULLIF(substring(p.description FROM '([0-9]+([.,][0-9]+)?)'), '')), ',', '.')::NUMERIC
          INTO wings_source, wings_source_amount FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE pv.product_id = wings_product AND pv.active = TRUE AND COALESCE(pv.sku, '') NOT LIKE 'TRI-%' AND EXISTS (SELECT 1 FROM recipes r WHERE r.product_variant_id = pv.id AND r.active = TRUE)
         ORDER BY pv.id LIMIT 1;
        SELECT pv.id, REPLACE(COALESCE(NULLIF(substring(pv.name FROM '([0-9]+([.,][0-9]+)?)'), ''), NULLIF(substring(p.description FROM '([0-9]+([.,][0-9]+)?)'), '')), ',', '.')::NUMERIC
          INTO nuggets_source, nuggets_source_amount FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE pv.product_id = nuggets_product AND pv.active = TRUE AND COALESCE(pv.sku, '') NOT LIKE 'TRI-%' AND EXISTS (SELECT 1 FROM recipes r WHERE r.product_variant_id = pv.id AND r.active = TRUE)
         ORDER BY pv.id LIMIT 1;
        SELECT pv.id, REPLACE(COALESCE(NULLIF(substring(pv.name FROM '([0-9]+([.,][0-9]+)?)'), ''), NULLIF(substring(p.description FROM '([0-9]+([.,][0-9]+)?)'), '')), ',', '.')::NUMERIC
          INTO fries_source, fries_source_amount FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE pv.product_id = fries_product AND pv.active = TRUE AND COALESCE(pv.sku, '') NOT LIKE 'TRI-%' AND EXISTS (SELECT 1 FROM recipes r WHERE r.product_variant_id = pv.id AND r.active = TRUE)
         ORDER BY pv.id LIMIT 1;

        IF wings_source_amount IS NULL OR nuggets_source_amount IS NULL OR fries_source_amount IS NULL THEN
            RAISE EXCEPTION 'Sucursal %: el nombre de la variante o descripción debe indicar piezas/gramos (ej. 8 piezas o 300g) para poder escalar las recetas.', b.id;
        END IF;

        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT wings_product,'20 piezas (Tricombo)','TRI-WINGS-20-B'||b.id,ROUND(price*20/wings_source_amount,2),2,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=wings_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,max_flavors=2,active=TRUE,updated_at=NOW() RETURNING id INTO wings_20;
        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT wings_product,'5 piezas (Tricombo)','TRI-WINGS-5-B'||b.id,ROUND(price*5/wings_source_amount,2),1,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=wings_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,max_flavors=1,active=TRUE,updated_at=NOW() RETURNING id INTO wings_5;
        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT nuggets_product,'18 piezas (Tricombo)','TRI-NUGGETS-18-B'||b.id,ROUND(price*18/nuggets_source_amount,2),1,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=nuggets_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO nuggets_18;
        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT nuggets_product,'4 piezas (Tricombo)','TRI-NUGGETS-4-B'||b.id,ROUND(price*4/nuggets_source_amount,2),1,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=nuggets_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO nuggets_4;
        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT fries_product,'500 g (Tricombo)','TRI-FRIES-500-B'||b.id,ROUND(price*500/fries_source_amount,2),1,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=fries_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO fries_500;
        INSERT INTO product_variants (product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
        SELECT fries_product,'150 g (Tricombo)','TRI-FRIES-150-B'||b.id,ROUND(price*150/fries_source_amount,2),1,FALSE,FALSE,TRUE,NOW(),NOW() FROM product_variants WHERE id=fries_source
        ON CONFLICT (sku) DO UPDATE SET product_id=EXCLUDED.product_id,name=EXCLUDED.name,price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO fries_150;

        DELETE FROM recipes WHERE product_variant_id IN (wings_20,wings_5,nuggets_18,nuggets_4,fries_500,fries_150);
        WITH targets(source_id,target_id,factor,label) AS (VALUES
            (wings_source,wings_20,20/wings_source_amount,'20 piezas'),(wings_source,wings_5,5/wings_source_amount,'5 piezas'),
            (nuggets_source,nuggets_18,18/nuggets_source_amount,'18 piezas'),(nuggets_source,nuggets_4,4/nuggets_source_amount,'4 piezas'),
            (fries_source,fries_500,500/fries_source_amount,'500 g'),(fries_source,fries_150,150/fries_source_amount,'150 g')
        ), inserted AS (
            INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at)
            SELECT t.target_id,r.product_flavor_id,'Tricombo '||t.label||CASE WHEN pf.name IS NULL THEN '' ELSE ' · '||pf.name END,TRUE,NOW(),NOW()
            FROM targets t JOIN recipes r ON r.product_variant_id=t.source_id AND r.active=TRUE LEFT JOIN product_flavors pf ON pf.id=r.product_flavor_id
            RETURNING id,product_variant_id,product_flavor_id
        )
        INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at)
        SELECT ir.id,ri.ingredient_id,ROUND(ri.quantity*t.factor,4),ri.component,NOW(),NOW()
        FROM inserted ir JOIN targets t ON t.target_id=ir.product_variant_id
        JOIN recipes source_recipe ON source_recipe.product_variant_id=t.source_id AND source_recipe.active=TRUE AND source_recipe.product_flavor_id IS NOT DISTINCT FROM ir.product_flavor_id
        JOIN recipe_items ri ON ri.recipe_id=source_recipe.id;

        SELECT price INTO calculated_large FROM product_variants WHERE id=wings_20;
        calculated_large := calculated_large+(SELECT price FROM product_variants WHERE id=nuggets_18)+(SELECT price FROM product_variants WHERE id=fries_500);
        SELECT price INTO calculated_small FROM product_variants WHERE id=wings_5;
        calculated_small := calculated_small+(SELECT price FROM product_variants WHERE id=nuggets_4)+(SELECT price FROM product_variants WHERE id=fries_150);

        INSERT INTO combos(branch_id,name,price,active,created_at,updated_at) VALUES(b.id,'Tricombo 20 alitas + 18 nuggets + 500 g de papas',COALESCE(v_precio_grande,calculated_large),TRUE,NOW(),NOW())
        ON CONFLICT (branch_id,name) DO UPDATE SET price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO combo_large;
        INSERT INTO combos(branch_id,name,price,active,created_at,updated_at) VALUES(b.id,'Tricombo 5 alitas + 4 nuggets + 150 g de papas',COALESCE(v_precio_chico,calculated_small),TRUE,NOW(),NOW())
        ON CONFLICT (branch_id,name) DO UPDATE SET price=EXCLUDED.price,active=TRUE,updated_at=NOW() RETURNING id INTO combo_small;

        DELETE FROM combo_items WHERE combo_id IN (combo_large,combo_small);
        INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(combo_large,wings_20,1,TRUE,2,TRUE,NOW(),NOW()) RETURNING id INTO combo_wings_item;
        INSERT INTO combo_allowed_options(combo_item_id,product_flavor_id,modifier_id,created_at,updated_at) SELECT combo_wings_item,id,NULL,NOW(),NOW() FROM product_flavors WHERE product_id=wings_product AND active=TRUE;
        INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(combo_large,nuggets_18,1,FALSE,NULL,TRUE,NOW(),NOW()),(combo_large,fries_500,1,FALSE,NULL,TRUE,NOW(),NOW());
        INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(combo_small,wings_5,1,TRUE,1,TRUE,NOW(),NOW()) RETURNING id INTO combo_wings_item;
        INSERT INTO combo_allowed_options(combo_item_id,product_flavor_id,modifier_id,created_at,updated_at) SELECT combo_wings_item,id,NULL,NOW(),NOW() FROM product_flavors WHERE product_id=wings_product AND active=TRUE;
        INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(combo_small,nuggets_4,1,FALSE,NULL,TRUE,NOW(),NOW()),(combo_small,fries_150,1,FALSE,NULL,TRUE,NOW(),NOW());
    END LOOP;
END $$;

COMMIT;

SELECT c.name,c.price,ci.quantity,p.name AS producto,pv.name AS variante,ci.flavor_selection_count
FROM combos c JOIN combo_items ci ON ci.combo_id=c.id JOIN product_variants pv ON pv.id=ci.product_variant_id JOIN products p ON p.id=pv.product_id
WHERE c.name LIKE 'Tricombo %' ORDER BY c.name,ci.id;
