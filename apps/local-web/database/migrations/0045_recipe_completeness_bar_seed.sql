-- H205 — Recipe completeness truth + provisional bar recipe seed.
-- Source: Sokna_Bar_Costing_V3_1405-06-16_to_1405-07-16.xlsx
-- SHA-256: 32a2e0f6354cfd96710d055af4df016e50d8a99e7e7df500950a28e68c95a5b6
-- Policy: never overwrite an active/manual recipe; partial recipes must not qualify for contribution margin.

ALTER TABLE inventory_recipe_versions
    ADD COLUMN IF NOT EXISTS completeness_status VARCHAR(20) NOT NULL DEFAULT 'complete' AFTER review_status,
    ADD COLUMN IF NOT EXISTS completeness_note VARCHAR(1000) NULL AFTER review_note;

-- Kitchen provisional recipes from H202 intentionally omitted known components; classify them as partial.
UPDATE inventory_recipe_versions
SET completeness_status='partial', completeness_note=COALESCE(completeness_note,review_note,'رسپی اولیه ناقص است و برای تحلیل کامل باید تکمیل شود.')
WHERE source_ref='sokna_recipe_costing_estimate_v1' AND status='active';

-- INV-092 — پودر کاکائو
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-092','پودر کاکائو','ingredient','g','bar',0,'ready','خرید ۳۳۰ گرم با مبلغ مشخص در داده هزینه بار ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-092' OR name='پودر کاکائو');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-092'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-093 — سیروپ توت‌فرنگی
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-093','سیروپ توت‌فرنگی','ingredient','ml','bar',0,'needs_review','هویت ماده روشن است؛ حجم بطری در مدل هزینه ۷۸۰ml فرض شده و باید هنگام ورود واقعی ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-093' OR name='سیروپ توت‌فرنگی');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-093'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-094 — آبلیمو صنعتی
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-094','آبلیمو صنعتی','ingredient','ml','bar',0,'ready','۱۸ لیتر خرید با مبلغ مشخص در داده هزینه بار ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-094' OR name='آبلیمو صنعتی');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'لیتر','fixed',1000,'ready','هر لیتر برابر ۱۰۰۰ میلی‌لیتر.',1
FROM inventory_items i WHERE i.item_code='INV-094'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='لیتر');

-- INV-095 — ارده
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-095','ارده','ingredient','g','bar',0,'ready','۲.۶ کیلو خرید با وزن مشخص در داده هزینه بار ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-095' OR name='ارده');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلو','fixed',1000,'ready','هر کیلو برابر ۱۰۰۰ گرم.',1
FROM inventory_items i WHERE i.item_code='INV-095'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کیلو');

-- INV-096 — کرم لوتوس
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-096','کرم لوتوس','ingredient','g','bar',0,'needs_review','وزن ظرف ۴۰۰g در مدل هزینه فرض شده و باید هنگام دریافت واقعی تأیید شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-096' OR name='کرم لوتوس');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-096'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-097 — نسکافه
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-097','نسکافه','ingredient','g','bar',0,'needs_review','قیمت/وزن خرید در بازه قطعی نیست؛ master برای ثبت واقعی خرید ایجاد می‌شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-097' OR name='نسکافه');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-097'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-098 — نکتار انبه
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-098','نکتار انبه','ingredient','ml','bar',0,'needs_review','حجم ۱ لیتری در مدل هزینه فرض شده و باید هنگام دریافت واقعی تأیید شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-098' OR name='نکتار انبه');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-098'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-099 — چای
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-099','چای','ingredient','g','bar',0,'needs_review','وزن بسته ۱kg در مدل هزینه فرض شده و باید از بسته/فاکتور تأیید شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-099' OR name='چای');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','مقدار واقعی هر دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-099'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- INV-100 — شکلات کنار قهوه
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-100','شکلات کنار قهوه','ingredient','count','bar',0,'needs_review','تعداد سرو به‌ازای شات مشخص است؛ هزینه/تعداد بسته خرید هنوز باید با وزن یا فاکتور تأیید شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-100' OR name='شکلات کنار قهوه');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','تعداد شکلات داخل بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-100'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');

-- 901 — اسپرسو تک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','complete','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','همه اجزای ثبت‌شده در منبع به inventory master نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='901'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,9 qty,'estimated' qstatus,'قهوه: 9 گرم — منبع Bar Costing V3؛ دوز ۹g فرض اولیه؛ باید یک بار وزن شود.' note
    UNION ALL SELECT 'INV-100' code,1 qty,'known' qstatus,'شکلات کنار قهوه: 1 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='901' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 902 — اسپرسو دبل
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','complete','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','همه اجزای ثبت‌شده در منبع به inventory master نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='902'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ دوز ۱۸g فرض اولیه.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='902' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 903 — آمریکانو تک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='903'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,9 qty,'estimated' qstatus,'قهوه: 9 گرم — منبع Bar Costing V3؛ ۹g قهوه + آب.' note
    UNION ALL SELECT 'INV-100' code,1 qty,'known' qstatus,'شکلات کنار قهوه: 1 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='903' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 904 — آمریکانو دبل
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='904'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ ۱۸g قهوه + آب.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='904' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1001 — آیس آمریکانو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1001'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ دبل‌شات فرض شده.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1001' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1004 — آیس لته
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1004'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ ۱۸g قهوه + ۱۸۰ml شیر.' note
    UNION ALL SELECT 'INV-003' code,180 qty,'estimated' qstatus,'شیر پرچرب: 180 میلی‌لیتر — منبع Bar Costing V3؛ ۱۸g قهوه + ۱۸۰ml شیر.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1004' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1002 — آیس کارامل ماکیاتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','سیروپ طعم‌دار عمومی: سیروپ عمومی به SKU واقعی وصل نیست؛ برای جلوگیری از کسر از ماده اشتباه حذف شده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1002'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ ۲۰ml سیروپ کارامل فرض شده.' note
    UNION ALL SELECT 'INV-003' code,180 qty,'estimated' qstatus,'شیر پرچرب: 180 میلی‌لیتر — منبع Bar Costing V3؛ ۲۰ml سیروپ کارامل فرض شده.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1002' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1003 — آیس موکا
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1003'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ ۷g کاکائو؛ سس شکلات مستقل ثبت نشده.' note
    UNION ALL SELECT 'INV-003' code,160 qty,'estimated' qstatus,'شیر پرچرب: 160 میلی‌لیتر — منبع Bar Costing V3؛ ۷g کاکائو؛ سس شکلات مستقل ثبت نشده.' note
    UNION ALL SELECT 'INV-092' code,7 qty,'estimated' qstatus,'پودر کاکائو: 7 گرم — منبع Bar Costing V3؛ ۷g کاکائو؛ سس شکلات مستقل ثبت نشده.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1003' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 905 — آفوگاتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='905'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-001' code,9 qty,'estimated' qstatus,'قهوه: 9 گرم — منبع Bar Costing V3؛ ۱ شات + ۱۲۰ml بستنی.' note
    UNION ALL SELECT 'INV-100' code,1 qty,'known' qstatus,'شکلات کنار قهوه: 1 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='905' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1011 — آب هندوانه
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1011'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-033' code,350 qty,'estimated' qstatus,'هندوانه: 350 گرم — منبع Bar Costing V3؛ ۳۵۰g هندوانه.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1011' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1009 — شیر موز
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1009'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,200 qty,'estimated' qstatus,'شیر پرچرب: 200 میلی‌لیتر — منبع Bar Costing V3؛ ۲۰۰ml شیر + ۱۵۰g موز.' note
    UNION ALL SELECT 'INV-032' code,150 qty,'estimated' qstatus,'موز: 150 گرم — منبع Bar Costing V3؛ ۲۰۰ml شیر + ۱۵۰g موز.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1009' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1010 — شیر موز بستنی
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1010'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,180 qty,'estimated' qstatus,'شیر پرچرب: 180 میلی‌لیتر — منبع Bar Costing V3؛ ۱۸۰ml شیر + موز + ۱۲۰ml بستنی.' note
    UNION ALL SELECT 'INV-032' code,150 qty,'estimated' qstatus,'موز: 150 گرم — منبع Bar Costing V3؛ ۱۸۰ml شیر + موز + ۱۲۰ml بستنی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1010' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1005 — موهیتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','آب گازدار: آب گازدار مصرفی بر حسب ml است و به SKU شمارشی موجودی نگاشت قطعی ندارد.؛ گارنیش: گارنیش تجمیعی به ماده انبار مشخص نگاشت نشده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1005'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-060' code,20 qty,'estimated' qstatus,'سیروپ نعنا/موهیتو: 20 میلی‌لیتر — منبع Bar Costing V3؛ ۲۰ml سیروپ نعنا؛ خرید ۳ بطری با فروش موهیتوها خوب هم‌راستا است.' note
    UNION ALL SELECT 'INV-031' code,30 qty,'estimated' qstatus,'لیمو تازه: 30 گرم — منبع Bar Costing V3؛ ۲۰ml سیروپ نعنا؛ خرید ۳ بطری با فروش موهیتوها خوب هم‌راستا است.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1005' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1016 — بلو موهیتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','آب گازدار: آب گازدار مصرفی بر حسب ml است و به SKU شمارشی موجودی نگاشت قطعی ندارد.؛ گارنیش: گارنیش تجمیعی به ماده انبار مشخص نگاشت نشده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1016'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-060' code,20 qty,'estimated' qstatus,'سیروپ نعنا/موهیتو: 20 میلی‌لیتر — منبع Bar Costing V3؛ ۱۵ml بلوکاراسائو فرض شده.' note
    UNION ALL SELECT 'INV-059' code,15 qty,'estimated' qstatus,'سیروپ بلوکاراسائو: 15 میلی‌لیتر — منبع Bar Costing V3؛ ۱۵ml بلوکاراسائو فرض شده.' note
    UNION ALL SELECT 'INV-031' code,30 qty,'estimated' qstatus,'لیمو تازه: 30 گرم — منبع Bar Costing V3؛ ۱۵ml بلوکاراسائو فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1016' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1018 — رد موهیتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','آب گازدار: آب گازدار مصرفی بر حسب ml است و به SKU شمارشی موجودی نگاشت قطعی ندارد.؛ گارنیش: گارنیش تجمیعی به ماده انبار مشخص نگاشت نشده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1018'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-060' code,20 qty,'estimated' qstatus,'سیروپ نعنا/موهیتو: 20 میلی‌لیتر — منبع Bar Costing V3؛ ۱۵ml توت‌فرنگی فرض شده.' note
    UNION ALL SELECT 'INV-093' code,15 qty,'estimated' qstatus,'سیروپ توت‌فرنگی: 15 میلی‌لیتر — منبع Bar Costing V3؛ ۱۵ml توت‌فرنگی فرض شده.' note
    UNION ALL SELECT 'INV-031' code,30 qty,'estimated' qstatus,'لیمو تازه: 30 گرم — منبع Bar Costing V3؛ ۱۵ml توت‌فرنگی فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1018' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1006 — لیموناد
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','آب گازدار: آب گازدار مصرفی بر حسب ml است و به SKU شمارشی موجودی نگاشت قطعی ندارد.؛ شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1006'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-094' code,60 qty,'estimated' qstatus,'آبلیمو صنعتی: 60 میلی‌لیتر — منبع Bar Costing V3؛ ۶۰ml آبلیمو صنعتی + آب گازدار.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1006' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1008 — فیمتو اسپرایت
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1008'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-076' code,1 qty,'known' qstatus,'اسپرایت قوطی: 1 عدد — منبع Bar Costing V3؛ ۱ قوطی اسپرایت + ۳۰ml فیمتو.' note
    UNION ALL SELECT 'INV-062' code,30 qty,'estimated' qstatus,'فیمتو: 30 میلی‌لیتر — منبع Bar Costing V3؛ ۱ قوطی اسپرایت + ۳۰ml فیمتو.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1008' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1007 — فیمتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','آب گازدار: آب گازدار مصرفی بر حسب ml است و به SKU شمارشی موجودی نگاشت قطعی ندارد.؛ یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1007'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-062' code,30 qty,'estimated' qstatus,'فیمتو: 30 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰ml فیمتو.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1007' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1103 — شیک شکلات
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی شکلاتی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1103'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ پایه ۱۵۰ml شیر + ۱۸۰ml بستنی شکلاتی.' note
    UNION ALL SELECT 'INV-092' code,5 qty,'estimated' qstatus,'پودر کاکائو: 5 گرم — منبع Bar Costing V3؛ پایه ۱۵۰ml شیر + ۱۸۰ml بستنی شکلاتی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1103' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1109 — شیک انبه
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1109'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,120 qty,'estimated' qstatus,'شیر پرچرب: 120 میلی‌لیتر — منبع Bar Costing V3؛ ۱۰۰g انبه.' note
    UNION ALL SELECT 'INV-020' code,100 qty,'estimated' qstatus,'انبه منجمد: 100 گرم — منبع Bar Costing V3؛ ۱۰۰g انبه.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1109' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1112 — شیک توت فرنگی
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1112'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,120 qty,'estimated' qstatus,'شیر پرچرب: 120 میلی‌لیتر — منبع Bar Costing V3؛ ۱۰۰g توت‌فرنگی.' note
    UNION ALL SELECT 'INV-021' code,100 qty,'estimated' qstatus,'توت‌فرنگی منجمد: 100 گرم — منبع Bar Costing V3؛ ۱۰۰g توت‌فرنگی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1112' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1101 — شیک ارده
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1101'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰g ارده.' note
    UNION ALL SELECT 'INV-095' code,30 qty,'estimated' qstatus,'ارده: 30 گرم — منبع Bar Costing V3؛ ۳۰g ارده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1101' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1102 — شیک ارده اسپرسو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1102'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰g ارده + دبل‌شات.' note
    UNION ALL SELECT 'INV-095' code,30 qty,'estimated' qstatus,'ارده: 30 گرم — منبع Bar Costing V3؛ ۳۰g ارده + دبل‌شات.' note
    UNION ALL SELECT 'INV-001' code,18 qty,'estimated' qstatus,'قهوه: 18 گرم — منبع Bar Costing V3؛ ۳۰g ارده + دبل‌شات.' note
    UNION ALL SELECT 'INV-100' code,2 qty,'known' qstatus,'شکلات کنار قهوه: 2 عدد — منبع Bar Costing V3؛ طبق اعلام کاربر: ۱ شکلات برای هر شات. تعداد شکلات قطعی؛ وزن هر عدد فعلاً 7g فرض شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1102' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1104 — شیک لوتوس
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1104'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰g لوتوس؛ وزن ظرف فرضی.' note
    UNION ALL SELECT 'INV-096' code,30 qty,'estimated' qstatus,'کرم لوتوس: 30 گرم — منبع Bar Costing V3؛ ۳۰g لوتوس؛ وزن ظرف فرضی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1104' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1107 — شیک پینات
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1107'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰g کره بادام‌زمینی.' note
    UNION ALL SELECT 'INV-058' code,30 qty,'estimated' qstatus,'کره بادام‌زمینی: 30 گرم — منبع Bar Costing V3؛ ۳۰g کره بادام‌زمینی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1107' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1108 — شیک نسکافه
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1108'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۶g نسکافه.' note
    UNION ALL SELECT 'INV-097' code,6 qty,'estimated' qstatus,'نسکافه: 6 گرم — منبع Bar Costing V3؛ ۶g نسکافه.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1108' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1106 — شیک فیمتو
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','بستنی وانیلی: رسپی بر حسب ml است ولی موجودی بستنی بر حسب g؛ تا اندازه‌گیری تبدیل، کسر خودکار حذف شده است.',NULL
FROM items m WHERE m.item_code='1106'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-003' code,150 qty,'estimated' qstatus,'شیر پرچرب: 150 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰ml فیمتو.' note
    UNION ALL SELECT 'INV-062' code,30 qty,'estimated' qstatus,'فیمتو: 30 میلی‌لیتر — منبع Bar Costing V3؛ ۳۰ml فیمتو.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1106' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1110 — اسموتی انبه
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1110'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-020' code,180 qty,'estimated' qstatus,'انبه منجمد: 180 گرم — منبع Bar Costing V3؛ ۱۸۰g انبه + ۵۰ml نکتار.' note
    UNION ALL SELECT 'INV-098' code,50 qty,'estimated' qstatus,'نکتار انبه: 50 میلی‌لیتر — منبع Bar Costing V3؛ ۱۸۰g انبه + ۵۰ml نکتار.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1110' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 1111 — اسموتی منگو بری
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','یخ و آب: یخ و آب به‌صورت مصرف انباری مستقل مدیریت نمی‌شود.',NULL
FROM items m WHERE m.item_code='1111'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-020' code,120 qty,'estimated' qstatus,'انبه منجمد: 120 گرم — منبع Bar Costing V3؛ ۱۲۰g انبه + ۸۰g توت‌فرنگی.' note
    UNION ALL SELECT 'INV-021' code,80 qty,'estimated' qstatus,'توت‌فرنگی منجمد: 80 گرم — منبع Bar Costing V3؛ ۱۲۰g انبه + ۸۰g توت‌فرنگی.' note
    UNION ALL SELECT 'INV-098' code,50 qty,'estimated' qstatus,'نکتار انبه: 50 میلی‌لیتر — منبع Bar Costing V3؛ ۱۲۰g انبه + ۸۰g توت‌فرنگی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='1111' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 801 — چای ساده - کوچک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.',NULL
FROM items m WHERE m.item_code='801'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,5 qty,'estimated' qstatus,'چای: 5 گرم — منبع Bar Costing V3؛ ۵g چای.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='801' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 802 — چای ساده - بزرگ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده بزرگ: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.',NULL
FROM items m WHERE m.item_code='802'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,10 qty,'estimated' qstatus,'چای: 10 گرم — منبع Bar Costing V3؛ ۱۰g چای.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='802' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 803 — چای مراکش - کوچک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ گارنیش: گارنیش تجمیعی به ماده انبار مشخص نگاشت نشده است.',NULL
FROM items m WHERE m.item_code='803'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,5 qty,'estimated' qstatus,'چای: 5 گرم — منبع Bar Costing V3؛ چای + نعنا/گارنیش.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='803' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 804 — چای مراکش - بزرگ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده بزرگ: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ گارنیش: گارنیش تجمیعی به ماده انبار مشخص نگاشت نشده است.',NULL
FROM items m WHERE m.item_code='804'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,10 qty,'estimated' qstatus,'چای: 10 گرم — منبع Bar Costing V3؛ چای + نعنا/گارنیش.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='804' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 807 — چای زعفران - کوچک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ زعفران کوچک: زعفران به‌صورت سهمی ثبت شده و وزن مصرف باید اندازه‌گیری شود.',NULL
FROM items m WHERE m.item_code='807'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,5 qty,'estimated' qstatus,'چای: 5 گرم — منبع Bar Costing V3؛ زعفران خرید مستقیم ندارد.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='807' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 808 — چای زعفران - بزرگ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده بزرگ: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ زعفران بزرگ: زعفران به‌صورت سهمی ثبت شده و وزن مصرف باید اندازه‌گیری شود.',NULL
FROM items m WHERE m.item_code='808'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,10 qty,'estimated' qstatus,'چای: 10 گرم — منبع Bar Costing V3؛ زعفران خرید مستقیم ندارد.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='808' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 805 — چای هل و دارچین - کوچک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده کوچک: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ هل/دارچین کوچک: هل/دارچین به‌صورت سهمی ثبت شده و مواد/وزن باید جدا شود.',NULL
FROM items m WHERE m.item_code='805'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,5 qty,'estimated' qstatus,'چای: 5 گرم — منبع Bar Costing V3؛ ادویه برآوردی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='805' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 806 — چای هل و دارچین - بزرگ
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','partial','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','شیرین‌کننده بزرگ: شیرین‌کننده سهمی به ماده انبار قطعی نگاشت نشده است.؛ هل/دارچین بزرگ: هل/دارچین به‌صورت سهمی ثبت شده و مواد/وزن باید جدا شود.',NULL
FROM items m WHERE m.item_code='806'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-099' code,10 qty,'estimated' qstatus,'چای: 10 گرم — منبع Bar Costing V3؛ ادویه برآوردی.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='806' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 205 — آب معدنی کوچک
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','complete','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','همه اجزای ثبت‌شده در منبع به inventory master نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='205'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-073' code,1 qty,'known' qstatus,'آب معدنی کوچک: 1 عدد — منبع Bar Costing V3؛ هزینه واحد؛ برای موارد فاقد خرید مستقیم مشابه‌سازی شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='205' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 207 — آب گاز دار لیمویی
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','complete','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','همه اجزای ثبت‌شده در منبع به inventory master نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='207'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-072' code,1 qty,'known' qstatus,'آب گازدار لیمویی: 1 عدد — منبع Bar Costing V3؛ هزینه واحد؛ برای موارد فاقد خرید مستقیم مشابه‌سازی شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='207' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

-- 203 — نوشابه شانی قوطی
INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT m.id,COALESCE((SELECT MAX(v2.version_no) FROM inventory_recipe_versions v2 WHERE v2.menu_item_id=m.id),0)+1,
       'active','needs_review','complete','sokna_bar_costing_v3_1405_06_16_to_1405_07_16','رسپی اولیه بار از Bar Costing V3 وارد شده و تا بازبینی عملیاتی قطعی نیست.','همه اجزای ثبت‌شده در منبع به inventory master نگاشت شده‌اند.',NULL
FROM items m WHERE m.item_code='203'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=m.id AND v.status='active');
INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT rv.id,ii.id,x.qty,x.qstatus,x.note
FROM inventory_recipe_versions rv JOIN items m ON m.id=rv.menu_item_id
JOIN (
    SELECT 'INV-075' code,1 qty,'known' qstatus,'شانی قوطی: 1 عدد — منبع Bar Costing V3؛ هزینه واحد؛ برای موارد فاقد خرید مستقیم مشابه‌سازی شده.' note
) x JOIN inventory_items ii ON ii.item_code=x.code
WHERE m.item_code='203' AND rv.status='active' AND rv.source_ref='sokna_bar_costing_v3_1405_06_16_to_1405_07_16'
  AND NOT EXISTS (SELECT 1 FROM inventory_recipe_components c WHERE c.recipe_version_id=rv.id AND c.inventory_item_id=ii.id);

