-- H300 canonical repair after H298/H299 menu variant rollout.
-- 0055 remains byte-identical to the originally published H298 migration so
-- migration history stays immutable. This forward-only migration normalizes
-- the draft-line uniqueness rule to preserve BOTH price snapshot segmentation
-- and bundle choice identity.
DROP INDEX IF EXISTS uq_h297_draft_item_mode_choice ON table_draft_items;
DROP INDEX IF EXISTS uq_h299_draft_item_mode_price_choice ON table_draft_items;
DROP INDEX IF EXISTS uq_m53_draft_item_mode_price ON table_draft_items;
DROP INDEX IF EXISTS uq_m53_draft_item_mode ON table_draft_items;
CREATE UNIQUE INDEX IF NOT EXISTS uq_h300_draft_item_mode_price_choice
    ON table_draft_items(draft_id,item_id,fulfillment_mode,unit_price_snapshot,bundle_choice_item_id);
