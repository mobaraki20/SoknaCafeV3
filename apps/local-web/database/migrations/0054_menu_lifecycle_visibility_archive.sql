-- H297 / Menu Product Model V2 — lifecycle, channel visibility and archive foundation.
-- Historical rows are never deleted; legacy active/staff_only remain compatibility projections.
;

ALTER TABLE items ADD COLUMN lifecycle_status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER active;

ALTER TABLE items ADD COLUMN guest_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER lifecycle_status;

ALTER TABLE items ADD COLUMN staff_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_visible;

ALTER TABLE items ADD COLUMN retired_at DATETIME NULL AFTER staff_visible;

ALTER TABLE items ADD COLUMN retired_note TEXT NULL AFTER retired_at;

ALTER TABLE items ADD CONSTRAINT ck_h297_item_lifecycle CHECK (lifecycle_status IN ('active','inactive','retired'));

ALTER TABLE items ADD INDEX idx_h297_item_lifecycle (lifecycle_status,category_id,sort_order);

ALTER TABLE items ADD INDEX idx_h297_item_visibility (lifecycle_status,guest_visible,staff_visible,available);

ALTER TABLE categories ADD COLUMN lifecycle_status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER active;

ALTER TABLE categories ADD COLUMN guest_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER lifecycle_status;

ALTER TABLE categories ADD COLUMN staff_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER guest_visible;

ALTER TABLE categories ADD COLUMN retired_at DATETIME NULL AFTER staff_visible;

ALTER TABLE categories ADD COLUMN retired_note TEXT NULL AFTER retired_at;

ALTER TABLE categories ADD CONSTRAINT ck_h297_category_lifecycle CHECK (lifecycle_status IN ('active','inactive','retired'));

ALTER TABLE categories ADD INDEX idx_h297_category_lifecycle (lifecycle_status,sort_order);

UPDATE items SET lifecycle_status=IF(active=1,'active','inactive'),guest_visible=IF(staff_only=1,0,1),staff_visible=1 WHERE lifecycle_status='active' AND retired_at IS NULL;

UPDATE categories SET lifecycle_status=IF(active=1,'active','inactive'),guest_visible=IF(audience='staff_only',0,1),staff_visible=1 WHERE lifecycle_status='active' AND retired_at IS NULL;

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1312';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='606';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1309';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1004';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='205';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='804';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1102';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1019';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='604';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='807';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='209';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1314';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1112';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1007';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='703';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='210';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='805';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=0,staff_visible=1,staff_only=1,retired_at=NULL,retired_note=NULL WHERE item_code='1219';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='904';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1003';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1211';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=0,staff_visible=1,staff_only=1,retired_at=NULL,retired_note=NULL WHERE item_code='1217';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=0,staff_visible=1,staff_only=1,retired_at=NULL,retired_note=NULL WHERE item_code='1208';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1318';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='601';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='711';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1001';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='901';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='608';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='709';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1109';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='603';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='713';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='902';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='808';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='801';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1301';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='705';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1103';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1313';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1315';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='903';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='203';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='707';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='212';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='211';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='708';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1012';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1107';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='806';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='802';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1104';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='206';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1005';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='605';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1016';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1006';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='803';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=0,staff_visible=1,staff_only=1,retired_at=NULL,retired_note=NULL WHERE item_code='1017';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1011';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='701';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='905';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1305';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='207';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1002';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1009';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1010';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1110';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1106';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1018';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='607';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='201';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1008';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1310';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1111';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='118';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='208';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1317';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='710';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1220';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='712';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1101';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='214';

UPDATE items SET lifecycle_status='active',active=1,guest_visible=1,staff_visible=1,staff_only=0,retired_at=NULL,retired_note=NULL WHERE item_code='1108';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1316';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1214';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1213';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1202';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1308';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1304';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1201';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1207';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='602';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر؛ پیناکولادا نیاز تأمین متمایز دارد و جک‌ها با خانواده موهیتو هم‌پوشانی دارند؛ برای کاهش شلوغی غیرفعال. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1013';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1218';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='104';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر؛ شیک اسپرسو با گزینه‌های قهوه‌ای هم‌پوشان و شیک پروتئینی ماده اختصاصیِ ثبت‌شده ندارد. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1113';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1311';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1216';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='103';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='108';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1303';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1206';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1204';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='109';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر؛ شیک اسپرسو با گزینه‌های قهوه‌ای هم‌پوشان و شیک پروتئینی ماده اختصاصیِ ثبت‌شده ندارد. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1105';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='117';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1209';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر در نوشیدنی آماده؛ SKU اضافی و بدون نقش روشن. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='202';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='106';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر در نوشیدنی آماده؛ SKU اضافی و بدون نقش روشن. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='213';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='301';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='102';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1205';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر؛ پیناکولادا نیاز تأمین متمایز دارد و جک‌ها با خانواده موهیتو هم‌پوشانی دارند؛ برای کاهش شلوغی غیرفعال. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1015';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='107';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='114';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر؛ پیناکولادا نیاز تأمین متمایز دارد و جک‌ها با خانواده موهیتو هم‌پوشانی دارند؛ برای کاهش شلوغی غیرفعال. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1014';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='110';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='116';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='101';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1215';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='112';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1302';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='302';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1210';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='115';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1307';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1306';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='111';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'فروش صفر در نوشیدنی آماده؛ SKU اضافی و بدون نقش روشن. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='204';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1203';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'باقی‌مانده دوره کافه‌رستوران؛ سکنا اکنون فقط کافه است. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='113';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'در دوره بررسی فروش صفر و در عرضه/خرید جاری شواهد کافی ندارد؛ از منوی جاری خارج بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='1212';

UPDATE items SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,staff_only=1,available=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'از قبل غیرفعال/قدیمی؛ غیرفعال بماند. Baseline Revision 2: در آرشیو Retired نگهداری شود؛ Delete نشود.') WHERE item_code='105';

UPDATE categories SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'Legacy cafe-restaurant category; archived by Menu Product Model V2') WHERE name='خوراک';

UPDATE categories SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'Legacy cafe-restaurant category; archived by Menu Product Model V2') WHERE name='غذای اصلی';

UPDATE categories SET lifecycle_status='retired',active=0,guest_visible=0,staff_visible=0,retired_at=COALESCE(retired_at,NOW()),retired_note=COALESCE(retired_note,'Legacy cafe-restaurant category; archived by Menu Product Model V2') WHERE name='ترشیجات';
