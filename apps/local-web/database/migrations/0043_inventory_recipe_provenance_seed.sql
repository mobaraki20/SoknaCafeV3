-- H202 — Inventory recipe provenance + safe provisional Sokna recipe seed.
-- Product rule: provisional recipe data must remain explicit and must never overwrite a manual active recipe.
-- Source workbook: Sokna_Recipe_Costing_Estimate_1405-06-16_to_1405-07-16.xlsx
-- IMPORTANT: recipe quantities below are physical per-item consumption only. The 5% management waste allowance
-- from the costing workbook is NOT folded into quantities; waste remains a separate inventory movement.

ALTER TABLE inventory_recipe_versions
    ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NOT NULL DEFAULT 'ready' AFTER status,
    ADD COLUMN IF NOT EXISTS source_ref VARCHAR(190) NULL AFTER review_status,
    ADD COLUMN IF NOT EXISTS review_note VARCHAR(500) NULL AFTER source_ref;

ALTER TABLE inventory_recipe_components
    ADD COLUMN IF NOT EXISTS quantity_status VARCHAR(20) NOT NULL DEFAULT 'known' AFTER quantity_base,
    ADD COLUMN IF NOT EXISTS source_note VARCHAR(500) NULL AFTER quantity_status;

-- Missing master ingredients that are clearly present in the purchase history.
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-090','تخم مرغ','ingredient','count','kitchen',0,'ready','خرید در سوابق به‌صورت عدد/شانه ثبت شده است؛ موجودی پایه به عدد نگهداری می‌شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-090' OR name IN ('تخم مرغ','تخم‌مرغ'));

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready','واحد پایه؛ هر عدد برابر یک عدد تخم مرغ.',1
FROM inventory_items i
WHERE i.item_code='INV-090'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='عدد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-091','پودر سوخاری پانکو','ingredient','g','kitchen',0,'needs_review','در خریدها چند نوع پانکو/پودر سوخاری ثبت شده؛ وزن بسته و نوع مصرفی هر بار باید هنگام ورود مشخص شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-091' OR name IN ('پودر سوخاری پانکو','پودر سوخاری','پانکو'));

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن بسته بین خریدها ممکن است متفاوت باشد؛ مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i
WHERE i.item_code='INV-091'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- Helper convention: all seeded versions share this source_ref. The INSERT only runs when the menu item has no active recipe.
-- If a user has already created a recipe, it wins and the seed is skipped.

-- 1310 — اسنک مرغ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه و ناقص است. نان تست و مخلوط پیاز/فلفل/ذرت/سس به‌دلیل نبود نگاشت قطعی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1310' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-025' code,45 qty,'estimated' qstatus,'۴۵ گرم مرغ؛ برآورد از مخلوط ۹۰ گرمی.' note UNION ALL
    SELECT 'INV-030',25,'estimated','۲۵ گرم قارچ؛ برآورد اولیه.' UNION ALL
    SELECT 'INV-015',45,'estimated','۴۵ گرم پنیر ویلی؛ برآورد اولیه.' UNION ALL
    SELECT 'INV-017',35,'estimated','۳۵ گرم پنیر پیتزا؛ برآورد اولیه.' UNION ALL
    SELECT 'INV-010',20,'estimated','۲۰ گرم کچاپ؛ برآورد اولیه.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1310' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1305 — پاستا چیکن آلفردو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. جعفری/ادویه تجمیعی از کسر خودکار حذف شده و گوجه گیلاسی/زیتون هنوز نیازمند تأیید مقدار هستند.',NULL
FROM items m
WHERE m.item_code='1305' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-025' code,50 qty,'known' qstatus,'۵۰ گرم مرغ طبق رسپی مبنا.' note UNION ALL
    SELECT 'INV-030',50,'known','۵۰ گرم قارچ طبق رسپی مبنا.' UNION ALL
    SELECT 'INV-005',100,'known','۱۰۰ میلی‌لیتر خامه طبق رسپی مبنا.' UNION ALL
    SELECT 'INV-003',100,'known','۱۰۰ میلی‌لیتر شیر؛ شیر دومینو با سابقه خرید دوره نگاشت شده.' UNION ALL
    SELECT 'INV-067',100,'known','۱۰۰ گرم پنه مانا طبق رسپی مبنا.' UNION ALL
    SELECT 'INV-019',10,'known','۱۰ گرم پارمسان طبق رسپی مبنا؛ master item هنوز از نظر وزن بسته نیازمند بازبینی است.' UNION ALL
    SELECT 'INV-040',20,'estimated','حدود ۲۰ گرم گوجه گیلاسی.' UNION ALL
    SELECT 'INV-046',15,'estimated','حدود ۱۵ گرم زیتون سیاه.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1305' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1301 — سیب زمینی ساده
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. روغن به‌علت ناسازگاری واحد برآورد ml با موجودی g و ادویه تجمیعی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1301' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-009' code,250 qty,'known' qstatus,'۲۵۰ گرم سیب زمینی طبق رسپی مبنا.' note UNION ALL SELECT 'INV-010',20,'estimated','۲۰ گرم کچاپ؛ برآورد اولیه.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1301' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1313 — سیب زمینی با سس قارچ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. ¼ عصاره مرغ، روغن ml→g و ادویه تا تعیین مدل واحد/نگاشت قطعی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1313' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-009' code,250 qty,'known' qstatus,'۲۵۰ گرم سیب زمینی.' note UNION ALL SELECT 'INV-030',50,'known','۵۰ گرم قارچ.' UNION ALL SELECT 'INV-005',100,'known','۱۰۰ میلی‌لیتر خامه.' UNION ALL SELECT 'INV-003',50,'known','۵۰ میلی‌لیتر شیر دومینو.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1313' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1312 — سالاد سزار
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. نان تست، بیبی کورن با واحد مبهم و سس سزار ترکیبی تا تأیید نگاشت از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1312' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-025' code,120 qty,'known' qstatus,'۱۲۰ گرم مرغ.' note UNION ALL SELECT 'INV-035',120,'known','۱۲۰ گرم کاهو.' UNION ALL SELECT 'INV-040',40,'estimated','حدود ۴۰ گرم گوجه گیلاسی.' UNION ALL SELECT 'INV-046',20,'estimated','۲۰ گرم زیتون سیاه.' UNION ALL SELECT 'INV-019',4,'known','۴ گرم پارمسان.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1312' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1309 — قارچ سوخاری
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. آرد/ادویه و روغن تجمیعی تا تعیین مقدار/واحد قطعی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1309' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-030' code,135 qty,'estimated' qstatus,'۱۳۵ گرم قارچ؛ میانه بازه ۱۲۰–۱۵۰ گرم.' note UNION ALL SELECT 'INV-090',1,'known','یک عدد تخم مرغ.' UNION ALL SELECT 'INV-091',35,'estimated','۳۵ گرم پودر سوخاری؛ برآورد اولیه.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1309' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 118 — زینگر
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. نان برگر به‌دلیل نامشخص بودن برند مصرفی، سس برگر ترکیبی و روغن تا تأیید نگاشت حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='118' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-025' code,200 qty,'known' qstatus,'۲۰۰ گرم مرغ.' note UNION ALL SELECT 'INV-091',30,'estimated','۳۰ گرم پودر سوخاری.' UNION ALL SELECT 'INV-039',50,'estimated','حدود ۵۰ گرم گوجه.' UNION ALL SELECT 'INV-035',30,'estimated','حدود ۳۰ گرم کاهو.' UNION ALL SELECT 'INV-042',20,'estimated','حدود ۲۰ گرم خیارشور.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='118' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1314 — برگر گوشت
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. نان برگر به‌دلیل نامشخص بودن برند مصرفی و سس برگر ترکیبی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1314' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-028' code,1 qty,'known' qstatus,'یک عدد برگر گوشت ۸۵٪.' note UNION ALL SELECT 'INV-039',50,'estimated','حدود ۵۰ گرم گوجه.' UNION ALL SELECT 'INV-035',30,'estimated','حدود ۳۰ گرم کاهو.' UNION ALL SELECT 'INV-042',20,'estimated','حدود ۲۰ گرم خیارشور.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1314' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1315 — برگر مرغ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,source_ref,review_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','sokna_recipe_costing_estimate_v1',
       'رسپی اولیه است. نان برگر به‌دلیل نامشخص بودن برند مصرفی و سس برگر ترکیبی از کسر خودکار حذف شده‌اند.',NULL
FROM items m
WHERE m.item_code='1315' AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (SELECT 'INV-029' code,1 qty,'known' qstatus,'یک عدد برگر مرغ.' note UNION ALL SELECT 'INV-039',50,'estimated','حدود ۵۰ گرم گوجه.' UNION ALL SELECT 'INV-035',30,'estimated','حدود ۳۰ گرم کاهو.' UNION ALL SELECT 'INV-042',20,'estimated','حدود ۲۰ گرم خیارشور.') x
JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1315' AND rv.status='active' AND rv.source_ref='sokna_recipe_costing_estimate_v1'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);
