-- 0091: verified purchase vocabulary only. Never alter balances, cost, receipts or historical IDs.
-- Exact duplicate units: retire the newer label only when both quantities are identical.
-- Unknown or operator-modified units remain intact.

UPDATE inventory_purchase_units newer
JOIN inventory_items i ON i.id=newer.inventory_item_id
JOIN inventory_purchase_units legacy ON legacy.inventory_item_id=i.id AND legacy.id<newer.id
SET newer.active=0
WHERE i.item_code='INV-003' AND i.active=1 AND i.base_unit='ml'
  AND BINARY legacy.name=BINARY 'عدد ۱ لیتری' AND BINARY newer.name=BINARY 'پاکت یک لیتری'
  AND legacy.active=1 AND newer.active=1
  AND legacy.conversion_mode='fixed' AND newer.conversion_mode='fixed'
  AND legacy.review_status='ready' AND newer.review_status='ready'
  AND legacy.base_quantity=1000 AND newer.base_quantity=1000
  AND newer.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶';

UPDATE inventory_purchase_units newer
JOIN inventory_items i ON i.id=newer.inventory_item_id
JOIN inventory_purchase_units legacy ON legacy.inventory_item_id=i.id AND legacy.id<newer.id
SET newer.active=0
WHERE i.item_code='INV-020' AND i.active=1 AND i.base_unit='g'
  AND BINARY legacy.name=BINARY 'بسته ۲.۵ کیلویی' AND BINARY newer.name=BINARY 'بسته ۲٫۵ کیلویی'
  AND legacy.active=1 AND newer.active=1
  AND legacy.conversion_mode='fixed' AND newer.conversion_mode='fixed'
  AND legacy.review_status='ready' AND newer.review_status='ready'
  AND legacy.base_quantity=2500 AND newer.base_quantity=2500
  AND newer.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶';

UPDATE inventory_purchase_units newer
JOIN inventory_items i ON i.id=newer.inventory_item_id
JOIN inventory_purchase_units legacy ON legacy.inventory_item_id=i.id AND legacy.id<newer.id
SET newer.active=0
WHERE i.item_code='INV-021' AND i.active=1 AND i.base_unit='g'
  AND BINARY legacy.name=BINARY 'بسته ۲.۵ کیلویی' AND BINARY newer.name=BINARY 'بسته ۲٫۵ کیلویی'
  AND legacy.active=1 AND newer.active=1
  AND legacy.conversion_mode='fixed' AND newer.conversion_mode='fixed'
  AND legacy.review_status='ready' AND newer.review_status='ready'
  AND legacy.base_quantity=2500 AND newer.base_quantity=2500
  AND newer.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶';

UPDATE inventory_purchase_units newer
JOIN inventory_items i ON i.id=newer.inventory_item_id
JOIN inventory_purchase_units legacy ON legacy.inventory_item_id=i.id AND legacy.id<newer.id
SET newer.active=0
WHERE i.item_code='INV-072' AND i.active=1 AND i.base_unit='count'
  AND BINARY legacy.name=BINARY 'شل ۱۵ عددی' AND BINARY newer.name=BINARY 'شل ۱۵ بطری'
  AND legacy.active=1 AND newer.active=1
  AND legacy.conversion_mode='fixed' AND newer.conversion_mode='fixed'
  AND legacy.review_status='ready' AND newer.review_status='ready'
  AND legacy.base_quantity=15 AND newer.base_quantity=15
  AND newer.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶';

UPDATE inventory_purchase_units newer
JOIN inventory_items i ON i.id=newer.inventory_item_id
JOIN inventory_purchase_units legacy ON legacy.inventory_item_id=i.id AND legacy.id<newer.id
SET newer.active=0
WHERE i.item_code='INV-015' AND i.active=1 AND i.base_unit='g'
  AND BINARY legacy.name=BINARY 'عدد / بسته ۱ کیلویی' AND BINARY newer.name=BINARY 'بسته یک کیلویی'
  AND legacy.active=1 AND newer.active=1
  AND legacy.conversion_mode='fixed' AND newer.conversion_mode='fixed'
  AND legacy.review_status='ready' AND newer.review_status='ready'
  AND legacy.base_quantity=1000 AND newer.base_quantity=1000
  AND newer.note='بسته‌بندی تأییدشده ۲۴ نوامبر ۲۰۲۶';

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته ۱۰۰ گرمی','fixed',100,'ready','تطبیق بسته‌بندی خرید؛ message454؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-019' AND i.base_unit='g' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته ۱۰۰ گرمی')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=100);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کارتن ۶ عددی ۱۸۰۰ گرمی','fixed',10800,'ready','تطبیق بسته‌بندی خرید؛ message367 و message443؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-052' AND i.base_unit='g' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کارتن ۶ عددی ۱۸۰۰ گرمی')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=10800);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'شل ۲۴ عددی','fixed',24,'ready','تطبیق بسته‌بندی خرید؛ message393 و message433؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-076' AND i.base_unit='count' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='شل ۲۴ عددی')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=24);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'۱۰ عدد','fixed',10,'ready','تطبیق بسته‌بندی خرید؛ message456؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-028' AND i.base_unit='count' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='۱۰ عدد')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=10);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'۵ عدد','fixed',5,'ready','تطبیق بسته‌بندی خرید؛ message456؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-029' AND i.base_unit='count' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='۵ عدد')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=5);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته یک کیلویی','fixed',1000,'ready','تطبیق بسته‌بندی خرید؛ message455؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-112' AND i.base_unit='g' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته یک کیلویی')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000);

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته یک کیلویی','fixed',1000,'ready','تطبیق بسته‌بندی خرید؛ message455؛ بدون ثبت خرید یا قیمت',1
FROM inventory_items i WHERE i.item_code='INV-113' AND i.base_unit='g' AND i.active=1
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته یک کیلویی')
AND NOT EXISTS(SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=1000);
