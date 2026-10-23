-- H191 working — exact fulfillment distribution evidence for committed item corrections.
-- These columns do not change financial/inventory ownership; they record how the
-- committed quantity is split between dine-in and takeaway before/after correction.
ALTER TABLE order_item_adjustments
    ADD COLUMN from_dine_in_quantity SMALLINT UNSIGNED NULL AFTER to_fulfillment_mode,
    ADD COLUMN to_dine_in_quantity SMALLINT UNSIGNED NULL AFTER from_dine_in_quantity,
    ADD COLUMN from_takeaway_quantity SMALLINT UNSIGNED NULL AFTER to_dine_in_quantity,
    ADD COLUMN to_takeaway_quantity SMALLINT UNSIGNED NULL AFTER from_takeaway_quantity;
