-- Iran time alignment: explicit UTC event instant, Tehran business clock.
SET time_zone = '+03:30';
-- Only still-undispatched, exactly-proven local source values are corrected.
-- No blanket historical time shift; order/ledger/source records remain untouched.
UPDATE notification_domain_events e JOIN orders o ON e.entity_type='order' AND e.entity_id=o.id
SET e.occurred_at=CONVERT_TZ(e.occurred_at,@@session.time_zone,'+00:00')
WHERE e.dispatched_at IS NULL AND
((e.event_type='guest_order.new' AND e.occurred_at=o.created_at) OR
 (e.event_type='preparation.accounted' AND e.occurred_at=COALESCE(o.accepted_at,o.created_at)));
UPDATE notification_domain_events e JOIN waiter_calls w ON e.entity_type='waiter_call' AND e.entity_id=w.id
SET e.occurred_at=CONVERT_TZ(e.occurred_at,@@session.time_zone,'+00:00')
WHERE e.dispatched_at IS NULL AND e.event_type='waiter_call.new' AND e.occurred_at=w.created_at;
UPDATE notification_domain_events e JOIN settlement_records s ON e.entity_type='settlement' AND e.entity_id=s.id
SET e.occurred_at=CONVERT_TZ(e.occurred_at,@@session.time_zone,'+00:00')
WHERE e.dispatched_at IS NULL AND e.event_type='settlement.completed' AND e.occurred_at=s.settled_at;

DROP TRIGGER IF EXISTS tr_h333_orders_notification_ai;

CREATE TRIGGER tr_h333_orders_notification_ai AFTER INSERT ON orders FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('guest_order.new.',NEW.id),'guest_order.new','order',NEW.id,CONVERT_TZ(NEW.created_at,@@session.time_zone,'+00:00'),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.order_source='guest' AND NEW.status='new' UNION ALL SELECT CONCAT('preparation.accounted.',NEW.id),'preparation.accounted','order',NEW.id,CONVERT_TZ(COALESCE(NEW.accepted_at,NEW.created_at),@@session.time_zone,'+00:00'),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='accounted';

DROP TRIGGER IF EXISTS tr_h333_orders_notification_au;

CREATE TRIGGER tr_h333_orders_notification_au AFTER UPDATE ON orders FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('guest_order.new.',NEW.id),'guest_order.new','order',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.order_source='guest' AND NEW.status='new' AND OLD.status<>'new' UNION ALL SELECT CONCAT('preparation.accounted.',NEW.id),'preparation.accounted','order',NEW.id,COALESCE(CONVERT_TZ(NEW.accepted_at,@@session.time_zone,'+00:00'),UTC_TIMESTAMP()),JSON_OBJECT('business_order_number',NEW.business_order_number,'table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='accounted' AND OLD.status<>'accounted';

DROP TRIGGER IF EXISTS tr_h333_waiter_calls_notification_ai;

CREATE TRIGGER tr_h333_waiter_calls_notification_ai AFTER INSERT ON waiter_calls FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('waiter_call.new.',NEW.id),'waiter_call.new','waiter_call',NEW.id,CONVERT_TZ(NEW.created_at,@@session.time_zone,'+00:00'),JSON_OBJECT('table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='new';

DROP TRIGGER IF EXISTS tr_h333_waiter_calls_notification_au;

CREATE TRIGGER tr_h333_waiter_calls_notification_au AFTER UPDATE ON waiter_calls FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('waiter_call.new.',NEW.id),'waiter_call.new','waiter_call',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('table_id',NEW.table_id,'session_id',NEW.session_id) WHERE NEW.status='new' AND OLD.status<>'new';

DROP TRIGGER IF EXISTS tr_h333_settlement_notification_ai;

CREATE TRIGGER tr_h333_settlement_notification_ai AFTER INSERT ON settlement_records FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('settlement.completed.',NEW.id),'settlement.completed','settlement',NEW.id,CONVERT_TZ(NEW.settled_at,@@session.time_zone,'+00:00'),JSON_OBJECT('session_id',NEW.session_id,'total',NEW.total) WHERE NEW.status='completed';

DROP TRIGGER IF EXISTS tr_h333_settlement_notification_au;

CREATE TRIGGER tr_h333_settlement_notification_au AFTER UPDATE ON settlement_records FOR EACH ROW INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) SELECT CONCAT('settlement.completed.',NEW.id),'settlement.completed','settlement',NEW.id,UTC_TIMESTAMP(),JSON_OBJECT('session_id',NEW.session_id,'total',NEW.total) WHERE NEW.status='completed' AND OLD.status<>'completed';
