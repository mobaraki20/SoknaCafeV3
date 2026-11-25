-- QA data-only candidate; requires whole-migration transaction and tested source integration.

-- Never import historical cost, balances, purchases, or sales prices. No change to existing menu rows.

-- Stable codes match existing ingredient identities; unknown names are exact matched before adding.

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-096')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-096' AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-02','بیسکویت لوتوس','ingredient','count','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))) AND ii.active=1 AND ii.base_unit='count')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-007')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-007' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-003')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-003' AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-058')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-058' AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-06','دارچین','ingredient','g','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین'))) AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-07','کنجد','ingredient','g','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو'))) AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-032')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-032' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-108')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-108' AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-10','یخ دستگاه','ingredient','g','bar',0,'ready','یخ تولید داخل؛ بدون ثبت خرید یا موجودی ساختگی.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-10' OR ii.name IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ‌ساز','یخ یخ ساز') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ ساز'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-10' OR ii.name IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ‌ساز','یخ یخ ساز') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ ساز'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-10' OR ii.name IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ‌ساز','یخ یخ ساز') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ ساز'))) AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-106')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-106' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-020')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-020' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-098')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-098' AND ii.active=1 AND ii.base_unit='ml')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-14','سیروپ انبه','ingredient','ml','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))) AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-008')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-008' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-014')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-014' AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-093')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-093' AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-021')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-021' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-015')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-015' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-107')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-107' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-095')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-095' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-097')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-097' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-001')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-001' AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-24','شکر قهوه‌ای','ingredient','g','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-24' OR ii.name IN ('شکر قهوه‌ای','شکر قهوه ای') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('شکر قهوه ای'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-24' OR ii.name IN ('شکر قهوه‌ای','شکر قهوه ای') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('شکر قهوه ای'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-24' OR ii.name IN ('شکر قهوه‌ای','شکر قهوه ای') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('شکر قهوه ای'))) AND ii.active=1 AND ii.base_unit='g')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-25','سیروپ وانیل','ingredient','ml','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))) AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-031')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-031' AND ii.active=1 AND ii.base_unit='g')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-117')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-117' AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-072')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-072' AND ii.active=1 AND ii.base_unit='count')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-062')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-062' AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-059')<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE ii.item_code='INV-059' AND ii.active=1 AND ii.base_unit='ml')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-31','سیروپ آلبالو','ingredient','ml','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-31' OR ii.name IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ','سیروپ آلبالو سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-31' OR ii.name IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ','سیروپ آلبالو سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-31' OR ii.name IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ','سیروپ آلبالو سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ'))) AND ii.active=1 AND ii.base_unit='ml')<>1);

INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
SELECT 'SOKNA-ING-20261124-32','نوشیدنی آلوئه‌ورا','ingredient','ml','bar',0,'ready','ماده رسپی تأییدشده ۲۴ نوامبر؛ قیمت فقط از ثبت واقعی خرید.',1,NULL
WHERE NOT EXISTS(SELECT 1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))))<>1 OR (SELECT COUNT(*) FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))) AND ii.active=1 AND ii.base_unit='ml')<>1);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM items WHERE item_code IN ('1104','1113','1010','1009','1110','1109','1103','1112','1111','1107','1101','1108','1102','905','1019','1005','1018','1016') AND sellable_kind='menu_item')<>18);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM items i WHERE i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو'))>1 OR (SELECT COUNT(*) FROM items i JOIN categories c ON c.id=i.category_id WHERE i.item_code='1018' AND c.category_key='cold-drinks' AND i.active=1)<>1);

INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,lifecycle_status,guest_visible,staff_visible,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order)
SELECT 'SOKNA-BLACK-MOJITO-20261124',i.category_id,'بلک موهیتو','',275000,NULL,1,1,'active',1,1,0,0,'menu_item',i.takeaway_allowed,'cold_bar',i.sort_order+1
FROM items i WHERE i.item_code='1018' AND NOT EXISTS(SELECT 1 FROM items b WHERE b.item_code='SOKNA-BLACK-MOJITO-20261124' OR b.name IN ('بلک موهیتو','بلک مویتو'));

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM items i JOIN categories c ON c.id=i.category_id WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND c.category_key='cold-drinks' AND i.sellable_kind='menu_item' AND i.active=1 AND i.price=275000)<>1);

INSERT INTO menu_items(menu_id,item_id) SELECT m.menu_id,b.id FROM menu_items m JOIN items red ON red.id=m.item_id AND red.item_code='1018' JOIN items b ON b.item_code='SOKNA-BLACK-MOJITO-20261124' WHERE NOT EXISTS(SELECT 1 FROM menu_items z WHERE z.menu_id=m.menu_id AND z.item_id=b.id);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1104' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,18,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-096'
WHERE i.item_code='1104' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,3,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس')))
WHERE i.item_code='1104' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,280,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1104' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,75,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1104' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1104' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1104' AND v.source_ref='owner_approved_bar_recipes_20261124')<>4);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1104' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1104' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1104' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1113' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,50,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-058'
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین')))
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,10,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو')))
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,160,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-032'
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,25,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-108'
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,90,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1113' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1113' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1113' AND v.source_ref='owner_approved_bar_recipes_20261124')<>6);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1113' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1113' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1113' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1010' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,200,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-032'
WHERE i.item_code='1010' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,190,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1010' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,125,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1010' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1010' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1010' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1010' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1010' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1010' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1009' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,250,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-032'
WHERE i.item_code='1009' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,70,'known','در دستور ثبت شود؛ بدون حرکت خرید ساختگی'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-10' OR ii.name IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ‌ساز','یخ یخ ساز') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('یخ دستگاه','یخ تولید دستگاه','یخ یخ ساز')))
WHERE i.item_code='1009' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,195,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1009' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,2,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-106'
WHERE i.item_code='1009' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1009' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1009' AND v.source_ref='owner_approved_bar_recipes_20261124')<>4);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1009' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1009' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1009' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1110' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,320,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-020'
WHERE i.item_code='1110' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-098'
WHERE i.item_code='1110' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ')))
WHERE i.item_code='1110' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1110' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1110' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1110' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1110' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1110' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1109' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,165,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1109' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,120,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-020'
WHERE i.item_code='1109' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,60,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ')))
WHERE i.item_code='1109' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1109' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1109' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1109' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1109' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1109' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1103' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,285,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-008'
WHERE i.item_code='1103' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,75,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1103' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,50,'known','۶۵ گرم فقط برآورد هزینه؛ واحد فعلی ml تغییر نکند'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-014'
WHERE i.item_code='1103' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1103' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1103' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1103' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1103' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1103' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1112' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,285,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1112' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,80,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-093'
WHERE i.item_code='1112' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,75,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1112' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1112' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1112' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1112' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1112' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1112' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1111' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,140,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-021'
WHERE i.item_code='1111' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,320,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-020'
WHERE i.item_code='1111' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,70,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-098'
WHERE i.item_code='1111' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,20,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ')))
WHERE i.item_code='1111' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1111' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1111' AND v.source_ref='owner_approved_bar_recipes_20261124')<>4);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1111' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1111' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1111' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1107' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,280,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1107' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,50,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-058'
WHERE i.item_code='1107' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,4,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-015'
WHERE i.item_code='1107' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1107' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1107' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1107' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1107' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1107' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1101' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,20,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-107'
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,80,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-095'
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,10,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو')))
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین')))
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,280,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1101' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1101' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1101' AND v.source_ref='owner_approved_bar_recipes_20261124')<>6);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1101' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1101' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1101' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1108' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,285,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1108' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,5,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-097'
WHERE i.item_code='1108' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1108' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1108' AND v.source_ref='owner_approved_bar_recipes_20261124')<>2);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1108' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1108' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1108' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1102' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,20,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-107'
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,80,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-095'
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,10,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو')))
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-06' OR ii.name IN ('دارچین','پودر دارچین') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('دارچین','پودر دارچین')))
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,280,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,12,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-001'
WHERE i.item_code='1102' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1102' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1102' AND v.source_ref='owner_approved_bar_recipes_20261124')<>6);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1102' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1102' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1102' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='905' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,130,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-007'
WHERE i.item_code='905' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,12,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-001'
WHERE i.item_code='905' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='905' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='905' AND v.source_ref='owner_approved_bar_recipes_20261124')<>2);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='905' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='905' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='905' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE i.item_code='1019' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,120,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-003'
WHERE i.item_code='1019' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,3,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-097'
WHERE i.item_code='1019' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,2,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-24' OR ii.name IN ('شکر قهوه‌ای','شکر قهوه ای') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('شکر قهوه ای')))
WHERE i.item_code='1019' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,20,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل')))
WHERE i.item_code='1019' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1019' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1019' AND v.source_ref='owner_approved_bar_recipes_20261124')<>4);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1019' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1019' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1019' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','partial','owner_approved_bar_recipes_20261124',NULL,'نعنا: یک سهم لیوان؛ وزن واقعی مشخص نیست و به‌صورت گرم ساختگی از انبار کسر نمی‌شود.',NULL
FROM items i WHERE i.item_code='1005' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,150,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-031'
WHERE i.item_code='1005' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,60,'known','هویت سن‌ایچ با INV-060 بررسی شود، ادغام کور ممنوع'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-117'
WHERE i.item_code='1005' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known','دستور نوشیدنی ۱۲۰ میلی‌لیتر؛ مصرف انبار یک بطری کامل طبق تأیید کاربر.'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-072'
WHERE i.item_code='1005' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1005' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1005' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1005' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1005' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1005' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','partial','owner_approved_bar_recipes_20261124',NULL,'نعنا: یک سهم لیوان؛ وزن واقعی مشخص نیست و به‌صورت گرم ساختگی از انبار کسر نمی‌شود.',NULL
FROM items i WHERE i.item_code='1018' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,150,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-031'
WHERE i.item_code='1018' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,60,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-093'
WHERE i.item_code='1018' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,5,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-062'
WHERE i.item_code='1018' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known','دستور نوشیدنی ۱۲۰ میلی‌لیتر؛ مصرف انبار یک بطری کامل طبق تأیید کاربر.'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-072'
WHERE i.item_code='1018' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1018' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1018' AND v.source_ref='owner_approved_bar_recipes_20261124')<>4);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1018' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1018' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1018' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','partial','owner_approved_bar_recipes_20261124',NULL,'نعنا: یک سهم لیوان؛ وزن واقعی مشخص نیست و به‌صورت گرم ساختگی از انبار کسر نمی‌شود.',NULL
FROM items i WHERE i.item_code='1016' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,150,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-031'
WHERE i.item_code='1016' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,60,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-059'
WHERE i.item_code='1016' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known','دستور نوشیدنی ۱۲۰ میلی‌لیتر؛ مصرف انبار یک بطری کامل طبق تأیید کاربر.'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-072'
WHERE i.item_code='1016' AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1016' AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1016' AND v.source_ref='owner_approved_bar_recipes_20261124')<>3);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE i.item_code='1016' AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1016' AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE i.item_code='1016' AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id)
SELECT i.id,COALESCE((SELECT MAX(v.version_no) FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id),0)+1,'retired','ready','complete','owner_approved_bar_recipes_20261124',NULL,NULL,NULL
FROM items i WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND NOT EXISTS(SELECT 1 FROM inventory_recipe_versions v WHERE v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124');

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-31' OR ii.name IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ','سیروپ آلبالو سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ')))
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-059'
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,30,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا')))
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,1,'known','دستور نوشیدنی ۱۵۰ میلی‌لیتر؛ مصرف انبار یک بطری کامل طبق تأیید کاربر.'
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-072'
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note)
SELECT v.id,ii.id,120,'known',NULL
FROM items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.source_ref='owner_approved_bar_recipes_20261124' JOIN inventory_items ii ON ii.item_code='INV-031'
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='retired' AND NOT EXISTS(SELECT 1 FROM inventory_recipe_components co WHERE co.recipe_version_id=v.id AND co.inventory_item_id=ii.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.source_ref='owner_approved_bar_recipes_20261124')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_components co JOIN inventory_recipe_versions v ON v.id=co.recipe_version_id JOIN items i ON i.id=v.menu_item_id WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.source_ref='owner_approved_bar_recipes_20261124')<>5);

UPDATE inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id
JOIN inventory_recipe_versions nextv ON nextv.menu_item_id=i.id AND nextv.source_ref='owner_approved_bar_recipes_20261124' AND nextv.status='retired'
SET v.status=IF(v.id=nextv.id,'active','retired'),v.retired_at=IF(v.id=nextv.id,NULL,NOW())
WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND (v.status='active' OR v.id=nextv.id);

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='active')<>1 OR (SELECT COUNT(*) FROM inventory_recipe_versions v JOIN items i ON i.id=v.menu_item_id WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' AND v.review_status='ready')<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=3000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-007' AND u.name='بن‌ماری ۳ کیلویی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بن‌ماری ۳ کیلویی','fixed',3000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-007' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بن‌ماری ۳ کیلویی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-007' AND u.name='بن‌ماری ۳ کیلویی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=3000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=6000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-007' AND u.name='کارتن ۲ بن‌ماری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'کارتن ۲ بن‌ماری','fixed',6000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-007' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='کارتن ۲ بن‌ماری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-007' AND u.name='کارتن ۲ بن‌ماری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=6000)<>1);

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT ii.id,'بستنی سفید','بستنی سفید','owner_approved_bar_recipes_20261124','ready','نام رایج تأییدشده؛ سوابق تاریخی محفوظ',1 FROM inventory_items ii WHERE ii.item_code='INV-007' AND NOT EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.normalized_alias='بستنی سفید') AND NOT EXISTS(SELECT 1 FROM inventory_items j WHERE j.id<>ii.id AND j.name IN ('بستنی سفید','بستنی سفید'));

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT ii.id,'بستنی وانیلی','بستنی وانیلی','owner_approved_bar_recipes_20261124','ready','نام رایج تأییدشده؛ سوابق تاریخی محفوظ',1 FROM inventory_items ii WHERE ii.item_code='INV-007' AND NOT EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.normalized_alias='بستنی وانیلی') AND NOT EXISTS(SELECT 1 FROM inventory_items j WHERE j.id<>ii.id AND j.name IN ('بستنی وانیلی','بستنی وانیلی'));

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=3000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-008' AND u.name='بن‌ماری ۳ کیلویی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بن‌ماری ۳ کیلویی','fixed',3000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-008' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بن‌ماری ۳ کیلویی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-008' AND u.name='بن‌ماری ۳ کیلویی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=3000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=6000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-008' AND u.name='کارتن ۲ بن‌ماری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'کارتن ۲ بن‌ماری','fixed',6000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-008' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='کارتن ۲ بن‌ماری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-008' AND u.name='کارتن ۲ بن‌ماری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=6000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=1000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-003' AND u.name='پاکت یک لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'پاکت یک لیتری','fixed',1000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-003' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='پاکت یک لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-003' AND u.name='پاکت یک لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=10000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-003' AND u.name='کارتن ۱۰ پاکتی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'کارتن ۱۰ پاکتی','fixed',10000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-003' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='کارتن ۱۰ پاکتی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-003' AND u.name='کارتن ۱۰ پاکتی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=10000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=710,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-062' AND u.name='بطری ۷۱۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۷۱۰ میلی‌لیتری','fixed',710,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-062' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۷۱۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-062' AND u.name='بطری ۷۱۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=710)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=8520,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-062' AND u.name='کارتن ۱۲ بطری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'کارتن ۱۲ بطری','fixed',8520,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-062' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='کارتن ۱۲ بطری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-062' AND u.name='کارتن ۱۲ بطری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=8520)<>1);

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT ii.id,'ویمتو','ویمتو','owner_approved_bar_recipes_20261124','ready','نام رایج تأییدشده؛ سوابق تاریخی محفوظ',1 FROM inventory_items ii WHERE ii.item_code='INV-062' AND NOT EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.normalized_alias='ویمتو') AND NOT EXISTS(SELECT 1 FROM inventory_items j WHERE j.id<>ii.id AND j.name IN ('ویمتو','ویمتو'));

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT ii.id,'فیمتو','فیمتو','owner_approved_bar_recipes_20261124','ready','نام رایج تأییدشده؛ سوابق تاریخی محفوظ',1 FROM inventory_items ii WHERE ii.item_code='INV-062' AND NOT EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.normalized_alias='فیمتو') AND NOT EXISTS(SELECT 1 FROM inventory_items j WHERE j.id<>ii.id AND j.name IN ('فیمتو','فیمتو'));

INSERT INTO inventory_item_aliases(inventory_item_id,alias,normalized_alias,source_ref,review_status,note,active)
SELECT ii.id,'شربت ویمتو','شربت ویمتو','owner_approved_bar_recipes_20261124','ready','نام رایج تأییدشده؛ سوابق تاریخی محفوظ',1 FROM inventory_items ii WHERE ii.item_code='INV-062' AND NOT EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.normalized_alias='شربت ویمتو') AND NOT EXISTS(SELECT 1 FROM inventory_items j WHERE j.id<>ii.id AND j.name IN ('شربت ویمتو','شربت ویمتو'));

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=2500,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-020' AND u.name='بسته ۲٫۵ کیلویی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بسته ۲٫۵ کیلویی','fixed',2500,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-020' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته ۲٫۵ کیلویی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-020' AND u.name='بسته ۲٫۵ کیلویی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=2500)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=2500,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-021' AND u.name='بسته ۲٫۵ کیلویی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بسته ۲٫۵ کیلویی','fixed',2500,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-021' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته ۲٫۵ کیلویی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-021' AND u.name='بسته ۲٫۵ کیلویی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=2500)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=820,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-093' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۸۲۰ میلی‌لیتری','fixed',820,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-093' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۸۲۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-093' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=820)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=820,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-059' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۸۲۰ میلی‌لیتری','fixed',820,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-059' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۸۲۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-059' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=820)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=820,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-117' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۸۲۰ میلی‌لیتری','fixed',820,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-117' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۸۲۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-117' AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=820)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=1000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-098' AND u.name='پاکت یک لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'پاکت یک لیتری','fixed',1000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-098' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='پاکت یک لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-098' AND u.name='پاکت یک لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=400,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-096' AND u.name='ظرف ۴۰۰ گرمی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'ظرف ۴۰۰ گرمی','fixed',400,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-096' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='ظرف ۴۰۰ گرمی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-096' AND u.name='ظرف ۴۰۰ گرمی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=400)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=500,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-108' AND u.name='بسته ۵۰۰ گرمی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بسته ۵۰۰ گرمی','fixed',500,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-108' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته ۵۰۰ گرمی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-108' AND u.name='بسته ۵۰۰ گرمی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=500)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=1,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-072' AND u.name='بطری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری','fixed',1,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-072' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-072' AND u.name='بطری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=15,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-072' AND u.name='شل ۱۵ بطری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'شل ۱۵ بطری','fixed',15,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-072' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='شل ۱۵ بطری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-072' AND u.name='شل ۱۵ بطری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=15)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=1000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE ii.item_code='INV-015' AND u.name='بسته یک کیلویی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بسته یک کیلویی','fixed',1000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE ii.item_code='INV-015' AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته یک کیلویی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE ii.item_code='INV-015' AND u.name='بسته یک کیلویی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=27,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))) AND u.name='بسته ۲۷ عددی' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بسته ۲۷ عددی','fixed',27,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))) AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته ۲۷ عددی');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE (ii.item_code='SOKNA-ING-20261124-02' OR ii.name IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('بیسکویت لوتوس','بیسکوییت لوتوس','بیسکویت لوتس'))) AND u.name='بسته ۲۷ عددی' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=27)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=1000,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))) AND u.name='بطری یک لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری یک لیتری','fixed',1000,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))) AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری یک لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE (ii.item_code='SOKNA-ING-20261124-32' OR ii.name IN ('نوشیدنی آلوئه‌ورا','نوشیدنی آلوورا','آب آلوورا','آلوئه ورا','نوشیدنی آلوئه ورا','نوشیدنی آلوورا بزرگ','آب آلوئه‌ورا') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('نوشیدنی آلویه ورا','نوشیدنی آلوورا','آب آلوورا','آلویه ورا','نوشیدنی آلوورا بزرگ','آب آلویه ورا'))) AND u.name='بطری یک لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=820,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))) AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۸۲۰ میلی‌لیتری','fixed',820,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))) AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۸۲۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE (ii.item_code='SOKNA-ING-20261124-14' OR ii.name IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ','سیروپ انبه سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ انبه','شربت انبه','سیروپ انبه سن ایچ'))) AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=820)<>1);

UPDATE inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id SET u.conversion_mode='fixed',u.base_quantity=820,u.review_status='ready',u.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶' WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))) AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND (u.conversion_mode='actual_quantity' OR u.review_status='needs_review');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT ii.id,'بطری ۸۲۰ میلی‌لیتری','fixed',820,'ready','بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶',1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))) AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بطری ۸۲۰ میلی‌لیتری');

SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) guard_failure WHERE (SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items ii ON ii.id=u.inventory_item_id WHERE (ii.item_code='SOKNA-ING-20261124-25' OR ii.name IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ وانیل','سیروپ وانیلی','شربت وانیل'))) AND u.name='بطری ۸۲۰ میلی‌لیتری' AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=820)<>1);

UPDATE inventory_items ii SET ii.review_note=CONCAT_WS(' | ',NULLIF(ii.review_note,''),'820ml assumed by costing master, not verified packaging; use note until confirmed') WHERE (ii.item_code='SOKNA-ING-20261124-31' OR ii.name IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ','سیروپ آلبالو سن‌ایچ') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('سیروپ آلبالو','شربت آلبالو','سیروپ آلبالو سن ایچ'))) AND LOCATE('820ml assumed by costing master, not verified packaging; use note until confirmed',COALESCE(ii.review_note,''))=0 AND CHAR_LENGTH(CONCAT_WS(' | ',NULLIF(ii.review_note,''),'820ml assumed by costing master, not verified packaging; use note until confirmed'))<=500;

UPDATE inventory_items ii SET ii.review_note=CONCAT_WS(' | ',NULLIF(ii.review_note,''),'ظرف ۸۰۰ گرمی؛ موجودی فعلی ml؛ تبدیل وزنی به حجمی ممنوع تا شاهد دقیق؛ رسیپی ۵۰ml') WHERE ii.item_code='INV-014' AND LOCATE('ظرف ۸۰۰ گرمی؛ موجودی فعلی ml؛ تبدیل وزنی به حجمی ممنوع تا شاهد دقیق؛ رسیپی ۵۰ml',COALESCE(ii.review_note,''))=0 AND CHAR_LENGTH(CONCAT_WS(' | ',NULLIF(ii.review_note,''),'ظرف ۸۰۰ گرمی؛ موجودی فعلی ml؛ تبدیل وزنی به حجمی ممنوع تا شاهد دقیق؛ رسیپی ۵۰ml'))<=500;

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active) SELECT ii.id,'بسته ۳۴۰ گرمی','fixed',340,'ready','بسته‌بندی تأییدشده',1 FROM inventory_items ii WHERE (ii.item_code='SOKNA-ING-20261124-07' OR ii.name IN ('کنجد','کنجد بی‌بو','کنجد بی بو') OR EXISTS(SELECT 1 FROM inventory_item_aliases aa WHERE aa.inventory_item_id=ii.id AND aa.active=1 AND aa.normalized_alias IN ('کنجد','کنجد بی بو'))) AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=ii.id AND u.name='بسته ۳۴۰ گرمی');
