-- H203 — Sync latest Sokna item master/prices from Items - 1405_07_18 23_01.xlsx.
-- Source SHA-256: a91e2bb4c2652d856c563c29639c55d479773374d1ef482212ef4be0dcc1e21a
-- Existing price updates are guarded by the H201 default price so a later/manual installed price is never overwritten.
-- Descriptions, images, schedules, featured flags and operational availability are not rewritten.
UPDATE items SET price=315000 WHERE item_code='1309' AND price=290000; -- قارچ سوخاری
UPDATE items SET price=395000 WHERE item_code='1102' AND price=375000; -- شیک ارده اسپرسو
UPDATE items SET price=170000 WHERE item_code='807' AND price=155000; -- چای زعفران - کوچک
UPDATE items SET price=755000 WHERE item_code='1314' AND price=655000; -- برگر گوشت
UPDATE items SET price=355000 WHERE item_code='1112' AND price=345000; -- شیک توت فرنگی
UPDATE items SET price=240000 WHERE item_code='1007' AND price=230000; -- فیمتو
UPDATE items SET price=360000 WHERE item_code='711' AND price=340000; -- دمنوش آویشن - بزرگ
UPDATE items SET price=120000 WHERE item_code='901' AND price=110000; -- اسپرسو تک
UPDATE items SET price=195000 WHERE item_code='709' AND price=170000; -- دمنوش طلا - کوچک
UPDATE items SET price=355000 WHERE item_code='1109' AND price=340000; -- شیک انبه
UPDATE items SET price=180000 WHERE item_code='902' AND price=160000; -- اسپرسو دبل
UPDATE items SET price=330000 WHERE item_code='808' AND price=315000; -- چای زعفران - بزرگ
UPDATE items SET price=180000 WHERE item_code='705' AND price=160000; -- دمنوش آویشن - کوچک
UPDATE items SET price=155000 WHERE item_code='203' AND price=145000; -- نوشابه شانی قوطی
UPDATE items SET price=180000 WHERE item_code='707' AND price=160000; -- دمنوش گل نوش - کوچک
UPDATE items SET price=105000 WHERE item_code='212' AND price=95000; -- نوشابه کوکا زیرو قوطی
UPDATE items SET price=155000 WHERE item_code='211' AND price=145000; -- نوشابه میراندا قوطی
UPDATE items SET price=360000 WHERE item_code='708' AND price=340000; -- دمنوش گل نوش - بزرگ
UPDATE items SET price=310000 WHERE item_code='1105' AND price=285000; -- شیک اسپرسو
UPDATE items SET price=220000 WHERE item_code='1012' AND price=195000; -- آب طالبی
UPDATE items SET price=345000 WHERE item_code='1107' AND price=315000; -- شیک پینات
UPDATE items SET price=335000 WHERE item_code='1104' AND price=315000; -- شیک لوتوس
UPDATE items SET price=155000 WHERE item_code='202' AND price=145000; -- نوشابه دیو قوطی
UPDATE items SET price=265000 WHERE item_code='1005' AND price=255000; -- موهیتو
UPDATE items SET price=275000 WHERE item_code='1016' AND price=265000; -- بلو موهیتو
UPDATE items SET price=240000 WHERE item_code='1006' AND price=225000; -- لیموناد
UPDATE items SET price=215000 WHERE item_code='1011' AND price=190000; -- آب هندوانه
UPDATE items SET price=265000 WHERE item_code='905' AND price=245000; -- آفوگاتو
UPDATE items SET price=95000 WHERE item_code='207' AND price=85000; -- آب گاز دار لیمویی
UPDATE items SET price=235000 WHERE item_code='1009' AND price=220000; -- شیر موز
UPDATE items SET price=270000 WHERE item_code='1010' AND price=255000; -- شیر موز بستنی
UPDATE items SET price=380000 WHERE item_code='1110' AND price=360000; -- اسموتی انبه
UPDATE items SET price=315000 WHERE item_code='1106' AND price=290000; -- شیک فیمتو
UPDATE items SET price=155000 WHERE item_code='201' AND price=145000; -- نوشابه پپسی قوطی
UPDATE items SET price=255000 WHERE item_code='1008' AND price=245000; -- فیمتو اسپرایت
UPDATE items SET price=345000 WHERE item_code='1310' AND price=335000; -- اسنک مرغ
UPDATE items SET price=395000 WHERE item_code='1111' AND price=385000; -- اسموتی منگو بری
UPDATE items SET price=105000 WHERE item_code='208' AND price=95000; -- نوشابه کوکا قوطی
UPDATE items SET price=390000 WHERE item_code='710' AND price=370000; -- دمنوش طلا - بزرگ
UPDATE items SET price=360000 WHERE item_code='712' AND price=340000; -- دمنوش آرام دل - بزرگ
UPDATE items SET price=355000 WHERE item_code='1101' AND price=345000; -- شیک ارده
UPDATE items SET price=155000 WHERE item_code='214' AND price=145000; -- نوشابه سون آپ قوطی
UPDATE items SET price=315000 WHERE item_code='1108' AND price=270000; -- شیک نسکافه

-- 1019 — آیس دالگونا
INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order)
SELECT '1019',c.id,'آیس دالگونا','',210000,NULL,1,1,0,0,'menu_item',1,'cold_bar',190 FROM categories c
WHERE c.category_key='cold-drinks' AND NOT EXISTS (SELECT 1 FROM items i WHERE i.item_code='1019');
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1019' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='main' AND c.category_key='cold-drinks';
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1019' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='breakfast' AND c.category_key='cold-drinks';
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1019' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='lunch' AND c.category_key='cold-drinks';

-- 1318 — چاپاتای مرغ
INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order)
SELECT '1318',c.id,'چاپاتای مرغ','',390000,NULL,1,1,0,0,'menu_item',1,'kitchen',200 FROM categories c
WHERE c.category_key='finger-food' AND NOT EXISTS (SELECT 1 FROM items i WHERE i.item_code='1318');
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1318' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='main' AND c.category_key='finger-food';

-- 1113 — شیک پروتئینی
INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order)
SELECT '1113',c.id,'شیک پروتئینی','',395000,NULL,1,1,0,0,'menu_item',1,'cold_bar',110 FROM categories c
WHERE c.category_key='shakes-smoothies' AND NOT EXISTS (SELECT 1 FROM items i WHERE i.item_code='1113');
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1113' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='main' AND c.category_key='shakes-smoothies';

-- 1018 — رد موهیتو
INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order)
SELECT '1018',c.id,'رد موهیتو','',275000,NULL,1,1,0,0,'menu_item',1,'cold_bar',200 FROM categories c
WHERE c.category_key='cold-drinks' AND NOT EXISTS (SELECT 1 FROM items i WHERE i.item_code='1018');
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1018' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='main' AND c.category_key='cold-drinks';
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1018' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='breakfast' AND c.category_key='cold-drinks';
INSERT IGNORE INTO menu_items(menu_id,item_id)
SELECT m.id,i.id FROM menus m JOIN items i ON i.item_code='1018' JOIN categories c ON c.id=i.category_id
WHERE m.menu_key='lunch' AND c.category_key='cold-drinks';
