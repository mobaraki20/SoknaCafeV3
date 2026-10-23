-- H324 direct-upgrade recovery bridge for pre-H297/H298 databases.
--
-- Problem: 0054_repair_h298_draft_price_index_alias was introduced later than
-- 0055_menu_variant_suggestion_bundle and assumes 0055 already added
-- table_draft_items.bundle_choice_item_id. On a direct cumulative upgrade from
-- 229b, lexical migration order executes the repair before 0055.
--
-- This bridge is intentionally ordered before both 0054_menu... and 0054_repair...:
-- 1) add the prerequisite column idempotently;
-- 2) pre-journal canonical 0055 statement 56 as RUNNING. When 0055 later attempts
--    the same ADD COLUMN, MariaDB duplicate-column 1060 is safely reconciled by
--    the existing statement journal logic. Canonical 0055 bytes stay immutable.
ALTER TABLE table_draft_items
    ADD COLUMN IF NOT EXISTS bundle_choice_item_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER fulfillment_mode;

INSERT IGNORE INTO schema_migration_statements(version,ordinal_no,statement_sha256,state)
VALUES('0055_menu_variant_suggestion_bundle',56,'828468b8f58ed98ccf5a180920eac292a4ef729c3c4a8134a48a9ecfd5d8f32d','running');
