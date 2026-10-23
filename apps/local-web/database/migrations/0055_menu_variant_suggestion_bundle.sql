-- H297 / Menu Product Model V2 — Variant, Suggestion, Bundle and order snapshot domain.;

CREATE TABLE IF NOT EXISTS item_variant_families (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 family_key VARCHAR(80) NOT NULL,
 category_id INT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 description TEXT NULL,
 image_path VARCHAR(255) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 featured TINYINT(1) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_h297_variant_family_key(family_key),
 CONSTRAINT fk_h297_variant_family_category FOREIGN KEY(category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
 INDEX idx_h297_variant_family_category(category_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_variant_members (
 family_id INT UNSIGNED NOT NULL,
 item_id INT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(family_id,item_id),
 UNIQUE KEY uq_h297_variant_item(item_id),
 CONSTRAINT fk_h297_variant_member_family FOREIGN KEY(family_id) REFERENCES item_variant_families(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_h297_variant_member_item FOREIGN KEY(item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
 INDEX idx_h297_variant_member_order(family_id,sort_order,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_suggestions (
 source_item_id INT UNSIGNED NOT NULL,
 suggested_item_id INT UNSIGNED NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(source_item_id,suggested_item_id),
 CONSTRAINT fk_h297_suggestion_source FOREIGN KEY(source_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_h297_suggestion_target FOREIGN KEY(suggested_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
 INDEX idx_h297_suggestion_source(source_item_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_bundles (
 bundle_item_id INT UNSIGNED PRIMARY KEY,
 choice_label VARCHAR(160) NULL,
 choice_min TINYINT UNSIGNED NOT NULL DEFAULT 1,
 choice_max TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_h297_bundle_item FOREIGN KEY(bundle_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT ck_h297_bundle_choice CHECK(choice_min<=1 AND choice_max<=1 AND choice_min<=choice_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_bundle_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 bundle_item_id INT UNSIGNED NOT NULL,
 component_item_id INT UNSIGNED NOT NULL,
 mode VARCHAR(16) NOT NULL DEFAULT 'fixed',
 quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 price_delta BIGINT NOT NULL DEFAULT 0,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_h297_bundle_component(bundle_item_id,component_item_id,mode),
 CONSTRAINT fk_h297_bundle_component_bundle FOREIGN KEY(bundle_item_id) REFERENCES item_bundles(bundle_item_id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_h297_bundle_component_item FOREIGN KEY(component_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
 CONSTRAINT ck_h297_bundle_mode CHECK(mode IN ('fixed','choice')),
 INDEX idx_h297_bundle_components(bundle_item_id,mode,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_bundle_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_item_id BIGINT UNSIGNED NOT NULL,
 component_item_id INT UNSIGNED NULL,
 item_name_snapshot VARCHAR(160) NOT NULL,
 quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 preparation_station_snapshot VARCHAR(30) NOT NULL DEFAULT 'none',
 component_mode VARCHAR(16) NOT NULL DEFAULT 'fixed',
 choice_label_snapshot VARCHAR(160) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_h297_order_bundle_parent FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_h297_order_bundle_component_item FOREIGN KEY(component_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE SET NULL,
 CONSTRAINT ck_h297_order_bundle_mode CHECK(component_mode IN ('fixed','choice')),
 INDEX idx_h297_order_bundle_parent(order_item_id,id),
 INDEX idx_h297_order_bundle_component(component_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO item_suggestions(source_item_id,suggested_item_id,sort_order,active)
 SELECT id,suggested_item_id,10,1 FROM items WHERE suggested_item_id IS NOT NULL AND suggested_item_id<>id;

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-01',i.category_id,'اسپرسو',10,0,1 FROM items i WHERE i.item_code='901' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-01');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'تک',10 FROM item_variant_families f JOIN items i ON i.item_code='901' WHERE f.family_key='family-01';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'دبل',20 FROM item_variant_families f JOIN items i ON i.item_code='902' WHERE f.family_key='family-01';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-02',i.category_id,'آمریکانو',20,0,1 FROM items i WHERE i.item_code='903' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-02');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'تک',10 FROM item_variant_families f JOIN items i ON i.item_code='903' WHERE f.family_key='family-02';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'دبل',20 FROM item_variant_families f JOIN items i ON i.item_code='904' WHERE f.family_key='family-02';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-03',i.category_id,'چای ساده',30,0,1 FROM items i WHERE i.item_code='801' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-03');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='801' WHERE f.family_key='family-03';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='802' WHERE f.family_key='family-03';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-04',i.category_id,'چای مراکش',40,0,1 FROM items i WHERE i.item_code='803' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-04');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='803' WHERE f.family_key='family-04';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='804' WHERE f.family_key='family-04';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-05',i.category_id,'چای هل و دارچین',50,0,1 FROM items i WHERE i.item_code='805' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-05');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='805' WHERE f.family_key='family-05';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='806' WHERE f.family_key='family-05';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-06',i.category_id,'چای زعفران',60,0,1 FROM items i WHERE i.item_code='807' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-06');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='807' WHERE f.family_key='family-06';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='808' WHERE f.family_key='family-06';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-07',i.category_id,'دمنوش فرش قرمز',70,0,1 FROM items i WHERE i.item_code='701' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-07');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='701' WHERE f.family_key='family-07';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='713' WHERE f.family_key='family-07';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-08',i.category_id,'دمنوش آرام دل',80,0,1 FROM items i WHERE i.item_code='703' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-08');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='703' WHERE f.family_key='family-08';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='712' WHERE f.family_key='family-08';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-09',i.category_id,'دمنوش آویشن',90,0,1 FROM items i WHERE i.item_code='705' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-09');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='705' WHERE f.family_key='family-09';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='711' WHERE f.family_key='family-09';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-10',i.category_id,'دمنوش گل نوش',100,0,1 FROM items i WHERE i.item_code='707' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-10');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='707' WHERE f.family_key='family-10';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='708' WHERE f.family_key='family-10';

INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active) SELECT 'family-11',i.category_id,'دمنوش طلا',110,0,1 FROM items i WHERE i.item_code='709' AND NOT EXISTS(SELECT 1 FROM item_variant_families f WHERE f.family_key='family-11');

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'کوچک',10 FROM item_variant_families f JOIN items i ON i.item_code='709' WHERE f.family_key='family-11';

INSERT IGNORE INTO item_variant_members(family_id,item_id,label,sort_order) SELECT f.id,i.id,'بزرگ',20 FROM item_variant_families f JOIN items i ON i.item_code='710' WHERE f.family_key='family-11';

CREATE TRIGGER tr_h297_item_variant_famil_ai AFTER INSERT ON item_variant_families FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_families.insert','variant_family',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_variant_famil_au AFTER UPDATE ON item_variant_families FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_families.update','variant_family',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_variant_famil_ad AFTER DELETE ON item_variant_families FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_families.delete','variant_family',CAST(OLD.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_variant_membe_ai AFTER INSERT ON item_variant_members FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_members.insert','variant_member',CONCAT(NEW.family_id,':',NEW.item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_variant_membe_au AFTER UPDATE ON item_variant_members FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_members.update','variant_member',CONCAT(NEW.family_id,':',NEW.item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_variant_membe_ad AFTER DELETE ON item_variant_members FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_variant_members.delete','variant_member',CONCAT(OLD.family_id,':',OLD.item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_suggestions_ai AFTER INSERT ON item_suggestions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_suggestions.insert','item_suggestion',CONCAT(NEW.source_item_id,':',NEW.suggested_item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_suggestions_au AFTER UPDATE ON item_suggestions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_suggestions.update','item_suggestion',CONCAT(NEW.source_item_id,':',NEW.suggested_item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_suggestions_ad AFTER DELETE ON item_suggestions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_suggestions.delete','item_suggestion',CONCAT(OLD.source_item_id,':',OLD.suggested_item_id),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundles_ai AFTER INSERT ON item_bundles FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundles.insert','item_bundle',CAST(NEW.bundle_item_id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundles_au AFTER UPDATE ON item_bundles FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundles.update','item_bundle',CAST(NEW.bundle_item_id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundles_ad AFTER DELETE ON item_bundles FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundles.delete','item_bundle',CAST(OLD.bundle_item_id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundle_compon_ai AFTER INSERT ON item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundle_components.insert','bundle_component',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundle_compon_au AFTER UPDATE ON item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundle_components.update','bundle_component',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h297_item_bundle_compon_ad AFTER DELETE ON item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('item_bundle_components.delete','bundle_component',CAST(OLD.id AS CHAR),'operations,table_draft_context');

ALTER TABLE table_draft_items ADD COLUMN bundle_choice_item_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER fulfillment_mode;

ALTER TABLE table_draft_items DROP INDEX uq_m53_draft_item_mode;

ALTER TABLE table_draft_items ADD UNIQUE KEY uq_h297_draft_item_mode_choice(draft_id,item_id,fulfillment_mode,bundle_choice_item_id);

CREATE TRIGGER tr_h297_order_bundle_comp_ai AFTER INSERT ON order_item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_bundle_components.insert','order_bundle_component',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h297_order_bundle_comp_au AFTER UPDATE ON order_item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_bundle_components.update','order_bundle_component',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h297_order_bundle_comp_ad AFTER DELETE ON order_item_bundle_components FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_bundle_components.delete','order_bundle_component',CAST(OLD.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');
