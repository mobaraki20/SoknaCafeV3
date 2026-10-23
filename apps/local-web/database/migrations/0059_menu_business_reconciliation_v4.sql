-- SOKNA Cafe V3 H319 — Menu business reconciliation Revision 3/4
-- Forward-only upgrade from H313 semantics. Clean-install parity lives in resources/default-content/v1/catalog.json.
-- No new baklava/bundle/large-pot SKU is created here; their master-data code/price remains deferred.

-- Hard guards: missing canonical item codes must fail this migration instead of guessing by title.
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) h319_guard WHERE (SELECT COUNT(*) FROM items WHERE item_code IN ('206','1007','1008','1019','1106','1109','1110','1111','1301','1309','1313')) <> 11);
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) h319_guard WHERE (SELECT COUNT(*) FROM items WHERE item_code IN ('1113','1317','1316','1314','1315','1112','1111','1110','118','1109','1215','1214','1107','1213','1212','1211','1312','1309','1310','1305','1210','1301','1313','1203','1201','607','606','605','604','603','602','601','1001','1002')) <> 34);
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) h319_guard WHERE (SELECT COUNT(*) FROM items WHERE item_code IN ('709','707','705','703','701','903','901')) <> 7);
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) h319_guard WHERE (SELECT COUNT(*) FROM items WHERE item_code IN ('801','802','803','804','805','806','807','808','701','713','703','712','705','711','707','708','709','710')) <> 18);

-- Latest project decision supersedes Revision-2 retirement: protein shake 1113 is a newly-added active item.
INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.business_reconcile','menu_item',CAST(i.id AS CHAR),JSON_OBJECT('item_code',i.item_code,'field','lifecycle','old_lifecycle',i.lifecycle_status,'old_active',i.active,'old_available',i.available,'old_guest_visible',i.guest_visible,'old_staff_visible',i.staff_visible,'new_lifecycle','active','new_active',1,'new_available',1,'new_guest_visible',1,'new_staff_visible',1,'source','H319 latest project decision')
FROM items i WHERE i.item_code='1113' AND NOT (i.lifecycle_status='active' AND i.active=1 AND i.available=1 AND i.guest_visible=1 AND i.staff_visible=1 AND i.staff_only=0);
UPDATE items i JOIN categories c ON c.category_key='shakes-smoothies'
SET i.category_id=c.id,i.lifecycle_status='active',i.active=1,i.available=1,i.guest_visible=1,i.staff_visible=1,i.staff_only=0,i.retired_at=NULL,i.retired_note=NULL
WHERE i.item_code='1113';

-- Revision-4 one-time price sync. Existing order_items price snapshots are intentionally untouched.
INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.price_baseline_sync','menu_item',CAST(i.id AS CHAR),JSON_OBJECT('item_code',i.item_code,'old_price_toman',i.price,'new_price_toman',p.new_price,'source','SOURCE_ITEMS_1405_07_21_23_13.xlsx','source_sha256','cb15ceb59b35a45f883b26406aae41f27c1e3654b09b1c10ce1f45412bb0fcd4')
FROM items i JOIN (
SELECT '206' AS item_code,100000 AS new_price
UNION ALL
SELECT '1007',245000
UNION ALL
SELECT '1008',295000
UNION ALL
SELECT '1019',220000
UNION ALL
SELECT '1106',345000
UNION ALL
SELECT '1109',475000
UNION ALL
SELECT '1110',495000
UNION ALL
SELECT '1111',520000
UNION ALL
SELECT '1301',295000
UNION ALL
SELECT '1309',345000
UNION ALL
SELECT '1313',495000
) p ON p.item_code=i.item_code WHERE i.price<>p.new_price;
UPDATE items i JOIN (
SELECT '206' AS item_code,100000 AS new_price
UNION ALL
SELECT '1007',245000
UNION ALL
SELECT '1008',295000
UNION ALL
SELECT '1019',220000
UNION ALL
SELECT '1106',345000
UNION ALL
SELECT '1109',475000
UNION ALL
SELECT '1110',495000
UNION ALL
SELECT '1111',520000
UNION ALL
SELECT '1301',295000
UNION ALL
SELECT '1309',345000
UNION ALL
SELECT '1313',495000
) p ON p.item_code=i.item_code SET i.price=p.new_price WHERE i.price<>p.new_price;

-- Approved item descriptions. Empty/excluded source texts are absent from this set, so no blanking can occur.
INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.description_baseline_sync','menu_item',CAST(i.id AS CHAR),JSON_OBJECT('item_code',i.item_code,'old_description',COALESCE(i.description,''),'new_description',d.new_description,'source','SOURCE_MENU_DESCRIPTIONS_20261013.txt','source_sha256','50bda802641c61ef591b0272259edbd9051689097e1a7ea4df7cc5b1694debd7')
FROM items i JOIN (
SELECT '1113' AS item_code,'موز، کنجد، کره بادام‌زمینی، گردو، جو دوسر، پودر دارچین و عسل.' AS new_description
UNION ALL
SELECT '1317','۱۳۰ گرم حمص، ۱۰۰ گرم مرغ، ۱۵۰ گرم کاهو پیچ، نان پیتا، گوجه گیلاسی، خیار سبز و زیتون سیاه و سبز.'
UNION ALL
SELECT '1316','آب‌دوغ‌خیار فقط سه‌شنبه‌شب‌ها سرو می‌شود :)'
UNION ALL
SELECT '1314','برگر گوشت (۱۰۰ تا ۱۲۵ گرم)، گوجه، خیارشور، کاهو پیچ، نان برگر و سس اختصاصی برگر.'
UNION ALL
SELECT '1315','برگر مرغ (۱۰۰ تا ۱۲۰ گرم)، گوجه، خیارشور، کاهو پیچ، نان برگر و سس اختصاصی برگر.'
UNION ALL
SELECT '1112','توت‌فرنگی، بستنی وانیلی و سیروپ توت‌فرنگی.'
UNION ALL
SELECT '1111','انبه، توت‌فرنگی و آب انبه.'
UNION ALL
SELECT '1110','انبه طبیعی و آب انبه.'
UNION ALL
SELECT '118','۲۰۰ گرم مرغ، پودر سوخاری، نان برگر، گوجه، کاهو پیچ، خیارشور و سس اختصاصی زینگر.'
UNION ALL
SELECT '1109','انبه طبیعی و بستنی وانیلی.'
UNION ALL
SELECT '1215','ترکیب کیک شکلاتی تلخ، سیروپ قهوه، کرم پنیری مخصوص و گاناش شکلات.'
UNION ALL
SELECT '1214','خانگی.'
UNION ALL
SELECT '1107','کره بادام‌زمینی، بادام‌زمینی و پنیر خامه‌ای.'
UNION ALL
SELECT '1213','کوکی خانگی با تکه‌های شکلات.'
UNION ALL
SELECT '1212','خانگی.'
UNION ALL
SELECT '1211','با خامه صبحانه و شکلات ساربون.'
UNION ALL
SELECT '1312','نان تست، ۱۲۰ گرم مرغ، ۱۲۰ گرم کاهو پیچ، گوجه گیلاسی، زیتون سیاه، بیبی‌کورن، پنیر پارمسان و سس سالاد.'
UNION ALL
SELECT '1309','۸ عدد قارچ (در مجموع حدود ۱۲۰ تا ۱۵۰ گرم)، تخم‌مرغ، آرد طعم‌دار و پودر سوخاری.'
UNION ALL
SELECT '1310','۹۰ گرم مواد اسنک، نان تست، پنیر پیتزا، پنیر ویلی و سس کچاپ.'
UNION ALL
SELECT '1305','۵۰ گرم مرغ، ۵۰ گرم قارچ، ۱۰۰ گرم خامه، ۱۰۰ میلی‌لیتر شیر، ۱۰۰ گرم پنه، پنیر پارمسان، گوجه گیلاسی، جعفری خشک و زیتون سیاه.'
UNION ALL
SELECT '1210','کیک شعریه خانگی.'
UNION ALL
SELECT '1301','۲۵۰ گرم سیب‌زمینی، ادویه اختصاصی و سس کچاپ.'
UNION ALL
SELECT '1313','۲۵۰ گرم سیب‌زمینی، ۵۰ گرم قارچ، ۱۰۰ گرم خامه، ۵۰ میلی‌لیتر شیر، ¼ عصاره مرغ و ادویه اختصاصی.'
UNION ALL
SELECT '1203','کیک شکلاتی خانگی.'
UNION ALL
SELECT '1201','کیک بسبوسه خانگی.'
UNION ALL
SELECT '607','گل محمدی و تخم شربتی.'
UNION ALL
SELECT '606','به‌لیمو و تخم شربتی.'
UNION ALL
SELECT '605','بیدمشک، نسترن و خاکشیر.'
UNION ALL
SELECT '604','کاسنی، شاطره و تخم شربتی.'
UNION ALL
SELECT '603','زعفران، گلاب و خاکشیر.'
UNION ALL
SELECT '602','نعنا، خاکشیر و بهارنارنج.'
UNION ALL
SELECT '601','بهارنارنج و تخم شربتی.'
UNION ALL
SELECT '1001','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
UNION ALL
SELECT '1002','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
) d ON d.item_code=i.item_code WHERE COALESCE(i.description,'')<>d.new_description;
UPDATE items i JOIN (
SELECT '1113' AS item_code,'موز، کنجد، کره بادام‌زمینی، گردو، جو دوسر، پودر دارچین و عسل.' AS new_description
UNION ALL
SELECT '1317','۱۳۰ گرم حمص، ۱۰۰ گرم مرغ، ۱۵۰ گرم کاهو پیچ، نان پیتا، گوجه گیلاسی، خیار سبز و زیتون سیاه و سبز.'
UNION ALL
SELECT '1316','آب‌دوغ‌خیار فقط سه‌شنبه‌شب‌ها سرو می‌شود :)'
UNION ALL
SELECT '1314','برگر گوشت (۱۰۰ تا ۱۲۵ گرم)، گوجه، خیارشور، کاهو پیچ، نان برگر و سس اختصاصی برگر.'
UNION ALL
SELECT '1315','برگر مرغ (۱۰۰ تا ۱۲۰ گرم)، گوجه، خیارشور، کاهو پیچ، نان برگر و سس اختصاصی برگر.'
UNION ALL
SELECT '1112','توت‌فرنگی، بستنی وانیلی و سیروپ توت‌فرنگی.'
UNION ALL
SELECT '1111','انبه، توت‌فرنگی و آب انبه.'
UNION ALL
SELECT '1110','انبه طبیعی و آب انبه.'
UNION ALL
SELECT '118','۲۰۰ گرم مرغ، پودر سوخاری، نان برگر، گوجه، کاهو پیچ، خیارشور و سس اختصاصی زینگر.'
UNION ALL
SELECT '1109','انبه طبیعی و بستنی وانیلی.'
UNION ALL
SELECT '1215','ترکیب کیک شکلاتی تلخ، سیروپ قهوه، کرم پنیری مخصوص و گاناش شکلات.'
UNION ALL
SELECT '1214','خانگی.'
UNION ALL
SELECT '1107','کره بادام‌زمینی، بادام‌زمینی و پنیر خامه‌ای.'
UNION ALL
SELECT '1213','کوکی خانگی با تکه‌های شکلات.'
UNION ALL
SELECT '1212','خانگی.'
UNION ALL
SELECT '1211','با خامه صبحانه و شکلات ساربون.'
UNION ALL
SELECT '1312','نان تست، ۱۲۰ گرم مرغ، ۱۲۰ گرم کاهو پیچ، گوجه گیلاسی، زیتون سیاه، بیبی‌کورن، پنیر پارمسان و سس سالاد.'
UNION ALL
SELECT '1309','۸ عدد قارچ (در مجموع حدود ۱۲۰ تا ۱۵۰ گرم)، تخم‌مرغ، آرد طعم‌دار و پودر سوخاری.'
UNION ALL
SELECT '1310','۹۰ گرم مواد اسنک، نان تست، پنیر پیتزا، پنیر ویلی و سس کچاپ.'
UNION ALL
SELECT '1305','۵۰ گرم مرغ، ۵۰ گرم قارچ، ۱۰۰ گرم خامه، ۱۰۰ میلی‌لیتر شیر، ۱۰۰ گرم پنه، پنیر پارمسان، گوجه گیلاسی، جعفری خشک و زیتون سیاه.'
UNION ALL
SELECT '1210','کیک شعریه خانگی.'
UNION ALL
SELECT '1301','۲۵۰ گرم سیب‌زمینی، ادویه اختصاصی و سس کچاپ.'
UNION ALL
SELECT '1313','۲۵۰ گرم سیب‌زمینی، ۵۰ گرم قارچ، ۱۰۰ گرم خامه، ۵۰ میلی‌لیتر شیر، ¼ عصاره مرغ و ادویه اختصاصی.'
UNION ALL
SELECT '1203','کیک شکلاتی خانگی.'
UNION ALL
SELECT '1201','کیک بسبوسه خانگی.'
UNION ALL
SELECT '607','گل محمدی و تخم شربتی.'
UNION ALL
SELECT '606','به‌لیمو و تخم شربتی.'
UNION ALL
SELECT '605','بیدمشک، نسترن و خاکشیر.'
UNION ALL
SELECT '604','کاسنی، شاطره و تخم شربتی.'
UNION ALL
SELECT '603','زعفران، گلاب و خاکشیر.'
UNION ALL
SELECT '602','نعنا، خاکشیر و بهارنارنج.'
UNION ALL
SELECT '601','بهارنارنج و تخم شربتی.'
UNION ALL
SELECT '1001','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
UNION ALL
SELECT '1002','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
) d ON d.item_code=i.item_code SET i.description=d.new_description WHERE COALESCE(i.description,'')<>d.new_description;

-- Approved Variant-family descriptions, resolved through real member item codes so upgrade and clean-install family keys may differ safely.
INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.variant_family_description_sync','variant_family',CAST(f.id AS CHAR),JSON_OBJECT('member_code',i.item_code,'old_description',COALESCE(f.description,''),'new_description',d.new_description,'source','SOURCE_MENU_DESCRIPTIONS_20261013.txt')
FROM item_variant_families f JOIN item_variant_members m ON m.family_id=f.id JOIN items i ON i.id=m.item_id JOIN (
SELECT '709' AS member_code,'به، سیب و زعفران.' AS new_description
UNION ALL
SELECT '707','به، سیب و دارچین.'
UNION ALL
SELECT '705','آویشن و عسل.'
UNION ALL
SELECT '703','گل‌گاوزبان، بهارنارنج، سنبل‌الطیب و گلاب.'
UNION ALL
SELECT '701','چای قرمز، گل محمدی، بهارنارنج و دارچین.'
UNION ALL
SELECT '903','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
UNION ALL
SELECT '901','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
) d ON d.member_code=i.item_code WHERE COALESCE(f.description,'')<>d.new_description;
UPDATE item_variant_families f JOIN item_variant_members m ON m.family_id=f.id JOIN items i ON i.id=m.item_id JOIN (
SELECT '709' AS member_code,'به، سیب و زعفران.' AS new_description
UNION ALL
SELECT '707','به، سیب و دارچین.'
UNION ALL
SELECT '705','آویشن و عسل.'
UNION ALL
SELECT '703','گل‌گاوزبان، بهارنارنج، سنبل‌الطیب و گلاب.'
UNION ALL
SELECT '701','چای قرمز، گل محمدی، بهارنارنج و دارچین.'
UNION ALL
SELECT '903','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
UNION ALL
SELECT '901','اسپرسو بر پایه روبوستا ۶۰ در ۴۰.'
) d ON d.member_code=i.item_code SET f.description=d.new_description WHERE COALESCE(f.description,'')<>d.new_description;

-- Revision-3 labels: existing two-member tea/herbal families become تک‌نفره / دو نفره. No قوری بزرگ is guessed or created.
INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.variant_label_sync','variant_member',CONCAT(m.family_id,':',m.item_id),JSON_OBJECT('item_code',i.item_code,'old_label',m.label,'new_label',v.new_label,'source','H319 Revision 3')
FROM item_variant_members m JOIN items i ON i.id=m.item_id JOIN (
SELECT '801' AS item_code,'تک‌نفره' AS new_label
UNION ALL
SELECT '802','دو نفره'
UNION ALL
SELECT '803','تک‌نفره'
UNION ALL
SELECT '804','دو نفره'
UNION ALL
SELECT '805','تک‌نفره'
UNION ALL
SELECT '806','دو نفره'
UNION ALL
SELECT '807','تک‌نفره'
UNION ALL
SELECT '808','دو نفره'
UNION ALL
SELECT '701','تک‌نفره'
UNION ALL
SELECT '713','دو نفره'
UNION ALL
SELECT '703','تک‌نفره'
UNION ALL
SELECT '712','دو نفره'
UNION ALL
SELECT '705','تک‌نفره'
UNION ALL
SELECT '711','دو نفره'
UNION ALL
SELECT '707','تک‌نفره'
UNION ALL
SELECT '708','دو نفره'
UNION ALL
SELECT '709','تک‌نفره'
UNION ALL
SELECT '710','دو نفره'
) v ON v.item_code=i.item_code WHERE m.label<>v.new_label;
UPDATE item_variant_members m JOIN items i ON i.id=m.item_id JOIN (
SELECT '801' AS item_code,'تک‌نفره' AS new_label
UNION ALL
SELECT '802','دو نفره'
UNION ALL
SELECT '803','تک‌نفره'
UNION ALL
SELECT '804','دو نفره'
UNION ALL
SELECT '805','تک‌نفره'
UNION ALL
SELECT '806','دو نفره'
UNION ALL
SELECT '807','تک‌نفره'
UNION ALL
SELECT '808','دو نفره'
UNION ALL
SELECT '701','تک‌نفره'
UNION ALL
SELECT '713','دو نفره'
UNION ALL
SELECT '703','تک‌نفره'
UNION ALL
SELECT '712','دو نفره'
UNION ALL
SELECT '705','تک‌نفره'
UNION ALL
SELECT '711','دو نفره'
UNION ALL
SELECT '707','تک‌نفره'
UNION ALL
SELECT '708','دو نفره'
UNION ALL
SELECT '709','تک‌نفره'
UNION ALL
SELECT '710','دو نفره'
) v ON v.item_code=i.item_code SET m.label=v.new_label WHERE m.label<>v.new_label;

INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
VALUES(NULL,NULL,'catalog.business_reconcile_complete','catalog','H319',JSON_OBJECT('protein_shake_active',1,'price_sync_count',11,'item_description_count',34,'family_description_count',7,'variant_label_count',18,'large_pot_deferred',1,'baklava_bundle_deferred',1));
