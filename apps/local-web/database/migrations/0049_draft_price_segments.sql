-- H222 — preserve draft price snapshots across catalog price changes.
-- A saved draft may contain the same item/service mode at the old and current
-- catalog price as two explicit financial segments. Existing quantities keep
-- their recorded price; only newly added quantities use the current price.

ALTER TABLE table_draft_items
    DROP INDEX uq_m53_draft_item_mode,
    ADD UNIQUE KEY uq_m53_draft_item_mode_price (draft_id,item_id,fulfillment_mode,unit_price_snapshot);
