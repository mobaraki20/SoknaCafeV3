CREATE TABLE operations_command_clock (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 `cursor` BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- `cursor`-only business actions (e.g. buying without delivery) must invalidate the model too.
INSERT INTO operations_command_clock(id,`cursor`) VALUES(1,0);
CREATE TABLE operations_command_journal (
 request_id VARCHAR(96) NOT NULL PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 request_hash CHAR(64) NOT NULL,
 `cursor` BIGINT UNSIGNED NOT NULL UNIQUE,
 result_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inventory_supply_purchases (
 purchase_id VARCHAR(96) NOT NULL PRIMARY KEY,
 group_key VARCHAR(512) NULL,
 item_id BIGINT UNSIGNED NULL,
 item_name VARCHAR(160) NOT NULL,
 base_unit VARCHAR(8) NOT NULL,
 quantity_base BIGINT NOT NULL,
 total_cost BIGINT NULL,
 supplier VARCHAR(160) NULL,
 note VARCHAR(500) NULL,
 payload_json LONGTEXT NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 purchased_at DATETIME NOT NULL,
 delivered_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 cancellation_reason VARCHAR(500) NULL,
 receipt_id BIGINT UNSIGNED NULL,
 KEY supply_purchase_delivery(delivered_at,purchased_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER tr_daily_command_projection AFTER INSERT ON operations_command_journal FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('operations.daily','operations_command',NEW.request_id,'operations_workspace,deferred_context');
