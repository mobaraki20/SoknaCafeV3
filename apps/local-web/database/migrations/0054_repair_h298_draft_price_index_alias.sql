-- H300 forward-recovery bridge for the originally-published H298 migration 0055.
--
-- Facts from the real upgrade lineage:
-- * 0049 replaced uq_m53_draft_item_mode with uq_m53_draft_item_mode_price.
-- * H298/0055 statement 57 still drops the old uq_m53_draft_item_mode name.
-- * H298/0055 statement 58 creates uq_h297_draft_item_mode_choice WITHOUT
--   unit_price_snapshot. Executing that definition would regress open-order
--   price segmentation and can fail when the same item exists at two prices.
--
-- Keep 0055 byte-immutable. Instead, make its two historical DDL statements
-- safely replayable by the base 229c migration engine:
-- 1) recreate the legacy name so statement 57 can drop it;
-- 2) pre-create statement 58's index NAME with the CORRECT price+choice columns;
-- 3) pre-journal canonical H298 statement 58 as RUNNING. When 0055 reaches it,
--    MariaDB returns duplicate-index-name (1061); the migration engine is then
--    allowed to reconcile that already-journaled RUNNING statement.
CREATE INDEX IF NOT EXISTS uq_m53_draft_item_mode
    ON table_draft_items(draft_id,item_id,fulfillment_mode,unit_price_snapshot);

CREATE UNIQUE INDEX IF NOT EXISTS uq_h297_draft_item_mode_choice
    ON table_draft_items(draft_id,item_id,fulfillment_mode,unit_price_snapshot,bundle_choice_item_id);

INSERT IGNORE INTO schema_migration_statements(version,ordinal_no,statement_sha256,state)
VALUES('0055_menu_variant_suggestion_bundle',58,'1e2ca47b4c95bd0e514fa4e0f5ade91bddc7600e5c41d9f610e13f978e37dc56','running');
