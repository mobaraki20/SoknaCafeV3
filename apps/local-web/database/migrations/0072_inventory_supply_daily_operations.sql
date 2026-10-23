-- Direct physical purchases use the same receipt/ledger path without a fabricated need.
ALTER TABLE inventory_supply_receipts MODIFY supply_need_id BIGINT UNSIGNED NULL;

DROP TRIGGER IF EXISTS tr_h289_inventory_count_lines_ai;
CREATE TRIGGER tr_h289_inventory_count_lines_ai AFTER INSERT ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.insert','inventory_count_lines','*','reports,operations_workspace,deferred_context');

DROP TRIGGER IF EXISTS tr_h289_inventory_count_lines_au;
CREATE TRIGGER tr_h289_inventory_count_lines_au AFTER UPDATE ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.update','inventory_count_lines','*','reports,operations_workspace,deferred_context');

DROP TRIGGER IF EXISTS tr_h289_inventory_count_lines_ad;
CREATE TRIGGER tr_h289_inventory_count_lines_ad AFTER DELETE ON inventory_count_lines FOR EACH ROW INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES('inventory_count_lines.delete','inventory_count_lines','*','reports,operations_workspace,deferred_context');
