-- H242 / P2A — transactional projection invalidation outbox.

-- Each trigger is a single SQL statement so the canonical migration splitter can journal it.

-- The outbox is Local-owned; Public only receives signed event batches + read-model projections.

CREATE TABLE IF NOT EXISTS public_projection_outbox (
    sequence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(96) NOT NULL,
    aggregate_type VARCHAR(48) NOT NULL,
    aggregate_id VARCHAR(96) NOT NULL,
    model_keys VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    INDEX idx_public_projection_outbox_pending (delivered_at,sequence_id),
    INDEX idx_public_projection_outbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER tr_h242_cafe_tables_ai AFTER INSERT ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.insert','table',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_cafe_tables_au AFTER UPDATE ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.update','table',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_cafe_tables_ad AFTER DELETE ON cafe_tables FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('cafe_tables.delete','table',CAST(OLD.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_table_sessions_ai AFTER INSERT ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.insert','session',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_table_sessions_au AFTER UPDATE ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.update','session',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_table_sessions_ad AFTER DELETE ON table_sessions FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_sessions.delete','session',CAST(OLD.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_orders_ai AFTER INSERT ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.insert','order',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_orders_au AFTER UPDATE ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.update','order',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_orders_ad AFTER DELETE ON orders FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('orders.delete','order',CAST(OLD.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_order_items_ai AFTER INSERT ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.insert','order_item',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_order_items_au AFTER UPDATE ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.update','order_item',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_order_items_ad AFTER DELETE ON order_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_items.delete','order_item',CAST(OLD.id AS CHAR),'operations,table_draft_context,preparation,guest_operational');

CREATE TRIGGER tr_h242_order_item_adjustments_ai AFTER INSERT ON order_item_adjustments FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_adjustments.insert','order_adjustment',CAST(NEW.id AS CHAR),'operations,preparation');

CREATE TRIGGER tr_h242_order_item_adjustments_au AFTER UPDATE ON order_item_adjustments FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_adjustments.update','order_adjustment',CAST(NEW.id AS CHAR),'operations,preparation');

CREATE TRIGGER tr_h242_order_item_adjustments_ad AFTER DELETE ON order_item_adjustments FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_item_adjustments.delete','order_adjustment',CAST(OLD.id AS CHAR),'operations,preparation');

CREATE TRIGGER tr_h242_table_drafts_ai AFTER INSERT ON table_drafts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_drafts.insert','table_draft',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_table_drafts_au AFTER UPDATE ON table_drafts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_drafts.update','table_draft',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_table_drafts_ad AFTER DELETE ON table_drafts FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_drafts.delete','table_draft',CAST(OLD.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_table_draft_items_ai AFTER INSERT ON table_draft_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_draft_items.insert','table_draft_item',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_table_draft_items_au AFTER UPDATE ON table_draft_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_draft_items.update','table_draft_item',CAST(NEW.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_table_draft_items_ad AFTER DELETE ON table_draft_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_draft_items.delete','table_draft_item',CAST(OLD.id AS CHAR),'operations,table_draft_context');

CREATE TRIGGER tr_h242_waiter_calls_ai AFTER INSERT ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.insert','waiter_call',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_waiter_calls_au AFTER UPDATE ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.update','waiter_call',CAST(NEW.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_waiter_calls_ad AFTER DELETE ON waiter_calls FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('waiter_calls.delete','waiter_call',CAST(OLD.id AS CHAR),'operations,table_draft_context,guest_operational');

CREATE TRIGGER tr_h242_order_preparation_claims_ai AFTER INSERT ON order_preparation_claims FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_preparation_claims.insert','preparation_claim',CONCAT(NEW.order_id,':',NEW.area_key),'preparation');

CREATE TRIGGER tr_h242_order_preparation_claims_au AFTER UPDATE ON order_preparation_claims FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_preparation_claims.update','preparation_claim',CONCAT(NEW.order_id,':',NEW.area_key),'preparation');

CREATE TRIGGER tr_h242_order_preparation_claims_ad AFTER DELETE ON order_preparation_claims FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('order_preparation_claims.delete','preparation_claim',CONCAT(OLD.order_id,':',OLD.area_key),'preparation');

CREATE TRIGGER tr_h242_items_ai AFTER INSERT ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.insert','item',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation');

CREATE TRIGGER tr_h242_items_au AFTER UPDATE ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.update','item',CAST(NEW.id AS CHAR),'operations,table_draft_context,preparation');

CREATE TRIGGER tr_h242_items_ad AFTER DELETE ON items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('items.delete','item',CAST(OLD.id AS CHAR),'operations,table_draft_context,preparation');

CREATE TRIGGER tr_h242_categories_ai AFTER INSERT ON categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('categories.insert','category',CAST(NEW.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_categories_au AFTER UPDATE ON categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('categories.update','category',CAST(NEW.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_categories_ad AFTER DELETE ON categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('categories.delete','category',CAST(OLD.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_menus_ai AFTER INSERT ON menus FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menus.insert','menu',CAST(NEW.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_menus_au AFTER UPDATE ON menus FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menus.update','menu',CAST(NEW.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_menus_ad AFTER DELETE ON menus FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menus.delete','menu',CAST(OLD.id AS CHAR),'table_draft_context');

CREATE TRIGGER tr_h242_menu_items_ai AFTER INSERT ON menu_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_items.insert','menu_item',CONCAT(NEW.menu_id,':',NEW.item_id),'table_draft_context');

CREATE TRIGGER tr_h242_menu_items_au AFTER UPDATE ON menu_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_items.update','menu_item',CONCAT(NEW.menu_id,':',NEW.item_id),'table_draft_context');

CREATE TRIGGER tr_h242_menu_items_ad AFTER DELETE ON menu_items FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_items.delete','menu_item',CONCAT(OLD.menu_id,':',OLD.item_id),'table_draft_context');

CREATE TRIGGER tr_h242_menu_categories_ai AFTER INSERT ON menu_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_categories.insert','menu_category',CONCAT(NEW.menu_id,':',NEW.category_id),'table_draft_context');

CREATE TRIGGER tr_h242_menu_categories_au AFTER UPDATE ON menu_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_categories.update','menu_category',CONCAT(NEW.menu_id,':',NEW.category_id),'table_draft_context');

CREATE TRIGGER tr_h242_menu_categories_ad AFTER DELETE ON menu_categories FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('menu_categories.delete','menu_category',CONCAT(OLD.menu_id,':',OLD.category_id),'table_draft_context');


CREATE TRIGGER tr_h242_table_session_clients_ai AFTER INSERT ON table_session_clients FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_session_clients.insert','session_client',CAST(NEW.id AS CHAR),'guest_operational');

CREATE TRIGGER tr_h242_table_session_clients_au AFTER UPDATE ON table_session_clients FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_session_clients.update','session_client',CAST(NEW.id AS CHAR),'guest_operational');

CREATE TRIGGER tr_h242_table_session_clients_ad AFTER DELETE ON table_session_clients FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('table_session_clients.delete','session_client',CAST(OLD.id AS CHAR),'guest_operational');

CREATE TRIGGER tr_h242_settings_ai AFTER INSERT ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) SELECT 'settings.insert','setting',NEW.setting_key,'guest_operational' FROM DUAL WHERE NEW.setting_key IN('orders_accepting.cafe','orders_accepting.kitchen','orders_accepting.bar','waiter_call_enabled','public_waiter_call_enabled','new_device_alert_minutes');

CREATE TRIGGER tr_h242_settings_au AFTER UPDATE ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) SELECT 'settings.update','setting',NEW.setting_key,'guest_operational' FROM DUAL WHERE NEW.setting_key IN('orders_accepting.cafe','orders_accepting.kitchen','orders_accepting.bar','waiter_call_enabled','public_waiter_call_enabled','new_device_alert_minutes');

CREATE TRIGGER tr_h242_settings_ad AFTER DELETE ON settings FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) SELECT 'settings.delete','setting',OLD.setting_key,'guest_operational' FROM DUAL WHERE OLD.setting_key IN('orders_accepting.cafe','orders_accepting.kitchen','orders_accepting.bar','waiter_call_enabled','public_waiter_call_enabled','new_device_alert_minutes');

CREATE TRIGGER tr_h242_settlement_records_ai AFTER INSERT ON settlement_records FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_records.insert','settlement',CAST(NEW.id AS CHAR),'operations,reports');

CREATE TRIGGER tr_h242_settlement_records_au AFTER UPDATE ON settlement_records FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_records.update','settlement',CAST(NEW.id AS CHAR),'operations,reports');

CREATE TRIGGER tr_h242_settlement_records_ad AFTER DELETE ON settlement_records FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('settlement_records.delete','settlement',CAST(OLD.id AS CHAR),'operations,reports');
