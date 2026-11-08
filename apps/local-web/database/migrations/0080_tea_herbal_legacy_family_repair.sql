-- R148: SQL-only, append-only, transactional repair for legacy family-03..family-11.
-- Do not edit/re-run the applied 0078. Every mismatch aborts the entire 0080 transaction.
-- No tables are dropped or renamed; historical orders and existing item codes remain unchanged.
-- The original two-person price is captured in the audit log prior to any change.
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) fail WHERE EXISTS (
 SELECT 1 FROM item_variant_families f LEFT JOIN categories c ON c.id=f.category_id
 WHERE f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11') AND (
   f.active<>1 OR COALESCE(c.category_key,'')<>'tea-herbal'
   OR (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id) NOT IN (2,3)
   OR NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END) AND REPLACE(m.label,'‌',' ')='تک نفره'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1 OR NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END) AND REPLACE(m.label,'‌',' ')='دو نفره'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1
   OR ((SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id)=3 AND NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20)) AND m.label='بزرگ'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1)
   OR EXISTS(SELECT 1 FROM item_variant_families cf WHERE cf.family_key=REPLACE(f.family_key,'family-','h297-'))
   OR EXISTS(SELECT 1 FROM items lg WHERE lg.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20)) AND (
      lg.category_id<>f.category_id OR lg.lifecycle_status<>'active'
      OR lg.name<>CONCAT(f.name,' - بزرگ')
      OR EXISTS (SELECT 1 FROM item_variant_members lm WHERE lm.item_id=lg.id AND lm.family_id<>f.id)
   ))
 )
));

INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
SELECT NULL,NULL,'catalog.legacy_tea_size_expansion_r148','variant_family',CAST(f.id AS CHAR),
 JSON_OBJECT('source','r148','family_key',f.family_key,'single_item_id',i1.id,
 'two_item_id',i2.id,'old_two_price_toman',i2.price,'single_price_toman',i1.price,
 'proposed_two_price_toman',ROUND(i1.price*1.70/5000)*5000,
 'proposed_large_price_toman',ROUND(i1.price*2.30/5000)*5000)
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
 AND (SELECT COUNT(*) FROM item_variant_members mx WHERE mx.family_id=f.id)=2;

INSERT IGNORE INTO items(item_code,category_id,name,description,price,image_path,available,active,
 lifecycle_status,guest_visible,staff_visible,featured,staff_only,sellable_kind,takeaway_allowed,
 preparation_station,sort_order)
SELECT CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20)),i2.category_id,CONCAT(f.name,' - بزرگ'),i2.description,
 ROUND(i1.price*2.30/5000)*5000,i2.image_path,i2.available,i2.active,i2.lifecycle_status,
 i2.guest_visible,i2.staff_visible,0,i2.staff_only,'menu_item',i2.takeaway_allowed,i2.preparation_station,i2.sort_order+1
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
 AND (SELECT COUNT(*) FROM item_variant_members mx WHERE mx.family_id=f.id)=2;

INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT mi.menu_id,lg.id
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20))
JOIN menu_items mi ON mi.item_id=i2.id
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

INSERT IGNORE INTO guest_media_references(media_id,owner_type,owner_id,slot_key)
SELECT mr.media_id,'item',CAST(lg.id AS CHAR),mr.slot_key
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20))
JOIN guest_media_references mr ON mr.owner_type='item' AND BINARY mr.owner_id = BINARY CAST(i2.id AS CHAR) AND mr.slot_key='card'
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

UPDATE items i2
 JOIN item_variant_members m2 ON m2.item_id=i2.id AND m2.label='دو نفره'
 JOIN item_variant_families f ON f.id=m2.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
 JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
 JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
 SET i2.price=ROUND(i1.price*1.70/5000)*5000
 WHERE f.active=1 AND i1.price>0 AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
 AND (SELECT COUNT(*) FROM item_variant_members mx WHERE mx.family_id=f.id)=2;

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order)
SELECT f.id,lg.id,'بزرگ',30
FROM item_variant_families f
JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره'
JOIN items i1 ON i1.id=m1.item_id AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END)
JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره'
JOIN items i2 ON i2.id=m2.item_id AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END)
JOIN items lg ON lg.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20))
WHERE f.active=1 AND m1.item_id<>m2.item_id AND i1.lifecycle_status='active' AND i2.lifecycle_status='active'
;

UPDATE items i1 JOIN item_variant_members m1 ON m1.item_id=i1.id
 JOIN item_variant_families f ON f.id=m1.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
 SET i1.name=CONCAT(f.name,' - تک‌نفره'),i1.description=IF(i1.description='قوری کوچک.','قوری تک‌نفره.',i1.description)
 WHERE REPLACE(m1.label,'‌',' ')='تک نفره' AND i1.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END) AND i1.name LIKE '% - کوچک';

UPDATE items i2 JOIN item_variant_members m2 ON m2.item_id=i2.id
 JOIN item_variant_families f ON f.id=m2.family_id
 JOIN categories c ON c.id=f.category_id AND c.category_key='tea-herbal' AND f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
 SET i2.name=CONCAT(f.name,' - دو نفره'),i2.description=IF(i2.description='قوری بزرگ.','قوری دو نفره.',i2.description)
 WHERE m2.label='دو نفره' AND i2.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END) AND i2.name LIKE '% - بزرگ';

-- Postcondition: every detected legacy family must now have exactly 3 correctly linked members;
-- a missing/ambiguous family is NOT silently reported as complete by the read-only audit.
SELECT (SELECT x FROM (SELECT 1 AS x UNION ALL SELECT 2 AS x) fail WHERE EXISTS (
 SELECT 1 FROM item_variant_families f LEFT JOIN categories c ON c.id=f.category_id
 WHERE f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11') AND (
   f.active<>1 OR COALESCE(c.category_key,'')<>'tea-herbal'
   OR (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id)<>3
   OR NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=(CASE f.family_key WHEN 'family-03' THEN '801' WHEN 'family-04' THEN '803' WHEN 'family-05' THEN '805' WHEN 'family-06' THEN '807' WHEN 'family-07' THEN '701' WHEN 'family-08' THEN '703' WHEN 'family-09' THEN '705' WHEN 'family-10' THEN '707' WHEN 'family-11' THEN '709' ELSE NULL END) AND REPLACE(m.label,'‌',' ')='تک نفره'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1 OR NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=(CASE f.family_key WHEN 'family-03' THEN '802' WHEN 'family-04' THEN '804' WHEN 'family-05' THEN '806' WHEN 'family-06' THEN '808' WHEN 'family-07' THEN '713' WHEN 'family-08' THEN '712' WHEN 'family-09' THEN '711' WHEN 'family-10' THEN '708' WHEN 'family-11' THEN '710' ELSE NULL END) AND REPLACE(m.label,'‌',' ')='دو نفره'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1 OR NOT (
   SELECT COUNT(*) FROM item_variant_members m JOIN items i ON i.id=m.item_id
   WHERE m.family_id=f.id AND i.item_code=CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20)) AND m.label='بزرگ'
     AND i.lifecycle_status='active' AND i.price>0 AND i.category_id=f.category_id
 )=1
   OR EXISTS (SELECT 1 FROM item_variant_members lm JOIN items lg ON lg.id=lm.item_id
      WHERE lm.family_id=f.id AND lm.label='بزرگ' AND lg.item_code<>CONCAT('szlarge-',LEFT(SHA2(REPLACE(f.family_key,'family-','h297-'),256),20)))
 )
));