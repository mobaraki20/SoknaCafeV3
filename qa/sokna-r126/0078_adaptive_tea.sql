-- R121: tea/herbal sizes; new large is a distinct sellable, not a relabelled second size.
-- Live single price is authoritative. 2-person = rounded 1.70x, large = rounded 2.30x.
-- Historic order-item snapshots are immutable. This change is repeatable after a partial migration.
-- Only an active tea/herbal family with exactly two members carrying exact single/two-person labels is eligible.
-- Guard canonical families exist before any mutation (clean installs also have all 9).;
-- Only structurally eligible existing families are transformed. A custom catalog with none is safe (no-op).
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
 (SELECT COUNT(*) FROM item_variant_families WHERE family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')) <>
 (SELECT COUNT(DISTINCT f.id) FROM item_variant_families f
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
 JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
 JOIN items i1 ON i1.id=m1.item_id AND i1.lifecycle_status='active' AND i1.price>0
 JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
 JOIN items i2 ON i2.id=m2.item_id AND i2.lifecycle_status='active'
 WHERE f.active=1 AND m1.item_id<>m2.item_id
 AND (SELECT COUNT(*) FROM item_variant_members x WHERE x.family_id=f.id)=2));

INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.size_expansion_r121','variant_family',CAST(f.id AS CHAR),
 JSON_OBJECT('source','r121','family_key',f.family_key,'single_item_id',i1.id,
 'two_item_id',i2.id,'old_two_price_toman',i2.price,'single_price_toman',i1.price,
 'proposed_two_price_toman',ROUND(i1.price*1.70/5000)*5000,
 'proposed_large_price_toman',ROUND(i1.price*2.30/5000)*5000)
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
 AND (SELECT COUNT(*) FROM item_variant_members mx WHERE mx.family_id=f.id)=2;

INSERT IGNORE INTO items(item_code,category_id,name,description,price,image_path,available,active,
 lifecycle_status,guest_visible,staff_visible,featured,staff_only,sellable_kind,takeaway_allowed,
 preparation_station,sort_order)
SELECT CONCAT('szlarge-',LEFT(SHA2(f.family_key,256),20)),i2.category_id,CONCAT(f.name,' - بزرگ'),i2.description,
 ROUND(i1.price*2.30/5000)*5000,i2.image_path,i2.available,i2.active,i2.lifecycle_status,
 i2.guest_visible,i2.staff_visible,0,i2.staff_only,'menu_item',i2.takeaway_allowed,i2.preparation_station,i2.sort_order+1
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
 AND (SELECT COUNT(*) FROM item_variant_members mx WHERE mx.family_id=f.id)=2;

INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT mi.menu_id,lg.id
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(f.family_key,256),20))
JOIN menu_items mi ON mi.item_id=i2.id
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

INSERT IGNORE INTO guest_media_references(media_id,owner_type,owner_id,slot_key)
SELECT mr.media_id,'item',CAST(lg.id AS CHAR),mr.slot_key
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(f.family_key,256),20))
JOIN guest_media_references mr ON mr.owner_type='item' AND mr.owner_id=CAST(i2.id AS CHAR) AND mr.slot_key='card'
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order)
SELECT f.id,lg.id,'بزرگ',30
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(f.family_key,256),20))
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

UPDATE items i1 JOIN item_variant_members m1 ON m1.item_id=i1.id
 JOIN item_variant_families f ON f.id=m1.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
 SET i1.name=CONCAT(f.name,' - تک‌نفره'),i1.description=IF(i1.description='قوری کوچک.','قوری تک‌نفره.',i1.description)
 WHERE REPLACE(m1.label,'‌',' ')='تک نفره' AND i1.name LIKE '% - کوچک';

UPDATE items i2 JOIN item_variant_members m2 ON m2.item_id=i2.id
 JOIN item_variant_families f ON f.id=m2.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
 SET i2.name=CONCAT(f.name,' - دو نفره'),i2.description=IF(i2.description='قوری بزرگ.','قوری دو نفره.',i2.description)
 WHERE m2.label='دو نفره' AND i2.name LIKE '% - بزرگ';

UPDATE items i2
 JOIN item_variant_members m2 ON m2.item_id=i2.id AND m2.label='دو نفره'
 JOIN item_variant_families f ON f.id=m2.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
 JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
 JOIN items i1 ON i1.id=m1.item_id
 SET i2.price=ROUND(i1.price*1.70/5000)*5000
 WHERE f.active=1 AND i1.price>0;

SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
  (SELECT COUNT(*) FROM item_variant_families f JOIN categories c ON c.id=f.category_id
   WHERE f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')
     AND c.category_key='tea-herbal'
     AND (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id)=3
     AND (SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
          WHERE m.family_id=f.id AND m.label='بزرگ'
          AND i.item_code=CONCAT('szlarge-',LEFT(SHA2(f.family_key,256),20)))=1
     AND (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id AND m.label='دو نفره')=1
     AND (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id AND REPLACE(m.label,'‌',' ')='تک نفره')=1) <> (SELECT COUNT(*) FROM item_variant_families WHERE family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')));