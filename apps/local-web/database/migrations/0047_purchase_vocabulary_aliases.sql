-- H207 — Purchase vocabulary aliases and safe masterdata additions.
-- Source: Sokna_Cafe_Purchases_CORRECTED_1405-06-16_to_1405-07-16.xlsx
-- Policy:
--   * aliases improve discovery only; they never block free-form supply work.
--   * ambiguous purchase terms are deliberately NOT auto-merged.
--   * no inventory balance, movement, purchase receipt, or cost is fabricated.

CREATE TABLE IF NOT EXISTS inventory_item_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    alias VARCHAR(160) NOT NULL,
    normalized_alias VARCHAR(160) NOT NULL,
    source_ref VARCHAR(190) NULL,
    review_status VARCHAR(20) NOT NULL DEFAULT 'ready',
    note VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_h207_inventory_alias_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_h207_inventory_alias_review CHECK (review_status IN ('ready','needs_review')),
    UNIQUE KEY uq_h207_inventory_alias_normalized (normalized_alias),
    INDEX idx_h207_inventory_alias_item (inventory_item_id,active),
    INDEX idx_h207_inventory_alias_active (active,alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- INV-101 — تخم مرغ
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-101','تخم مرغ','ingredient','count','kitchen',0,'ready','خریدهای متعدد با تعداد واقعی ثبت شده‌اند.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-101' OR name='تخم مرغ');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شانه','actual_quantity',NULL,'needs_review','تعداد واقعی تخم‌مرغ هر شانه هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-101'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='شانه');


-- INV-102 — فلفل سیاه
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-102','فلفل سیاه','ingredient','g','kitchen',0,'ready','خرید وزنی در داده واقعی کافه ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-102' OR name='فلفل سیاه');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-102'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-103 — پودر سیر
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-103','پودر سیر','ingredient','g','kitchen',0,'ready','خرید وزنی در داده واقعی کافه ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-103' OR name='پودر سیر');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-103'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-104 — نمک
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-104','نمک','ingredient','g','kitchen',0,'ready','خرید بسته‌ای ثبت شده؛ وزن بسته باید هنگام دریافت واقعی ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-104' OR name='نمک');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-104'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-105 — زیره
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-105','زیره','ingredient','g','kitchen',0,'ready','خرید وزنی در داده واقعی کافه ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-105' OR name='زیره');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-105'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-106 — عسل
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-106','عسل','ingredient','g','shared',0,'needs_review','کالا در خرید واقعی ثبت شده ولی وزن ظرف در داده فعلی قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-106' OR name='عسل');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی ظرف هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-106'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-107 — گردو
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-107','گردو','ingredient','g','shared',0,'ready','خرید وزنی در داده واقعی کافه ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-107' OR name='گردو');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready','هر کیلوگرم برابر ۱۰۰۰ گرم.',1
FROM inventory_items i WHERE i.item_code='INV-107'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کیلوگرم');


-- INV-108 — جو دوسر پرک
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-108','جو دوسر پرک','ingredient','g','bar',0,'needs_review','کالا در خرید واقعی ثبت شده؛ وزن بسته باید تأیید شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-108' OR name='جو دوسر پرک');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-108'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-109 — کنسرو نخود
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-109','کنسرو نخود','ingredient','g','kitchen',0,'needs_review','تعداد قوطی در خرید واقعی ثبت شده ولی وزن خالص/آبکش‌شده برای مصرف هنوز قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-109' OR name='کنسرو نخود');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'قوطی','actual_quantity',NULL,'needs_review','وزن مصرفی واقعی هر قوطی هنگام دریافت/بازبینی ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-109'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='قوطی');


-- INV-110 — نان چاپاتا
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-110','نان چاپاتا','ingredient','count','kitchen',0,'ready','خرید نان چاپاتا در داده واقعی کافه ثبت شده و با آیتم منوی چاپاتای مرغ مرتبط است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-110' OR name='نان چاپاتا');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready','هر عدد برابر یک نان.',1
FROM inventory_items i WHERE i.item_code='INV-110'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='عدد');


-- INV-111 — نان پیتا
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-111','نان پیتا','ingredient','count','kitchen',0,'needs_review','نان پیتا/لبنانی در خرید واقعی ثبت شده؛ هویت تأمین‌کننده نیازمند بازبینی است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-111' OR name='نان پیتا');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready','هر عدد برابر یک نان.',1
FROM inventory_items i WHERE i.item_code='INV-111'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='عدد');


-- INV-112 — پودر سوخاری پانکو سفید
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-112','پودر سوخاری پانکو سفید','ingredient','g','kitchen',0,'needs_review','خرید واقعی ثبت شده ولی وزن هر بسته در داده فعلی قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-112' OR name='پودر سوخاری پانکو سفید');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-112'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-113 — پودر سوخاری پانکو پاپریکا
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-113','پودر سوخاری پانکو پاپریکا','ingredient','g','kitchen',0,'needs_review','خرید واقعی ثبت شده ولی وزن هر بسته در داده فعلی قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-113' OR name='پودر سوخاری پانکو پاپریکا');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','actual_quantity',NULL,'needs_review','وزن واقعی بسته هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-113'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته');


-- INV-114 — دوغ آپادا
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-114','دوغ آپادا','ready_drink','count','shared',0,'ready','خرید شل دوغ آپادا در داده واقعی کافه ثبت شده است.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-114' OR name='دوغ آپادا');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شل','actual_quantity',NULL,'needs_review','تعداد بطری واقعی هر شل هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-114'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='شل');


-- INV-115 — شربت گلسرخ
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-115','شربت گلسرخ','ingredient','ml','bar',0,'needs_review','خرید واقعی ثبت شده ولی حجم بطری در داده فعلی قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-115' OR name='شربت گلسرخ');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بطری','actual_quantity',NULL,'needs_review','حجم واقعی بطری هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-115'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بطری');


-- INV-116 — شربت بهار نارنج
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-116','شربت بهار نارنج','ingredient','ml','bar',0,'needs_review','خرید واقعی ثبت شده ولی حجم بطری در داده فعلی قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-116' OR name='شربت بهار نارنج');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بطری','actual_quantity',NULL,'needs_review','حجم واقعی بطری هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-116'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بطری');


-- INV-117 — سیروپ نعنا سن ایچ
INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-117','سیروپ نعنا سن ایچ','ingredient','ml','bar',0,'needs_review','خرید واقعی ثبت شده و از سیروپ موهیتو مستقل نگه داشته می‌شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-117' OR name='سیروپ نعنا سن ایچ');
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بطری','actual_quantity',NULL,'needs_review','حجم واقعی بطری هنگام دریافت ثبت شود.',1
FROM inventory_items i WHERE i.item_code='INV-117'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بطری');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'هندونه','هندونه','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-033'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='هندونه');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'استیک مرغ','استیک مرغ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-025'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='استیک مرغ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'فیله مرغ','فیله مرغ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-025'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='فیله مرغ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'برگر گوشت','برگر گوشت','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-028'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='برگر گوشت');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پنیر وییلی','پنیر وییلی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-015'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پنیر وییلی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پنیری ویلی','پنیری ویلی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-015'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پنیری ویلی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پودر پنیر پارمسان','پودر پنیر پارمسان','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-019'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پودر پنیر پارمسان');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پودر پنیر پارمیسان','پودر پنیر پارمیسان','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-019'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پودر پنیر پارمیسان');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'شیرپرچرب دومینو','شیرپرچرب دومینو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','تفاوت فاصله/نیم‌فاصله',1
FROM inventory_items i WHERE i.item_code='INV-003'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='شیرپرچرب دومینو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'شیر پرچرب دومینو','شیر پرچرب دومینو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-003'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='شیر پرچرب دومینو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'خامه بزرگ','خامه بزرگ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-005'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='خامه بزرگ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'خامه ۱ لیتری','خامه ۱ لیتری','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-005'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='خامه ۱ لیتری');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'خامه یک لیتری','خامه یک لیتری','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-005'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='خامه یک لیتری');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'بستنی وانیل','بستنی وانیل','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-007'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='بستنی وانیل');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'انبه یخ زده','انبه یخ زده','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-020'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='انبه یخ زده');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'توت فرنگی یخ زده','توت فرنگی یخ زده','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-021'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='توت فرنگی یخ زده');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیب زمینی','سیب زمینی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','تفاوت فاصله/نیم‌فاصله',1
FROM inventory_items i WHERE i.item_code='INV-009'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیب زمینی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سس کچاب','سس کچاب','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-010'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سس کچاب');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'کچاپ','کچاپ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-010'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='کچاپ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سس کچاپ سطلی','سس کچاپ سطلی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-010'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سس کچاپ سطلی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سس مایونز','سس مایونز','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-011'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سس مایونز');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سس خردل','سس خردل','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-012'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سس خردل');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیروپ بلو کارسانو','سیروپ بلو کارسانو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-059'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیروپ بلو کارسانو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیروپ بلوکارسانو','سیروپ بلوکارسانو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-059'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیروپ بلوکارسانو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'بلو کارسانو','بلو کارسانو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-059'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='بلو کارسانو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'اب معدنی کوچک','اب معدنی کوچک','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-073'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='اب معدنی کوچک');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'نوشابه قوطی اسپرایت','نوشابه قوطی اسپرایت','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-076'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='نوشابه قوطی اسپرایت');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'اسپرایت ایرانی','اسپرایت ایرانی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-076'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='اسپرایت ایرانی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پاستا پنه بزرگ','پاستا پنه بزرگ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-067'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پاستا پنه بزرگ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'ماکارونی مانا پنه بزرگ','ماکارونی مانا پنه بزرگ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-067'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='ماکارونی مانا پنه بزرگ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'روغن سرخ کردنی اویلا','روغن سرخ کردنی اویلا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-052'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='روغن سرخ کردنی اویلا');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'روغن سرخ کردنی','روغن سرخ کردنی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-052'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='روغن سرخ کردنی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پنیر پیتزا مارگاریتا','پنیر پیتزا مارگاریتا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-018'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پنیر پیتزا مارگاریتا');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'کنسرو ذرت','کنسرو ذرت','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-045'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='کنسرو ذرت');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیروپ سن ایچ توت فرنگی','سیروپ سن ایچ توت فرنگی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-093'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیروپ سن ایچ توت فرنگی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیروپ توت فرنگی سن ایچ','سیروپ توت فرنگی سن ایچ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-093'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیروپ توت فرنگی سن ایچ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'سیروپ توت فرنگی سم ایچ','سیروپ توت فرنگی سم ایچ','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-093'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='سیروپ توت فرنگی سم ایچ');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'چای سوفیا','چای سوفیا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-099'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='چای سوفیا');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'آبلیمو','آبلیمو','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-094'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='آبلیمو');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'شربت گلاب زعفرون','شربت گلاب زعفرون','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-063'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='شربت گلاب زعفرون');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'زیتون اسلایس سبز','زیتون اسلایس سبز','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-047'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='زیتون اسلایس سبز');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'زیتون سیاه اسلایس','زیتون سیاه اسلایس','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-046'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='زیتون سیاه اسلایس');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'نون چاپاتا','نون چاپاتا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-110'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='نون چاپاتا');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'نان لبنانی','نان لبنانی','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','عنوان رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-111'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='نان لبنانی');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'دوغ اپادا','دوغ اپادا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','املای رایج در خریدهای واقعی',1
FROM inventory_items i WHERE i.item_code='INV-114'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='دوغ اپادا');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پانکو سفید','پانکو سفید','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-112'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پانکو سفید');

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT i.id,'پانکو پاپریکا','پانکو پاپریکا','sokna_cafe_purchases_corrected_1405_06_16_to_1405_07_16','ready','نام کوتاه رایج',1
FROM inventory_items i WHERE i.item_code='INV-113'
  AND NOT EXISTS (SELECT 1 FROM inventory_item_aliases a WHERE a.normalized_alias='پانکو پاپریکا');
