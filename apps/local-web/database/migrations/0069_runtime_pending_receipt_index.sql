-- P1 Local: pending Runtime receipt recovery and UI liveness query efficiency.
-- Intent: add one index; preserve all existing indexes, receipt rows, state and data.
-- This migration is a CANDIDATE: needs MariaDB Home DDL timing / maintenance gate.
ALTER TABLE runtime_trigger_receipts
  ADD INDEX IF NOT EXISTS idx_m7_runtime_pending_lookup (trigger_key, state, accepted_at);
