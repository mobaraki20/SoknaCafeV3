-- Preserve the original order quantity and receipt; a derived bundle row has
-- its own per-unit component snapshot and explicit original order-row identity.
-- No inference or backfill of historical ambiguous splits.
ALTER TABLE order_items
    ADD COLUMN bundle_distribution_origin_id BIGINT UNSIGNED NULL,
    ADD INDEX idx_bundle_distribution_origin (order_id,bundle_distribution_origin_id);
-- One economic correction can have independent preparation acknowledgements.
-- Secondary rows never represent an additional inventory or financial delta.
ALTER TABLE order_item_adjustments
    ADD COLUMN parent_adjustment_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_adjustment_secondary_area (parent_adjustment_id,area_key);
