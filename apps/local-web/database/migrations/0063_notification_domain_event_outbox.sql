-- H333 / A07: durable notification-domain events captured in the same InnoDB transaction as business changes.
CREATE TABLE IF NOT EXISTS notification_domain_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(120) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    payload_json JSON NOT NULL,
    dispatched_at DATETIME NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(300) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_h333_notification_domain_event_key(event_key),
    INDEX idx_h333_notification_domain_dispatch(dispatched_at,id),
    INDEX idx_h333_notification_domain_type(event_type,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS tr_h333_orders_notification_ai;
CREATE TRIGGER tr_h333_orders_notification_ai AFTER INSERT ON orders FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('guest_order.new.',NEW.id),'guest_order.new','order',NEW.id,NEW.created_at,JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.order_source='guest' AND NEW.status='new' UNION ALL SELECT CONCAT('preparation.accounted.',NEW.id),'preparation.accounted','order',NEW.id,COALESCE(NEW.accepted_at,NEW.created_at),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='accounted';

DROP TRIGGER IF EXISTS tr_h333_orders_notification_au;
CREATE TRIGGER tr_h333_orders_notification_au AFTER UPDATE ON orders FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('guest_order.new.',NEW.id),'guest_order.new','order',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.order_source='guest' AND NEW.status='new' AND OLD.status<>'new' UNION ALL SELECT CONCAT('preparation.accounted.',NEW.id),'preparation.accounted','order',NEW.id,COALESCE(NEW.accepted_at,UTC_TIMESTAMP()),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='accounted' AND OLD.status<>'accounted';

DROP TRIGGER IF EXISTS tr_h333_waiter_calls_notification_ai;
CREATE TRIGGER tr_h333_waiter_calls_notification_ai AFTER INSERT ON waiter_calls FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('waiter_call.new.',NEW.id),'waiter_call.new','waiter_call',NEW.id,NEW.created_at,JSON_OBJECT('table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='new';

DROP TRIGGER IF EXISTS tr_h333_waiter_calls_notification_au;
CREATE TRIGGER tr_h333_waiter_calls_notification_au AFTER UPDATE ON waiter_calls FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('waiter_call.new.',NEW.id),'waiter_call.new','waiter_call',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='new' AND OLD.status<>'new';

DROP TRIGGER IF EXISTS tr_h333_settlement_notification_ai;
CREATE TRIGGER tr_h333_settlement_notification_ai AFTER INSERT ON settlement_records FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('settlement.completed.',NEW.id),'settlement.completed','settlement',NEW.id,NEW.settled_at,JSON_OBJECT('session_id',NEW.session_id,'total',NEW.total) WHERE NEW.status='completed';

DROP TRIGGER IF EXISTS tr_h333_settlement_notification_au;
CREATE TRIGGER tr_h333_settlement_notification_au AFTER UPDATE ON settlement_records FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('settlement.completed.',NEW.id),'settlement.completed','settlement',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('session_id',NEW.session_id,'total',NEW.total) WHERE NEW.status='completed' AND OLD.status<>'completed';

-- Continuity backfill: only events still within their original notification TTL are relevant.
INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json)
SELECT CONCAT('guest_order.new.',o.id),'guest_order.new','order',o.id,o.created_at,JSON_OBJECT('business_order_number',o.business_order_number,'table_id',o.table_id,'session_id',o.session_id)
FROM orders o WHERE o.order_source='guest' AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND);

INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json)
SELECT CONCAT('preparation.accounted.',o.id),'preparation.accounted','order',o.id,COALESCE(o.accepted_at,o.updated_at,o.created_at),JSON_OBJECT('business_order_number',o.business_order_number,'table_id',o.table_id,'session_id',o.session_id)
FROM orders o WHERE o.status='accounted' AND COALESCE(o.accepted_at,o.updated_at,o.created_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 600 SECOND);

INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json)
SELECT CONCAT('waiter_call.new.',w.id),'waiter_call.new','waiter_call',w.id,w.created_at,JSON_OBJECT('table_id',w.table_id,'session_id',w.session_id)
FROM waiter_calls w WHERE w.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 SECOND);

INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json)
SELECT CONCAT('settlement.completed.',sr.id),'settlement.completed','settlement',sr.id,sr.settled_at,JSON_OBJECT('session_id',sr.session_id,'total',sr.total)
FROM settlement_records sr WHERE sr.status='completed' AND sr.settled_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1800 SECOND);
