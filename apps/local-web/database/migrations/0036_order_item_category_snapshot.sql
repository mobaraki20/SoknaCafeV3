-- H122: immutable order-time category snapshot for truthful item/category contribution reporting.
ALTER TABLE order_items
    ADD COLUMN category_id_snapshot INT UNSIGNED NULL AFTER item_id,
    ADD COLUMN category_name_snapshot VARCHAR(160) NULL AFTER category_id_snapshot,
    ADD COLUMN category_snapshot_quality VARCHAR(24) NOT NULL DEFAULT 'unknown' AFTER category_name_snapshot;

UPDATE order_items oi
LEFT JOIN items i ON i.id=oi.item_id
LEFT JOIN categories c ON c.id=i.category_id
SET oi.category_id_snapshot=i.category_id,
    oi.category_name_snapshot=c.name,
    oi.category_snapshot_quality=CASE WHEN i.id IS NULL OR c.id IS NULL THEN 'unknown' ELSE 'migrated_current' END
WHERE oi.category_snapshot_quality='unknown';

ALTER TABLE order_items
    ADD CONSTRAINT ck_order_item_category_snapshot_quality
    CHECK (category_snapshot_quality IN ('exact_order','migrated_current','unknown')),
    ADD INDEX idx_order_items_category_snapshot (category_id_snapshot,order_id);
