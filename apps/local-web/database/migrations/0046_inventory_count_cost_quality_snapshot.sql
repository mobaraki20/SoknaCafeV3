-- H206 — Preserve count-line cost quality so count-bounded COGS never upgrades estimates to facts.
ALTER TABLE inventory_count_lines
  ADD COLUMN IF NOT EXISTS cost_status_snapshot VARCHAR(24) NULL AFTER unit_cost_snapshot;

-- Explicit opening total cost is a known user-entered valuation.
UPDATE inventory_count_lines
SET cost_status_snapshot='known'
WHERE cost_status_snapshot IS NULL AND actual_total_cost IS NOT NULL;

-- Historical periodic/unit-cost lines predate this snapshot. Their old quality cannot be reconstructed safely.
UPDATE inventory_count_lines
SET cost_status_snapshot='legacy_unclassified'
WHERE cost_status_snapshot IS NULL AND unit_cost_snapshot IS NOT NULL;

UPDATE inventory_count_lines
SET cost_status_snapshot='unknown'
WHERE cost_status_snapshot IS NULL;
