-- H297 / Menu Product Model V2 — SOKNA business baseline category reconciliation.
-- Prices are intentionally untouched.;

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'coffee','قهوه','guest_staff',10,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='coffee' OR name='قهوه');

UPDATE categories SET name='قهوه',audience='guest_staff',sort_order=10,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='coffee' OR name='قهوه';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,10 FROM menus m JOIN categories c ON (c.category_key='coffee' OR c.name='قهوه') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'food-snacks','غذا و میان‌وعده','guest_staff',20,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='food-snacks' OR name='غذا و میان‌وعده');

UPDATE categories SET name='غذا و میان‌وعده',audience='guest_staff',sort_order=20,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='food-snacks' OR name='غذا و میان‌وعده';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,20 FROM menus m JOIN categories c ON (c.category_key='food-snacks' OR c.name='غذا و میان‌وعده') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'cold-drinks','نوشیدنی‌های سرد','guest_staff',30,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='cold-drinks' OR name='نوشیدنی‌های سرد');

UPDATE categories SET name='نوشیدنی‌های سرد',audience='guest_staff',sort_order=30,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='cold-drinks' OR name='نوشیدنی‌های سرد';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,30 FROM menus m JOIN categories c ON (c.category_key='cold-drinks' OR c.name='نوشیدنی‌های سرد') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'tea-herbal','چای و دمنوش','guest_staff',40,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='tea-herbal' OR name='چای و دمنوش');

UPDATE categories SET name='چای و دمنوش',audience='guest_staff',sort_order=40,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='tea-herbal' OR name='چای و دمنوش';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,40 FROM menus m JOIN categories c ON (c.category_key='tea-herbal' OR c.name='چای و دمنوش') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'shakes-smoothies','شیک و اسموتی','guest_staff',50,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='shakes-smoothies' OR name='شیک و اسموتی');

UPDATE categories SET name='شیک و اسموتی',audience='guest_staff',sort_order=50,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='shakes-smoothies' OR name='شیک و اسموتی';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,50 FROM menus m JOIN categories c ON (c.category_key='shakes-smoothies' OR c.name='شیک و اسموتی') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'traditional-syrups','شربت‌ها','guest_staff',60,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='traditional-syrups' OR name='شربت‌ها');

UPDATE categories SET name='شربت‌ها',audience='guest_staff',sort_order=60,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='traditional-syrups' OR name='شربت‌ها';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,60 FROM menus m JOIN categories c ON (c.category_key='traditional-syrups' OR c.name='شربت‌ها') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'cakes-desserts','کیک و دسر','guest_staff',70,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='cakes-desserts' OR name='کیک و دسر');

UPDATE categories SET name='کیک و دسر',audience='guest_staff',sort_order=70,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='cakes-desserts' OR name='کیک و دسر';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,70 FROM menus m JOIN categories c ON (c.category_key='cakes-desserts' OR c.name='کیک و دسر') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'packaged-drinks','نوشیدنی‌های آماده','guest_staff',80,1,'active',1,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='packaged-drinks' OR name='نوشیدنی‌های آماده');

UPDATE categories SET name='نوشیدنی‌های آماده',audience='guest_staff',sort_order=80,active=1,lifecycle_status='active',guest_visible=1,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='packaged-drinks' OR name='نوشیدنی‌های آماده';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,80 FROM menus m JOIN categories c ON (c.category_key='packaged-drinks' OR c.name='نوشیدنی‌های آماده') WHERE m.status='active';

INSERT INTO categories(category_key,name,audience,sort_order,active,lifecycle_status,guest_visible,staff_visible) SELECT 'internal-services','سرویس‌ها','staff_only',900,1,'active',0,1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE category_key='internal-services' OR name='سرویس‌ها');

UPDATE categories SET name='سرویس‌ها',audience='staff_only',sort_order=900,active=1,lifecycle_status='active',guest_visible=0,staff_visible=1,retired_at=NULL,retired_note=NULL WHERE category_key='internal-services' OR name='سرویس‌ها';

INSERT IGNORE INTO menu_categories(menu_id,category_id,sort_order) SELECT m.id,c.id,900 FROM menus m JOIN categories c ON (c.category_key='internal-services' OR c.name='سرویس‌ها') WHERE m.status='active';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1312';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='606';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1309';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='1004';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1316';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='205';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='804';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1102';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1214';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1213';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='1019';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='604';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='807';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='209';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1202';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1308';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1304';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1201';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1314';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1112';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1007';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='703';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='210';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='805';

UPDATE items i JOIN categories c ON c.name='سرویس‌ها' SET i.category_id=c.id WHERE i.item_code='1219';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1207';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='904';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='602';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1013';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='1003';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1211';

UPDATE items i JOIN categories c ON c.name='سرویس‌ها' SET i.category_id=c.id WHERE i.item_code='1217';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1218';

UPDATE items i JOIN categories c ON c.name='سرویس‌ها' SET i.category_id=c.id WHERE i.item_code='1208';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1318';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='104';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1113';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='601';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1311';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='711';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='1001';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1216';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='901';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='608';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='709';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='103';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1109';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='603';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='713';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='902';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='808';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='108';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='801';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1303';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1301';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1206';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='705';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1103';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1313';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1315';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='903';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1204';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='203';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='109';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='707';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='212';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='211';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='708';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1105';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1012';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1107';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='806';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='117';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='802';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1104';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1209';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='202';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='106';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='213';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='206';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1005';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='605';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1016';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1006';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='803';

UPDATE items i JOIN categories c ON c.name='ترشیجات' SET i.category_id=c.id WHERE i.item_code='301';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='102';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1205';

UPDATE items i JOIN categories c ON c.name='سرویس‌ها' SET i.category_id=c.id WHERE i.item_code='1017';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1015';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='107';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1011';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='701';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='905';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='114';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1014';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='110';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1305';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='116';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='207';

UPDATE items i JOIN categories c ON c.name='قهوه' SET i.category_id=c.id WHERE i.item_code='1002';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1009';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='101';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1215';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1010';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1110';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1106';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1018';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='112';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1302';

UPDATE items i JOIN categories c ON c.name='شربت‌ها' SET i.category_id=c.id WHERE i.item_code='607';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='201';

UPDATE items i JOIN categories c ON c.name='ترشیجات' SET i.category_id=c.id WHERE i.item_code='302';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1210';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='115';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های سرد' SET i.category_id=c.id WHERE i.item_code='1008';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1307';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1306';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1310';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='111';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1111';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='204';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='118';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='208';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1203';

UPDATE items i JOIN categories c ON c.name='غذا و میان‌وعده' SET i.category_id=c.id WHERE i.item_code='1317';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='710';

UPDATE items i JOIN categories c ON c.name='خوراک' SET i.category_id=c.id WHERE i.item_code='113';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1212';

UPDATE items i JOIN categories c ON c.name='کیک و دسر' SET i.category_id=c.id WHERE i.item_code='1220';

UPDATE items i JOIN categories c ON c.name='چای و دمنوش' SET i.category_id=c.id WHERE i.item_code='712';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1101';

UPDATE items i JOIN categories c ON c.name='نوشیدنی‌های آماده' SET i.category_id=c.id WHERE i.item_code='214';

UPDATE items i JOIN categories c ON c.name='غذای اصلی' SET i.category_id=c.id WHERE i.item_code='105';

UPDATE items i JOIN categories c ON c.name='شیک و اسموتی' SET i.category_id=c.id WHERE i.item_code='1108';

-- Reconcile family owner category after member items are moved to the new customer-facing categories.
UPDATE item_variant_families f
JOIN (
  SELECT m.family_id,MIN(i.category_id) category_id,COUNT(DISTINCT i.category_id) category_count
  FROM item_variant_members m JOIN items i ON i.id=m.item_id
  GROUP BY m.family_id
) x ON x.family_id=f.id AND x.category_count=1
SET f.category_id=x.category_id;

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='بار گرم' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='بار سرد' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='چای ها' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='دمنوش ها' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='اسموتی' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='شیک ها' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='شربت ها' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='نوشیدنی' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE categories c SET c.lifecycle_status='retired',c.active=0,c.guest_visible=0,c.staff_visible=0,c.retired_at=COALESCE(c.retired_at,NOW()),c.retired_note=COALESCE(c.retired_note,'Superseded by Menu Product Model V2 category structure') WHERE c.name='فینگرفود' AND NOT EXISTS(SELECT 1 FROM items i WHERE i.category_id=c.id AND i.lifecycle_status<>'retired');

UPDATE menus SET status='active' WHERE name='کافه';

UPDATE menus SET status='inactive' WHERE name IN ('صبحانه','ناهار');
