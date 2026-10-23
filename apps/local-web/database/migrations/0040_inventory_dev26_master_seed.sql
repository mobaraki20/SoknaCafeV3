-- H188 working — restore the canonical Dev26 inventory master catalog into V3.
-- Provenance: Sokna-CLEAN-INSTALL-1.36.4-dev.26(1).zip
-- Archive SHA-256: 1a1d0723edb5cf2ebdb6e7f37925b08ca729d82ceb283089183fbca61439e4dc
-- Source: database/inventory_seed.json
-- Seed SHA-256: f6a308e9fbb591d84e9fac5be8d214481d1cba70bfddc1ed72163339f1956677
-- Canonical rows: 89 inventory items / 64 purchase units.
--
-- Merge policy:
-- * never overwrite an existing inventory item; exact item_code OR exact name is treated as an existing master row;
-- * insert only missing canonical items;
-- * purchase units are added only to a row whose canonical code/name/category/base-unit/department match,
--   and only when that unit name does not already exist for the item;
-- * no stock balance, movement or cost row is fabricated by this migration.

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-001','قهوه','ingredient','g','bar',0,'ready','خرید وزنی؛ در سابقه خرید ۱۰ کیلو ثبت شده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-001' OR name='قهوه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-002','شیر پرچرب کاله ۱ لیتری','ingredient','ml','kitchen',0,'ready','بخش پیش‌فرض طبق تصمیم مالک؛ کارتن ۱۲ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-002' OR name='شیر پرچرب کاله ۱ لیتری');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-003','شیر پرچرب دومینو ۱ لیتری','ingredient','ml','bar',0,'ready','بخش پیش‌فرض طبق تصمیم مالک؛ خرید ۱۰ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-003' OR name='شیر پرچرب دومینو ۱ لیتری');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-004','شیر نارگیل کوچک','ingredient','ml','bar',0,'needs_review','حجم هر عدد در منبع مشخص نیست؛ خرید ۶ عددی ثبت شده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-004' OR name='شیر نارگیل کوچک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-005','خامه کاله ۱ لیتری','ingredient','ml','shared',0,'ready','کارتن ۱۲ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-005' OR name='خامه کاله ۱ لیتری');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-006','خامه کوچک کاله','ingredient','ml','bar',0,'needs_review','سایز کوچک در گفتگو آمده ولی حجم دقیق ثبت نشده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-006' OR name='خامه کوچک کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-007','بستنی وانیلی کاله','ingredient','g','bar',0,'ready','هر کارتن ۲ بن‌ماری؛ وزن بن‌ماری/کارتن متغیر و باید هنگام ورود ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-007' OR name='بستنی وانیلی کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-008','بستنی شکلاتی کاله','ingredient','g','bar',0,'ready','هر کارتن ۲ بن‌ماری؛ وزن بن‌ماری/کارتن متغیر و باید هنگام ورود ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-008' OR name='بستنی شکلاتی کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-009','سیب‌زمینی کاله','ingredient','g','kitchen',0,'ready','هر کارتن ۴ بسته ۲.۵ کیلویی = ۱۰ کیلو؛ تأیید مالک.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-009' OR name='سیب‌زمینی کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-010','سس کچاپ کاله','ingredient','g','kitchen',0,'ready','دبه/سطل ۸ و ۹ کیلویی در سوابق دیده می‌شود؛ هر دو روش خرید نگه داشته شوند.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-010' OR name='سس کچاپ کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-011','سس مایونز کم‌چرب','ingredient','g','kitchen',0,'ready','دبه/سطل ۸ کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-011' OR name='سس مایونز کم‌چرب');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-012','سس خردل کاله','ingredient','g','kitchen',0,'ready','دبه ۸ کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-012' OR name='سس خردل کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-013','کچاپ تک‌نفره','packaging','count','kitchen',0,'ready','کارتن ۳۰۰ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-013' OR name='کچاپ تک‌نفره');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-014','سس شکلات فرمند بزرگ','ingredient','ml','bar',0,'needs_review','حجم ظرف در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-014' OR name='سس شکلات فرمند بزرگ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-015','پنیر ویلی','ingredient','g','kitchen',0,'ready','هر عدد/بسته ۱ کیلو در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-015' OR name='پنیر ویلی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-016','دیپ چدار','ingredient','g','kitchen',0,'ready','هر عدد ۱ کیلو در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-016' OR name='دیپ چدار');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-017','پنیر پیتزا کاله','ingredient','g','kitchen',0,'ready','بسته ۲ کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-017' OR name='پنیر پیتزا کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-018','پنیر مارگاریتا','ingredient','g','kitchen',0,'ready','بسته ۲ کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-018' OR name='پنیر مارگاریتا');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-019','پنیر پارمسان','ingredient','g','kitchen',0,'needs_review','تعداد خرید مشخص است ولی وزن هر عدد در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-019' OR name='پنیر پارمسان');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-020','انبه منجمد','ingredient','g','bar',0,'ready','هم بسته ۲.۵ کیلویی و هم بن‌ماری/وزن متغیر در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-020' OR name='انبه منجمد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-021','توت‌فرنگی منجمد','ingredient','g','bar',0,'ready','بسته ۲.۵ کیلویی و خرید کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-021' OR name='توت‌فرنگی منجمد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-022','شاه‌توت منجمد','ingredient','g','bar',0,'ready','بسته ۲.۵ کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-022' OR name='شاه‌توت منجمد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-023','آلبالو بدون هسته منجمد','ingredient','g','bar',0,'ready','بسته ۲.۵ کیلویی و قیمت کیلویی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-023' OR name='آلبالو بدون هسته منجمد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-024','میوه خشک انبه','ingredient','g','bar',0,'needs_review','وزن بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-024' OR name='میوه خشک انبه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-025','سینه مرغ بدون استخوان','ingredient','g','kitchen',0,'ready','خرید وزنی با وزن دقیق/کیلویی در سوابق متعدد.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-025' OR name='سینه مرغ بدون استخوان');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-026','مرغ کامل','ingredient','g','kitchen',0,'needs_review','در سوابق به صورت «مرغ» و «مرغ کامل» آمده؛ وزن خرید باید ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-026' OR name='مرغ کامل');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-027','مرغ هشت‌تکه','ingredient','g','kitchen',0,'needs_review','در سابقه خرید با عنوان مستقل آمده؛ وزن/تعداد بسته مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-027' OR name='مرغ هشت‌تکه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-028','برگر گوشت ۸۵٪','ingredient','count','kitchen',0,'ready','خرید تعدادی و بسته ۵ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-028' OR name='برگر گوشت ۸۵٪');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-029','برگر مرغ','ingredient','count','kitchen',0,'ready','خرید تعدادی؛ ۱۵ عددی و بسته‌ای در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-029' OR name='برگر مرغ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-030','قارچ','ingredient','g','kitchen',0,'ready','خرید کیلویی؛ یک رخداد خرابی ۳ بسته نیز در سابقه ثبت شده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-030' OR name='قارچ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-031','لیمو','ingredient','g','shared',0,'ready','خرید وزنی؛ هم برای بار و هم آشپزخانه قابل استفاده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-031' OR name='لیمو');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-032','موز','ingredient','g','bar',0,'ready','خرید وزنی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-032' OR name='موز');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-033','هندوانه','ingredient','g','bar',0,'ready','خرید وزنی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-033' OR name='هندوانه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-034','طالبی','ingredient','g','bar',0,'ready','خرید با مبلغ/وزن متغیر؛ مقدار دقیق باید هنگام ورود ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-034' OR name='طالبی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-035','کاهو پیچ','ingredient','g','kitchen',0,'ready','در سوابق هم عددی و هم وزنی آمده؛ موجودی پایه وزنی نگه داشته شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-035' OR name='کاهو پیچ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-036','نعنا','ingredient','g','shared',0,'ready','مشترک بین بار و آشپزخانه طبق تصمیم مالک؛ خرید وزنی.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-036' OR name='نعنا');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-037','جعفری','ingredient','g','kitchen',0,'needs_review','خرید مبلغی ثبت شده؛ وزن خرید باید هنگام ورود ثبت شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-037' OR name='جعفری');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-038','تربچه','ingredient','g','kitchen',0,'needs_review','مقدار دقیق در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-038' OR name='تربچه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-039','گوجه','ingredient','g','kitchen',0,'ready','خرید وزنی با گرم در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-039' OR name='گوجه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-040','گوجه گیلاسی','ingredient','g','kitchen',0,'needs_review','در سابقه «۵ بسته» آمده؛ وزن هر بسته مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-040' OR name='گوجه گیلاسی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-041','خیار سبز','ingredient','g','kitchen',0,'needs_review','مقدار خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-041' OR name='خیار سبز');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-042','خیارشور','ingredient','g','kitchen',0,'needs_review','نوع/وزن ظرف در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-042' OR name='خیارشور');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-043','پیاز','ingredient','g','kitchen',0,'needs_review','مقدار خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-043' OR name='پیاز');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-044','بیبی کورن','ingredient','count','kitchen',0,'needs_review','۲ عدد در سابقه؛ اندازه/وزن بسته نیازمند بازبینی.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-044' OR name='بیبی کورن');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-045','ذرت کنسرو سحر','ingredient','count','kitchen',0,'needs_review','تعداد خرید/قیمت واحد مشخص؛ وزن کنسرو در منبع نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-045' OR name='ذرت کنسرو سحر');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-046','زیتون سیاه','ingredient','g','kitchen',0,'needs_review','خرید قوطی؛ وزن قوطی در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-046' OR name='زیتون سیاه');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-047','زیتون سبز اسلایسی','ingredient','g','kitchen',0,'needs_review','خرید یک قوطی؛ وزن قوطی در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-047' OR name='زیتون سبز اسلایسی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-048','کشمش','ingredient','g','kitchen',0,'ready','خرید ۱ کیلو در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-048' OR name='کشمش');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-049','ماست سون متوسط','ingredient','g','kitchen',0,'needs_review','سایز «متوسط» آمده؛ وزن دقیق مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-049' OR name='ماست سون متوسط');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-050','سرکه بالزامیک وردا ۵۰۰ میلی‌لیتری','ingredient','ml','kitchen',0,'ready','بطری ۵۰۰ میلی‌لیتری در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-050' OR name='سرکه بالزامیک وردا ۵۰۰ میلی‌لیتری');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-051','رب انار مهرام','ingredient','g','kitchen',0,'needs_review','۳ عدد خرید ثبت شده؛ وزن هر عدد مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-051' OR name='رب انار مهرام');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-052','روغن اویلا ۱۸۰۰ گرمی','ingredient','g','kitchen',0,'ready','هر عدد ۱۸۰۰ گرم؛ خرید ۶ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-052' OR name='روغن اویلا ۱۸۰۰ گرمی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-053','روغن ویژه کاله','ingredient','g','kitchen',0,'needs_review','خرید به صورت «حلب» ثبت شده؛ وزن حلب مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-053' OR name='روغن ویژه کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-054','ادویه پودر کچاپ','ingredient','g','kitchen',0,'needs_review','مقدار/بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-054' OR name='ادویه پودر کچاپ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-055','گل محمدی','ingredient','g','bar',0,'needs_review','مقدار خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-055' OR name='گل محمدی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-056','آویشن خشک','ingredient','g','kitchen',0,'needs_review','مقدار خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-056' OR name='آویشن خشک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-057','سبزی خشک','ingredient','g','kitchen',0,'needs_review','مقدار خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-057' OR name='سبزی خشک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-058','کره بادام‌زمینی خانگی','ingredient','g','bar',0,'needs_review','وزن ظرف/خرید در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-058' OR name='کره بادام‌زمینی خانگی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-059','سیروپ بلو کاراسائو','ingredient','ml','bar',0,'needs_review','حجم بطری در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-059' OR name='سیروپ بلو کاراسائو');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-060','سیروپ موهیتو','ingredient','ml','bar',0,'needs_review','در خرید تجمیعی با سیروپ لیموناد آمده؛ تعداد/حجم مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-060' OR name='سیروپ موهیتو');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-061','سیروپ لیموناد','ingredient','ml','bar',0,'needs_review','در خرید تجمیعی با سیروپ موهیتو آمده؛ تعداد/حجم مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-061' OR name='سیروپ لیموناد');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-062','شربت فیمتو','ingredient','ml','bar',0,'needs_review','هم مبلغ خرید و هم یک کارتن ثبت شده؛ تعداد/حجم کارتن مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-062' OR name='شربت فیمتو');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-063','شربت گلاب زعفران','ingredient','ml','bar',0,'needs_review','۳ عدد ثبت شده؛ حجم هر عدد مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-063' OR name='شربت گلاب زعفران');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-064','عرق نسترن','ingredient','ml','bar',0,'needs_review','حجم ظرف در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-064' OR name='عرق نسترن');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-065','کمپوت آناناس','ingredient','g','bar',0,'needs_review','تعداد/وزن قوطی در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-065' OR name='کمپوت آناناس');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-066','آبمیوه آناناس سن‌ایچ','ingredient','ml','bar',0,'needs_review','حجم بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-066' OR name='آبمیوه آناناس سن‌ایچ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-067','پنه بزرگ مانا','ingredient','g','kitchen',0,'needs_review','کارتن ۲۰ عددی ثبت شده؛ وزن هر بسته مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-067' OR name='پنه بزرگ مانا');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-068','نان برگر سحر','ingredient','count','kitchen',0,'needs_review','در سابقه هم بسته ۳تایی و هم کارتن ۱۲ بسته دوتایی آمده؛ هر دو به عنوان واحد خرید موقت ثبت شده‌اند.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-068' OR name='نان برگر سحر');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-069','نان برگر تک‌نان','ingredient','count','kitchen',0,'needs_review','برند طبق توضیح مالک؛ تعداد داخل بسته هنوز قطعی نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-069' OR name='نان برگر تک‌نان');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-070','نان تست سحر','ingredient','count','kitchen',0,'needs_review','خرید ۲ بسته ثبت شده؛ تعداد برش/عدد داخل بسته مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-070' OR name='نان تست سحر');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-071','نان تست تک‌نان','ingredient','count','kitchen',0,'needs_review','۴ بسته در سابقه؛ تعداد داخل هر بسته مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-071' OR name='نان تست تک‌نان');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-072','آب گازدار لیمویی کاله','ready_drink','count','bar',0,'ready','شل ۱۵ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-072' OR name='آب گازدار لیمویی کاله');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-073','آب معدنی کوچک','ready_drink','count','shared',0,'needs_review','تعداد بسته موقتاً ۱۲ ثبت می‌شود؛ مالک بین ۱۲ و ۱۵ مطمئن نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-073' OR name='آب معدنی کوچک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-074','پپسی','ready_drink','count','bar',0,'ready','شل ۲۴ عددی؛ تأیید مالک.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-074' OR name='پپسی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-075','شانی','ready_drink','count','bar',0,'ready','شل ۲۴ عددی؛ تأیید مالک.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-075' OR name='شانی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-076','اسپرایت قوطی','ready_drink','count','bar',0,'ready','خرید ۴۸ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-076' OR name='اسپرایت قوطی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-077','دو قنبرزاده (عنوان نیازمند تأیید)','ready_drink','count','shared',0,'needs_review','عبارت منبع «دو قنبر زاده ۲ عدد» است؛ نام محصول باید توسط مالک اصلاح شود.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-077' OR name='دو قنبرزاده (عنوان نیازمند تأیید)');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-078','جک (عنوان نیازمند تأیید)','ready_drink','count','bar',0,'needs_review','در خرید عمده «آب معدنی و پپسی و جک» آمده؛ نوع دقیق محصول مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-078' OR name='جک (عنوان نیازمند تأیید)');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-079','چیزکیک ماتیلدا','ready_food','count','shared',0,'ready','بارها به صورت تعداد ثبت شده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-079' OR name='چیزکیک ماتیلدا');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-080','تیرامیسو','ready_food','count','shared',0,'ready','به صورت تعداد ثبت شده.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-080' OR name='تیرامیسو');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-081','کیک (نوع نامشخص)','ready_food','count','shared',0,'needs_review','در منبع «کیک» با مبلغ آمده؛ نوع/تعداد مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-081' OR name='کیک (نوع نامشخص)');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-082','لیوان شیک','packaging','count','bar',0,'ready','خرید ۳۰۰ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-082' OR name='لیوان شیک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-083','نی شیک','packaging','count','bar',0,'needs_review','تعداد بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-083' OR name='نی شیک');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-084','زیپ‌کیپ','packaging','count','kitchen',0,'needs_review','تعداد/سایز بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-084' OR name='زیپ‌کیپ');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-085','کیسه پلاستیکی','packaging','count','shared',0,'needs_review','تعداد بسته در منبع مشخص نیست.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-085' OR name='کیسه پلاستیکی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-086','دستکش لاتکس لارج','consumable','count','shared',0,'ready','بسته ۵۰ عددی در سابقه؛ یک خرید قدیمی لاتکس بدون تعداد هم وجود دارد.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-086' OR name='دستکش لاتکس لارج');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-087','دستکش مشکی','consumable','count','shared',0,'ready','بسته ۱۰۰ عددی در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-087' OR name='دستکش مشکی');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-088','عود یاسمین','consumable','count','shared',0,'needs_review','کالای مصرفی محیطی؛ یک خرید در سابقه.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-088' OR name='عود یاسمین');

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'INV-089','باریستا (عنوان نیازمند تأیید)','ingredient','g','bar',0,'needs_review','در منبع «۱ ظرف باریستا» آمده؛ محصول دقیق و واحد پایه نیازمند اصلاح.',1,NULL
WHERE NOT EXISTS (SELECT 1 FROM inventory_items WHERE item_code='INV-089' OR name='باریستا (عنوان نیازمند تأیید)');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-001' AND i.name='قهوه' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد ۱ لیتری','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-002' AND i.name='شیر پرچرب کاله ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد ۱ لیتری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۱۲ عددی','fixed',12000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-002' AND i.name='شیر پرچرب کاله ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۱۲ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد ۱ لیتری','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-003' AND i.name='شیر پرچرب دومینو ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد ۱ لیتری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن / بسته','fixed',NULL,'needs_review','تعداد داخل کارتن/بسته قطعی نیست؛ خرید ۱۰ عددی در سابقه.',1
FROM inventory_items i
WHERE i.item_code='INV-003' AND i.name='شیر پرچرب دومینو ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن / بسته');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',NULL,'needs_review','حجم هر عدد نامشخص.',1
FROM inventory_items i
WHERE i.item_code='INV-004' AND i.name='شیر نارگیل کوچک' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد ۱ لیتری','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-005' AND i.name='خامه کاله ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد ۱ لیتری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۱۲ عددی','fixed',12000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-005' AND i.name='خامه کاله ۱ لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۱۲ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته / عدد','fixed',NULL,'needs_review','حجم سایز کوچک نامشخص.',1
FROM inventory_items i
WHERE i.item_code='INV-006' AND i.name='خامه کوچک کاله' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته / عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بن‌ماری','actual_quantity',NULL,'ready','وزن هر بن‌ماری متغیر.',1
FROM inventory_items i
WHERE i.item_code='INV-007' AND i.name='بستنی وانیلی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بن‌ماری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۲ بن‌ماری','actual_quantity',NULL,'ready','تعداد بن‌ماری ثابت ۲؛ وزن کل خرید باید ثبت شود.',1
FROM inventory_items i
WHERE i.item_code='INV-007' AND i.name='بستنی وانیلی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۲ بن‌ماری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بن‌ماری','actual_quantity',NULL,'ready','وزن هر بن‌ماری متغیر.',1
FROM inventory_items i
WHERE i.item_code='INV-008' AND i.name='بستنی شکلاتی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بن‌ماری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۲ بن‌ماری','actual_quantity',NULL,'ready','تعداد بن‌ماری ثابت ۲؛ وزن کل خرید باید ثبت شود.',1
FROM inventory_items i
WHERE i.item_code='INV-008' AND i.name='بستنی شکلاتی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۲ بن‌ماری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲.۵ کیلویی','fixed',2500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-009' AND i.name='سیب‌زمینی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲.۵ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۴ بسته‌ای','fixed',10000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-009' AND i.name='سیب‌زمینی کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۴ بسته‌ای');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'دبه / سطل ۸ کیلویی','fixed',8000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-010' AND i.name='سس کچاپ کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='دبه / سطل ۸ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'دبه / سطل ۹ کیلویی','fixed',9000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-010' AND i.name='سس کچاپ کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='دبه / سطل ۹ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'دبه / سطل ۸ کیلویی','fixed',8000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-011' AND i.name='سس مایونز کم‌چرب' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='دبه / سطل ۸ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'دبه ۸ کیلویی','fixed',8000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-012' AND i.name='سس خردل کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='دبه ۸ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۳۰۰ عددی','fixed',300,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-013' AND i.name='کچاپ تک‌نفره' AND i.category='packaging' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۳۰۰ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد / بسته ۱ کیلویی','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-015' AND i.name='پنیر ویلی' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد / بسته ۱ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد ۱ کیلویی','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-016' AND i.name='دیپ چدار' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد ۱ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲ کیلویی','fixed',2000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-017' AND i.name='پنیر پیتزا کاله' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲ کیلویی','fixed',2000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-018' AND i.name='پنیر مارگاریتا' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد / بسته','fixed',NULL,'needs_review','وزن هر عدد مشخص نیست.',1
FROM inventory_items i
WHERE i.item_code='INV-019' AND i.name='پنیر پارمسان' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد / بسته');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-020' AND i.name='انبه منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲.۵ کیلویی','fixed',2500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-020' AND i.name='انبه منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲.۵ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بن‌ماری / ظرف','actual_quantity',NULL,'ready','وزن متغیر؛ وزن واقعی خرید ثبت شود.',1
FROM inventory_items i
WHERE i.item_code='INV-020' AND i.name='انبه منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بن‌ماری / ظرف');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-021' AND i.name='توت‌فرنگی منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲.۵ کیلویی','fixed',2500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-021' AND i.name='توت‌فرنگی منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲.۵ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲.۵ کیلویی','fixed',2500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-022' AND i.name='شاه‌توت منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲.۵ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲.۵ کیلویی','fixed',2500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-023' AND i.name='آلبالو بدون هسته منجمد' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲.۵ کیلویی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-025' AND i.name='سینه مرغ بدون استخوان' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-028' AND i.name='برگر گوشت ۸۵٪' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۵ عددی','fixed',5,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-028' AND i.name='برگر گوشت ۸۵٪' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۵ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-029' AND i.name='برگر مرغ' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-030' AND i.name='قارچ' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-031' AND i.name='لیمو' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-032' AND i.name='موز' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-033' AND i.name='هندوانه' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-034' AND i.name='طالبی' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-035' AND i.name='کاهو پیچ' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-036' AND i.name='نعنا' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-039' AND i.name='گوجه' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'قوطی','fixed',NULL,'needs_review','وزن قوطی مشخص نیست.',1
FROM inventory_items i
WHERE i.item_code='INV-046' AND i.name='زیتون سیاه' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='قوطی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'قوطی','fixed',NULL,'needs_review','وزن قوطی مشخص نیست.',1
FROM inventory_items i
WHERE i.item_code='INV-047' AND i.name='زیتون سبز اسلایسی' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='قوطی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-048' AND i.name='کشمش' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بطری ۵۰۰ میلی‌لیتری','fixed',500,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-050' AND i.name='سرکه بالزامیک وردا ۵۰۰ میلی‌لیتری' AND i.category='ingredient' AND i.base_unit='ml' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بطری ۵۰۰ میلی‌لیتری');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد ۱۸۰۰ گرمی','fixed',1800,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-052' AND i.name='روغن اویلا ۱۸۰۰ گرمی' AND i.category='ingredient' AND i.base_unit='g' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد ۱۸۰۰ گرمی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۳ عددی','fixed',3,'needs_review','در یکی از سوابق صراحتاً بسته ۳تایی آمده.',1
FROM inventory_items i
WHERE i.item_code='INV-068' AND i.name='نان برگر سحر' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۳ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۲ عددی','fixed',2,'needs_review','در یکی از سوابق کارتن ۱۲ بسته دوتایی آمده.',1
FROM inventory_items i
WHERE i.item_code='INV-068' AND i.name='نان برگر سحر' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۲ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۱۲ بسته دوتایی','fixed',24,'needs_review',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-068' AND i.name='نان برگر سحر' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='کارتن ۱۲ بسته دوتایی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته','fixed',NULL,'needs_review','تعداد داخل بسته هنوز قطعی نیست.',1
FROM inventory_items i
WHERE i.item_code='INV-069' AND i.name='نان برگر تک‌نان' AND i.category='ingredient' AND i.base_unit='count' AND i.default_department='kitchen'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شل ۱۵ عددی','fixed',15,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-072' AND i.name='آب گازدار لیمویی کاله' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='شل ۱۵ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۱۲ عددی (موقت)','fixed',12,'needs_review','مالک بین ۱۲ و ۱۵ مطمئن نیست؛ فعلاً ۱۲ برای بازبینی وارد شده.',1
FROM inventory_items i
WHERE i.item_code='INV-073' AND i.name='آب معدنی کوچک' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۱۲ عددی (موقت)');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شل ۲۴ عددی','fixed',24,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-074' AND i.name='پپسی' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='شل ۲۴ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شل ۲۴ عددی','fixed',24,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-075' AND i.name='شانی' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='شل ۲۴ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-076' AND i.name='اسپرایت قوطی' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته / خرید ۴۸ عددی','fixed',48,'ready','در سابقه خرید ۴۸ عدد ثبت شده.',1
FROM inventory_items i
WHERE i.item_code='INV-076' AND i.name='اسپرایت قوطی' AND i.category='ready_drink' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته / خرید ۴۸ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-079' AND i.name='چیزکیک ماتیلدا' AND i.category='ready_food' AND i.base_unit='count' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-080' AND i.name='تیرامیسو' AND i.category='ready_food' AND i.base_unit='count' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۳۰۰ عددی','fixed',300,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-082' AND i.name='لیوان شیک' AND i.category='packaging' AND i.base_unit='count' AND i.default_department='bar'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۳۰۰ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۵۰ عددی','fixed',50,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-086' AND i.name='دستکش لاتکس لارج' AND i.category='consumable' AND i.base_unit='count' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۵۰ عددی');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۱۰۰ عددی','fixed',100,'ready',NULL,1
FROM inventory_items i
WHERE i.item_code='INV-087' AND i.name='دستکش مشکی' AND i.category='consumable' AND i.base_unit='count' AND i.default_department='shared'
  AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units pu WHERE pu.inventory_item_id=i.id AND pu.name='بسته ۱۰۰ عددی');
