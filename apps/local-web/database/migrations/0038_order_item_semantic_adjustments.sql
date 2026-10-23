-- H154 — Generalize post-commit order item adjustment ledger.
-- Quantity adjustments keep their existing semantics. Fulfillment/note-only corrections
-- use NULL delta_quantity so the original non-zero quantity CHECK remains meaningful.

ALTER TABLE order_item_adjustments
    MODIFY delta_quantity SMALLINT NULL,
    ADD COLUMN adjustment_kind VARCHAR(24) NOT NULL DEFAULT 'quantity' AFTER area_key,
    ADD COLUMN from_fulfillment_mode VARCHAR(16) NULL AFTER delta_quantity,
    ADD COLUMN to_fulfillment_mode VARCHAR(16) NULL AFTER from_fulfillment_mode,
    ADD COLUMN from_item_note VARCHAR(500) NULL AFTER to_fulfillment_mode,
    ADD COLUMN to_item_note VARCHAR(500) NULL AFTER from_item_note,
    ADD COLUMN reason VARCHAR(250) NULL AFTER to_item_note,
    ADD COLUMN correction_print_job_id BIGINT UNSIGNED NULL AFTER actor_user_id,
    ADD CONSTRAINT ck_h154_order_item_adjustment_kind CHECK (adjustment_kind IN ('quantity','fulfillment','note','combined')),
    ADD CONSTRAINT ck_h154_order_item_adjustment_from_fulfillment CHECK (from_fulfillment_mode IS NULL OR from_fulfillment_mode IN ('dine_in','takeaway')),
    ADD CONSTRAINT ck_h154_order_item_adjustment_to_fulfillment CHECK (to_fulfillment_mode IS NULL OR to_fulfillment_mode IN ('dine_in','takeaway')),
    ADD CONSTRAINT ck_h154_order_item_adjustment_semantic CHECK (
        delta_quantity IS NOT NULL OR
        (from_fulfillment_mode IS NOT NULL AND to_fulfillment_mode IS NOT NULL AND from_fulfillment_mode<>to_fulfillment_mode) OR
        NOT (from_item_note <=> to_item_note)
    ),
    ADD INDEX idx_h154_order_item_adjustment_print (correction_print_job_id);
