-- H249 Block A — master data / purchase-unit / recipe reconciliation.
-- Authorities: Menu Review V9, Monthly Reconciliation V8, Bar Costing V6, Purchases Corrected V2.
-- Safety rules:
--  * never turn an uncertain package weight/cost into a fixed fact;
--  * preserve manual active recipes;
--  * retire only the known H205 automated seed for the five superseded recipes;
--  * do not silently convert recipe mass (g) into inventory volume (ml).

-- Strawberry: the 2.5kg package in V6 is an explicit costing assumption, not confirmed master data.
UPDATE inventory_purchase_units u
JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.name='بسته',u.conversion_mode='actual_quantity',u.base_quantity=NULL,u.review_status='needs_review',
    u.note='H249: وزن بسته قطعی نیست؛ مقدار واقعی کل خرید هنگام دریافت ثبت شود. فرض ۲.۵kg از Costing V6 به master قطعی تبدیل نمی‌شود.'
WHERE i.item_code='INV-021' AND u.name='بسته ۲.۵ کیلویی'
  AND u.conversion_mode='fixed' AND u.base_quantity=2500
  AND u.review_status='ready' AND COALESCE(u.note,'')='';

-- Re-assert only mathematically/user-confirmed conversions.
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=2500,u.review_status='ready',u.note='H249: بسته ۲.۵kg تأییدشده.'
WHERE i.item_code='INV-009' AND u.name='بسته ۲.۵ کیلویی'
  AND u.conversion_mode='fixed' AND u.base_quantity=2500
  AND u.review_status='ready' AND COALESCE(u.note,'')='';
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=10000,u.review_status='ready',u.note='H249: ۴ بسته × ۲.۵kg = ۱۰kg.'
WHERE i.item_code='INV-009' AND u.name='کارتن ۴ بسته‌ای'
  AND u.conversion_mode='fixed' AND u.base_quantity=10000
  AND u.review_status='ready' AND COALESCE(u.note,'')='';
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=2500,u.review_status='ready',u.note='H249: بسته ۲.۵kg انبه تأییدشده.'
WHERE i.item_code='INV-020' AND u.name='بسته ۲.۵ کیلویی'
  AND u.conversion_mode='fixed' AND u.base_quantity=2500
  AND u.review_status='ready' AND COALESCE(u.note,'')='';

-- Ice cream purchases are confirmed as kilograms. Package/tub alternatives remain available for actual-weight entry.
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready','H249: واحد خرید بستنی توسط کاربر کیلوگرم تأیید شده است.',1
FROM inventory_items i WHERE i.item_code='INV-007'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کیلوگرم');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready','H249: خریدهای بستنی بر مبنای کیلوگرم ثبت می‌شوند؛ قیمت مستقل این طعم از conversion جداست.',1
FROM inventory_items i WHERE i.item_code='INV-008'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کیلوگرم');

-- Burger bun: corrected purchase authority says every package contains 2 buns.
-- Brand identity stays unresolved for generic «نان برگر», so item review_status remains needs_review.
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.active=0,u.review_status='needs_review',u.note='H249: superseded by corrected authority: each package contains 2 buns.'
WHERE i.item_code='INV-068' AND u.name='بسته ۳ عددی' AND u.base_quantity=3
  AND u.review_status='needs_review'
  AND COALESCE(u.note,'')='در یکی از سوابق صراحتاً بسته ۳تایی آمده.';
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=2,u.review_status='ready',u.note='H249: corrected authority — هر بسته ۲ عدد نان برگر.'
WHERE i.item_code='INV-068' AND u.name='بسته ۲ عددی' AND u.base_quantity=2
  AND u.review_status='needs_review'
  AND COALESCE(u.note,'')='در یکی از سوابق کارتن ۱۲ بسته دوتایی آمده.';
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=24,u.review_status='ready',u.note='H249: ۱۲ بسته × ۲ عدد = ۲۴ عدد.'
WHERE i.item_code='INV-068' AND u.name='کارتن ۱۲ بسته دوتایی' AND u.base_quantity=24
  AND u.review_status='needs_review' AND COALESCE(u.note,'')='';
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.conversion_mode='fixed',u.base_quantity=2,u.review_status='ready',u.note='H249: corrected authority — هر بسته ۲ عدد نان برگر.'
WHERE i.item_code='INV-069' AND u.name='بسته'
  AND u.review_status='needs_review'
  AND COALESCE(u.note,'')='تعداد داخل بسته هنوز قطعی نیست.'
  AND u.base_quantity IS NULL;
UPDATE inventory_items SET review_status='needs_review',
    review_note='H249: تبدیل بسته=۲ عدد قطعی است؛ اما خرید عمومی «نان برگر» بین masterهای برنددار بدون شاهد برند به‌صورت خودکار merge نمی‌شود.'
WHERE item_code IN ('INV-068','INV-069')
  AND review_status='needs_review'
  AND review_note IN (
    'در سابقه هم بسته ۳تایی و هم کارتن ۱۲ بسته دوتایی آمده؛ هر دو به عنوان واحد خرید موقت ثبت شده‌اند.',
    'برند طبق توضیح مالک؛ تعداد داخل بسته هنوز قطعی نیست.'
  );

-- Doogh Apada: each shrink contains 12 bottles. The latest reference purchase implies 70k/bottle,
-- but price is intentionally not fabricated as an inventory movement.
UPDATE inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id
SET u.name='شل ۱۲ بطری',u.conversion_mode='fixed',u.base_quantity=12,u.review_status='ready',
    u.note='H249: هر شل دوغ آپادا ۱۲ بطری است.'
WHERE i.item_code='INV-114' AND u.name='شل'
  AND u.conversion_mode='actual_quantity' AND u.base_quantity IS NULL
  AND u.review_status='needs_review'
  AND COALESCE(u.note,'')='تعداد بطری واقعی هر شل هنگام دریافت ثبت شود.';

-- Matilda reference price is also provenance-only; do not fabricate a receipt or balance.
UPDATE inventory_items
SET review_note='H249: خرید عددی تأیید شده و قیمت مرجع ۲۱۰٬۰۰۰ تومان/عدد است؛ تعداد/تاریخ خرید جاافتاده بدون رسید واقعی به موجودی افزوده نمی‌شود.'
WHERE item_code='INV-079' AND name='چیزکیک ماتیلدا'
  AND review_note='بارها به صورت تعداد ثبت شده.';

-- Ready-food master missing from the old inventory seed. Price is reference-only until a real receipt is recorded.
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-118','چیزکیک سن سباستین','ready_food','count','shared',0,'ready',
       'H249: قیمت مرجع آخرین خرید ۲۳۰٬۰۰۰ تومان/عدد؛ قیمت عملیاتی فقط از رسید واقعی وارد دفتر هزینه می‌شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-118' OR name='چیزکیک سن سباستین');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready','H249: هر عدد برابر یک واحد پایه است.',1
FROM inventory_items i WHERE i.item_code='INV-118'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='عدد');

-- Ingredient semantic correction: the confirmed recipe uses gold coffee powder. Cost remains unknown.
UPDATE inventory_items
SET name='پودر قهوه گلد',review_status='needs_review',
    review_note='H249: مقدار رسپی قطعی است اما قیمت خرید واقعی پودر قهوه گلد هنوز ثبت نشده است.'
WHERE item_code='INV-097' AND name='نسکافه';
INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'نسکافه','نسکافه','h249_v9_v8_v6_v2_reconciliation','ready','نام قبلی master برای حفظ جستجو و سازگاری.',1
FROM inventory_items i WHERE i.item_code='INV-097'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='نسکافه');

-- Supersede only H205's automated active versions. A manual/non-H205 active recipe is never retired here.
UPDATE inventory_recipe_versions rv
JOIN items m ON m.id=rv.menu_item_id
SET rv.status='retired',rv.retired_at=NOW()
WHERE m.item_code IN ('1103','1109','1108','1110','1111')
  AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16';

-- 1103 — شیک شکلات: source is 340g chocolate ice cream + 65g milk.
-- Milk is intentionally omitted from automatic consumption because inventory milk is ml and no density/conversion is confirmed.
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v6_confirmed_recipes_2026_10_12',
       'H249: رسپی منبع قطعی است: ۳۴۰g بستنی شکلاتی + ۶۵g شیر.',
       'شیر در منبع بر حسب گرم است ولی master شیر بر حسب ml؛ تا تأیید تبدیل جرم/حجم از کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1103'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,340,'known','H249/V6: ۳۴۰ گرم بستنی شکلاتی — مقدار قطعی.'
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id JOIN inventory_items ii ON ii.item_code='INV-008'
WHERE m.item_code='1103' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v6_confirmed_recipes_2026_10_12'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1109 — شیک انبه: 180g mango + 220g vanilla ice cream + 36g mango juice.
-- Mango juice is not silently converted g→ml.
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v6_confirmed_recipes_2026_10_12',
       'H249: رسپی منبع قطعی است: ۱۸۰g انبه + ۲۲۰g بستنی وانیلی + ۳۶g آب انبه.',
       'آب انبه در منبع بر حسب گرم است ولی master نوشیدنی/نکتار بر حسب ml؛ بدون تبدیل تأییدشده کسر خودکار نمی‌شود.',NULL
FROM items m WHERE m.item_code='1109'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,'known',x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
  SELECT 'INV-020' code,180 qty,'H249/V6: ۱۸۰ گرم انبه — مقدار قطعی.' note
  UNION ALL SELECT 'INV-007',220,'H249/V6: ۲۲۰ گرم بستنی وانیلی — مقدار قطعی.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1109' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v6_confirmed_recipes_2026_10_12'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1108 — شیک نسکافه: 300g vanilla ice cream + 2g gold coffee powder.
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','ready','complete','sokna_bar_costing_v6_confirmed_recipes_2026_10_12',
       'H249: ۳۰۰g بستنی وانیلی + ۲g پودر قهوه گلد؛ مقدارها قطعی‌اند. قیمت پودر گلد جداگانه نیازمند داده خرید است.',
       'تمام اجزای رسپی تأییدشده به master inventory نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='1108'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,'known',x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
  SELECT 'INV-007' code,300 qty,'H249/V6: ۳۰۰ گرم بستنی وانیلی — مقدار قطعی.' note
  UNION ALL SELECT 'INV-097',2,'H249/V6: ۲ گرم پودر قهوه گلد — مقدار قطعی؛ قیمت خرید هنوز نامعلوم.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1108' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v6_confirmed_recipes_2026_10_12'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1111 — اسموتی منگو بری: 140g strawberry + 30cc strawberry syrup + 190g mango. 1cc = 1ml exactly.
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','ready','complete','sokna_bar_costing_v6_confirmed_recipes_2026_10_12',
       'H249: ۱۴۰g توت‌فرنگی + ۳۰cc سیروپ توت‌فرنگی + ۱۹۰g انبه.',
       '۳۰cc معادل ۳۰ml است و همه اجزای رسپی تأییدشده به master inventory نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='1111'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,'known',x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
  SELECT 'INV-021' code,140 qty,'H249/V6: ۱۴۰ گرم توت‌فرنگی — مقدار قطعی.' note
  UNION ALL SELECT 'INV-093',30,'H249/V6: ۳۰cc = ۳۰ml سیروپ توت‌فرنگی — تبدیل حجمی قطعی.'
  UNION ALL SELECT 'INV-020',190,'H249/V6: ۱۹۰ گرم انبه — مقدار قطعی.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1111' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v6_confirmed_recipes_2026_10_12'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1110 — اسموتی انبه: 180g mango + 50ml nectar.
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','ready','complete','sokna_bar_costing_v6_confirmed_recipes_2026_10_12',
       'H249: ۱۸۰g انبه + ۵۰ml نکتار.',
       'تمام اجزای رسپی تأییدشده به master inventory نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='1110'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,'known',x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
  SELECT 'INV-020' code,180 qty,'H249/V6: ۱۸۰ گرم انبه — مقدار قطعی.' note
  UNION ALL SELECT 'INV-098',50,'H249/V6: ۵۰ میلی‌لیتر نکتار انبه — مقدار قطعی.'
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1110' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v6_confirmed_recipes_2026_10_12'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);
