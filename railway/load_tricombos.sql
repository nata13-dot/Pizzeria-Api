-- Pizzería POS · MySQL 5.7/8 · seguro para ejecutar varias veces.
-- Familiar $325; Dúo $148; orden de 8 nuggets $100; media orden de 4 alitas $50.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='combo_items' AND column_name='flavor_selection_count');
SET @ddl := IF(@exists=0,'ALTER TABLE combo_items ADD flavor_selection_count SMALLINT UNSIGNED NULL AFTER flavor_required','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DELIMITER $$
DROP PROCEDURE IF EXISTS load_tricombos$$
CREATE PROCEDURE load_tricombos()
BEGIN
 DECLARE done INT DEFAULT 0;
 DECLARE bid,wprod,nprod,fprod,wsrc,nsrc,fsrc,w20,w4,n18,n4,f500,f150,cfam,cduo,citem BIGINT UNSIGNED;
 DECLARE unit_piece,unit_gram,food_type,category_id,wings_ingredient,nuggets_ingredient,fries_ingredient,recipe_id BIGINT UNSIGNED;
 DECLARE wamount,namount,famount DECIMAL(12,4);
 DECLARE diagnostic_message VARCHAR(255);
 DECLARE branches CURSOR FOR SELECT id FROM branches ORDER BY id;
 DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;

 DROP TEMPORARY TABLE IF EXISTS tri_specs;
 CREATE TEMPORARY TABLE tri_specs(source_id BIGINT UNSIGNED,target_amount DECIMAL(12,4),source_amount DECIMAL(12,4),target_name VARCHAR(150),target_sku VARCHAR(100),fixed_price DECIMAL(12,2),max_flavors INT) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 START TRANSACTION;
 OPEN branches;
 branch_loop: LOOP
  FETCH branches INTO bid;
  IF done=1 THEN LEAVE branch_loop; END IF;

  SET wprod=(SELECT id FROM products WHERE branch_id=bid AND active=1 AND (type='wings' OR LOWER(name) LIKE '%alita%') ORDER BY (type='wings') DESC,id LIMIT 1);
  SET nprod=(SELECT id FROM products WHERE branch_id=bid AND active=1 AND (type='nuggets' OR LOWER(name) LIKE '%nugget%' OR LOWER(name) LIKE '%nuget%') ORDER BY (type='nuggets') DESC,id LIMIT 1);
  SET fprod=(SELECT id FROM products WHERE branch_id=bid AND active=1 AND (type='fries' OR LOWER(name) LIKE '%papa%' OR LOWER(name) LIKE '%francesa%') ORDER BY (type='fries') DESC,id LIMIT 1);
  INSERT INTO units(name,symbol,dimension,base_factor,active,created_at,updated_at) VALUES('Piezas','pz','count',1,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET unit_piece=LAST_INSERT_ID();
  INSERT INTO units(name,symbol,dimension,base_factor,active,created_at,updated_at) VALUES('Gramos','g','mass',1,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET unit_gram=LAST_INSERT_ID();
  INSERT INTO ingredient_types(name,expiry_alert_days,active,created_at,updated_at) VALUES('Alimentos preparados',3,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET food_type=LAST_INSERT_ID();
  INSERT INTO product_categories(branch_id,name,sort_order,active,created_at,updated_at) VALUES(bid,'Complementos',50,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET category_id=LAST_INSERT_ID();

  IF wprod IS NULL THEN INSERT INTO products(branch_id,product_category_id,name,type,description,active,created_at,updated_at) VALUES(bid,category_id,'Alitas','wings','Alitas por presentación y sabor.',1,NOW(),NOW()); SET wprod=LAST_INSERT_ID(); END IF;
  IF nprod IS NULL THEN INSERT INTO products(branch_id,product_category_id,name,type,description,active,created_at,updated_at) VALUES(bid,category_id,'Nuggets','nuggets','Nuggets de pollo por orden.',1,NOW(),NOW()); SET nprod=LAST_INSERT_ID(); END IF;
  IF fprod IS NULL THEN INSERT INTO products(branch_id,product_category_id,name,type,description,active,created_at,updated_at) VALUES(bid,category_id,'Papas a la francesa','fries','Porción de 300 g de papas a la francesa.',1,NOW(),NOW()); SET fprod=LAST_INSERT_ID(); END IF;
  UPDATE products SET active=1,updated_at=NOW() WHERE id IN(wprod,nprod,fprod);

  INSERT INTO ingredients(branch_id,ingredient_type_id,base_unit_id,name,sku,minimum_stock,critical_stock,expiry_alert_days,active,created_at,updated_at) VALUES(bid,food_type,unit_piece,'Alitas crudas',CONCAT('INS-WINGS-B',bid),20,8,3,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET wings_ingredient=LAST_INSERT_ID();
  INSERT INTO ingredients(branch_id,ingredient_type_id,base_unit_id,name,sku,minimum_stock,critical_stock,expiry_alert_days,active,created_at,updated_at) VALUES(bid,food_type,unit_piece,'Nuggets de pollo',CONCAT('INS-NUGGETS-B',bid),16,8,3,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET nuggets_ingredient=LAST_INSERT_ID();
  INSERT INTO ingredients(branch_id,ingredient_type_id,base_unit_id,name,sku,minimum_stock,critical_stock,expiry_alert_days,active,created_at,updated_at) VALUES(bid,food_type,unit_gram,'Papas a la francesa',CONCAT('INS-FRIES-B',bid),1000,500,3,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET fries_ingredient=LAST_INSERT_ID();

  INSERT INTO product_flavors(product_id,name,active,created_at,updated_at) SELECT wprod,'BBQ',1,NOW(),NOW() WHERE NOT EXISTS(SELECT 1 FROM product_flavors WHERE product_id=wprod AND name='BBQ');
  INSERT INTO product_flavors(product_id,name,active,created_at,updated_at) SELECT wprod,'Búfalo',1,NOW(),NOW() WHERE NOT EXISTS(SELECT 1 FROM product_flavors WHERE product_id=wprod AND name='Búfalo');

  SET wsrc=(SELECT pv.id FROM product_variants pv WHERE pv.product_id=wprod AND pv.active=1 AND COALESCE(pv.sku,'') NOT LIKE 'TRI-%' AND EXISTS(SELECT 1 FROM recipes r WHERE r.product_variant_id=pv.id AND r.active=1) ORDER BY (pv.name REGEXP '(^|[^0-9])8([^0-9]|$)') DESC,pv.id LIMIT 1);
  SET nsrc=(SELECT pv.id FROM product_variants pv WHERE pv.product_id=nprod AND pv.active=1 AND COALESCE(pv.sku,'') NOT LIKE 'TRI-%' AND EXISTS(SELECT 1 FROM recipes r WHERE r.product_variant_id=pv.id AND r.active=1) ORDER BY (pv.name REGEXP '(^|[^0-9])8([^0-9]|$)') DESC,pv.id LIMIT 1);
  SET fsrc=(SELECT pv.id FROM product_variants pv WHERE pv.product_id=fprod AND pv.active=1 AND COALESCE(pv.sku,'') NOT LIKE 'TRI-%' AND EXISTS(SELECT 1 FROM recipes r WHERE r.product_variant_id=pv.id AND r.active=1) ORDER BY pv.id LIMIT 1);
  IF wsrc IS NULL THEN
   INSERT INTO product_variants(product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at) VALUES(wprod,'Orden de 8 piezas',CONCAT('BASE-WINGS-8-B',bid),100,2,0,0,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET wsrc=LAST_INSERT_ID();
   INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at) SELECT wsrc,pf.id,CONCAT('8 alitas · ',pf.name),1,NOW(),NOW() FROM product_flavors pf WHERE pf.product_id=wprod AND pf.active=1 AND NOT EXISTS(SELECT 1 FROM recipes r WHERE r.product_variant_id=wsrc AND r.product_flavor_id=pf.id);
   INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at) SELECT r.id,wings_ingredient,8,'base',NOW(),NOW() FROM recipes r WHERE r.product_variant_id=wsrc AND NOT EXISTS(SELECT 1 FROM recipe_items ri WHERE ri.recipe_id=r.id AND ri.ingredient_id=wings_ingredient AND ri.component='base');
  END IF;
  IF nsrc IS NULL THEN
   INSERT INTO product_variants(product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at) VALUES(nprod,'Orden de 8 piezas',CONCAT('BASE-NUGGETS-8-B',bid),100,1,0,0,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),price=100,active=1,updated_at=NOW(); SET nsrc=LAST_INSERT_ID();
   INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at) SELECT nsrc,NULL,'8 nuggets',1,NOW(),NOW() WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE product_variant_id=nsrc AND product_flavor_id IS NULL);
   SET recipe_id=(SELECT id FROM recipes WHERE product_variant_id=nsrc AND product_flavor_id IS NULL ORDER BY id LIMIT 1); INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at) VALUES(recipe_id,nuggets_ingredient,8,'base',NOW(),NOW()) ON DUPLICATE KEY UPDATE quantity=8,updated_at=NOW();
  END IF;
  IF fsrc IS NULL THEN
   INSERT INTO product_variants(product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at) VALUES(fprod,'300 g',CONCAT('BASE-FRIES-300-B',bid),45,1,0,0,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1,updated_at=NOW(); SET fsrc=LAST_INSERT_ID();
   INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at) SELECT fsrc,NULL,'300 g de papas',1,NOW(),NOW() WHERE NOT EXISTS(SELECT 1 FROM recipes WHERE product_variant_id=fsrc AND product_flavor_id IS NULL);
   SET recipe_id=(SELECT id FROM recipes WHERE product_variant_id=fsrc AND product_flavor_id IS NULL ORDER BY id LIMIT 1); INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at) VALUES(recipe_id,fries_ingredient,300,'base',NOW(),NOW()) ON DUPLICATE KEY UPDATE quantity=300,updated_at=NOW();
  END IF;
  SET wamount=(SELECT CASE WHEN name REGEXP '^[0-9]+' THEN CAST(SUBSTRING_INDEX(name,' ',1) AS UNSIGNED) ELSE 8 END FROM product_variants WHERE id=wsrc);
  SET namount=(SELECT CASE WHEN name REGEXP '^[0-9]+' THEN CAST(SUBSTRING_INDEX(name,' ',1) AS UNSIGNED) ELSE 8 END FROM product_variants WHERE id=nsrc);
  SET famount=(SELECT CASE WHEN name REGEXP '^[0-9]+' THEN CAST(SUBSTRING_INDEX(name,' ',1) AS UNSIGNED) ELSE 300 END FROM product_variants WHERE id=fsrc);
  INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at)
  SELECT wsrc,pf.id,CONCAT(wamount,' alitas · ',pf.name),1,NOW(),NOW() FROM product_flavors pf
  WHERE pf.product_id=wprod AND pf.active=1 AND NOT EXISTS(SELECT 1 FROM recipes r WHERE r.product_variant_id=wsrc AND r.product_flavor_id=pf.id AND r.active=1);
  INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at)
  SELECT r.id,wings_ingredient,wamount,'base',NOW(),NOW() FROM recipes r
  WHERE r.product_variant_id=wsrc AND r.active=1 AND NOT EXISTS(SELECT 1 FROM recipe_items ri WHERE ri.recipe_id=r.id);

  DELETE FROM tri_specs;
  INSERT INTO tri_specs VALUES
   (wsrc,20,wamount,'20 piezas (Tricombo)',CONCAT('TRI-WINGS-20-B',bid),NULL,2),(wsrc,4,wamount,'Media orden · 4 piezas',CONCAT('TRI-WINGS-4-B',bid),50,1),
   (nsrc,18,namount,'18 piezas (Tricombo)',CONCAT('TRI-NUGGETS-18-B',bid),NULL,1),(nsrc,8,namount,'Orden de 8 piezas',CONCAT('TRI-NUGGETS-8-B',bid),100,1),(nsrc,4,namount,'4 piezas (Tricombo)',CONCAT('TRI-NUGGETS-4-B',bid),NULL,1),
   (fsrc,500,famount,'500 g (Tricombo)',CONCAT('TRI-FRIES-500-B',bid),NULL,1),(fsrc,150,famount,'150 g (Tricombo)',CONCAT('TRI-FRIES-150-B',bid),NULL,1);
  INSERT INTO product_variants(product_id,name,sku,price,max_flavors,allows_half_and_half,allows_stuffed_crust,active,created_at,updated_at)
  SELECT source.product_id,s.target_name,s.target_sku,COALESCE(s.fixed_price,ROUND(source.price*s.target_amount/s.source_amount,2)),s.max_flavors,0,0,1,NOW(),NOW() FROM tri_specs s JOIN product_variants source ON source.id=s.source_id
  ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(product_variants.id),sku=VALUES(sku),price=VALUES(price),max_flavors=VALUES(max_flavors),active=1,updated_at=NOW();

  DELETE r FROM recipes r JOIN product_variants pv ON pv.id=r.product_variant_id JOIN tri_specs s ON s.target_sku=pv.sku;
  INSERT INTO recipes(product_variant_id,product_flavor_id,name,active,created_at,updated_at)
  SELECT target.id,source_recipe.product_flavor_id,CONCAT('Tricombo ',s.target_name,IF(pf.name IS NULL,'',CONCAT(' · ',pf.name))),1,NOW(),NOW()
  FROM tri_specs s JOIN product_variants target ON target.sku=s.target_sku JOIN recipes source_recipe ON source_recipe.product_variant_id=s.source_id AND source_recipe.active=1 LEFT JOIN product_flavors pf ON pf.id=source_recipe.product_flavor_id;
  INSERT INTO recipe_items(recipe_id,ingredient_id,quantity,component,created_at,updated_at)
  SELECT target_recipe.id,ri.ingredient_id,ROUND(ri.quantity*s.target_amount/s.source_amount,4),ri.component,NOW(),NOW()
  FROM tri_specs s JOIN product_variants target ON target.sku=s.target_sku JOIN recipes target_recipe ON target_recipe.product_variant_id=target.id
  JOIN recipes source_recipe ON source_recipe.product_variant_id=s.source_id AND source_recipe.active=1 AND target_recipe.product_flavor_id <=> source_recipe.product_flavor_id JOIN recipe_items ri ON ri.recipe_id=source_recipe.id;

  SET w20=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-WINGS-20-B',bid)); SET w4=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-WINGS-4-B',bid));
  SET n18=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-NUGGETS-18-B',bid)); SET n4=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-NUGGETS-4-B',bid));
  SET f500=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-FRIES-500-B',bid)); SET f150=(SELECT id FROM product_variants WHERE sku=CONCAT('TRI-FRIES-150-B',bid));
  UPDATE combos SET active=0,updated_at=NOW() WHERE branch_id=bid AND name IN('Tricombo 20 alitas + 18 nuggets + 500 g de papas','Tricombo 5 alitas + 4 nuggets + 150 g de papas');
  INSERT INTO combos(branch_id,name,price,active,created_at,updated_at) VALUES(bid,'Tricombo Familiar',325,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),price=325,active=1,updated_at=NOW(); SET cfam=LAST_INSERT_ID();
  INSERT INTO combos(branch_id,name,price,active,created_at,updated_at) VALUES(bid,'Tricombo Dúo',148,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),price=148,active=1,updated_at=NOW(); SET cduo=LAST_INSERT_ID();
  DELETE FROM combo_items WHERE combo_id IN(cfam,cduo);
  INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(cfam,w20,1,1,2,1,NOW(),NOW()); SET citem=LAST_INSERT_ID();
  INSERT INTO combo_allowed_options(combo_item_id,product_flavor_id,modifier_id,created_at,updated_at) SELECT citem,id,NULL,NOW(),NOW() FROM product_flavors WHERE product_id=wprod AND active=1;
  INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(cfam,n18,1,0,NULL,1,NOW(),NOW()),(cfam,f500,1,0,NULL,1,NOW(),NOW());
  INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(cduo,w4,1,1,1,1,NOW(),NOW()); SET citem=LAST_INSERT_ID();
  INSERT INTO combo_allowed_options(combo_item_id,product_flavor_id,modifier_id,created_at,updated_at) SELECT citem,id,NULL,NOW(),NOW() FROM product_flavors WHERE product_id=wprod AND active=1;
  INSERT INTO combo_items(combo_id,product_variant_id,quantity,flavor_required,flavor_selection_count,active,created_at,updated_at) VALUES(cduo,n4,1,0,NULL,1,NOW(),NOW()),(cduo,f150,1,0,NULL,1,NOW(),NOW());
 END LOOP;
 CLOSE branches; COMMIT; DROP TEMPORARY TABLE tri_specs;
END$$
DELIMITER ;

CALL load_tricombos();
DROP PROCEDURE load_tricombos;
SELECT c.name,c.price,p.name producto,pv.name variante,ci.flavor_selection_count FROM combos c JOIN combo_items ci ON ci.combo_id=c.id JOIN product_variants pv ON pv.id=ci.product_variant_id JOIN products p ON p.id=pv.product_id WHERE c.name IN('Tricombo Familiar','Tricombo Dúo') ORDER BY c.name,ci.id;
