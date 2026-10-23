-- Keep Runtime acceptance O(1); recovery and heartbeat probes must not scan JSON history.
CREATE INDEX IF NOT EXISTS idx_runtime_pending_age ON runtime_trigger_receipts(state,accepted_at,id);
CREATE INDEX IF NOT EXISTS idx_runtime_trigger_state_age ON runtime_trigger_receipts(trigger_key,state,accepted_at);
CREATE INDEX IF NOT EXISTS idx_runtime_instance_state_age ON runtime_trigger_receipts(runtime_instance_id,state,accepted_at);
