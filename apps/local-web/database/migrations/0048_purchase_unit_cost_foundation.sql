-- H208 — Purchase-unit + cost foundation.
-- Unknown package conversions must never behave like invalid fixed units that block receiving.
-- Keep them operational as actual-quantity units until reviewed.

UPDATE inventory_purchase_units
SET conversion_mode='actual_quantity',
    base_quantity=NULL,
    review_status='needs_review',
    note=CONCAT(COALESCE(NULLIF(note,''),''), CASE WHEN COALESCE(NULLIF(note,''),'')='' THEN '' ELSE ' | ' END,
                'H208: تبدیل ثابت قبلی نامشخص بود؛ تا زمان تأیید، مقدار واقعی هنگام تحویل ثبت شود.')
WHERE conversion_mode='fixed' AND (base_quantity IS NULL OR base_quantity<1);

-- Safe, non-fabricated units for master items introduced from real purchase vocabulary.
-- Only mathematically certain conversions are fixed; uncertain packaging is actual_quantity.
INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'کیلوگرم','fixed',1000,'ready','H208: واحد استاندارد وزنی.',1 FROM inventory_items i
WHERE i.item_code IN ('INV-102','INV-103','INV-104','INV-105','INV-106','INV-107','INV-108')
AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='کیلوگرم');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'عدد','fixed',1,'ready','H208: هر عدد برابر یک واحد پایه است.',1 FROM inventory_items i
WHERE i.item_code IN ('INV-101','INV-110','INV-111','INV-114')
AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='عدد');

INSERT INTO inventory_purchase_units(inventory_item_id,name,conversion_mode,base_quantity,review_status,note,active)
SELECT i.id,'بسته / ظرف','actual_quantity',NULL,'needs_review','H208: وزن/حجم بسته ثابت فرض نشود؛ مقدار واقعی هر تحویل ثبت شود.',1 FROM inventory_items i
WHERE i.item_code IN ('INV-109','INV-112','INV-113','INV-115','INV-116','INV-117')
AND NOT EXISTS (SELECT 1 FROM inventory_purchase_units u WHERE u.inventory_item_id=i.id AND u.name='بسته / ظرف');
