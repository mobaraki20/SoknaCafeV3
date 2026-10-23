-- H88 — stable dual-day accounting snapshots for settlement/tender reconciliation.
-- Existing settlement_records.settled_at keeps its established Local/Tehran wall-clock semantics.
-- New absolute UTC + Iran civil date snapshots are additive to avoid silently shifting history.

ALTER TABLE settlement_records
    ADD COLUMN settled_at_utc DATETIME NULL AFTER settled_at,
    ADD COLUMN civil_date DATE NULL AFTER settled_at_utc,
    ADD INDEX idx_h88_settlement_civil (civil_date,status,destination),
    ADD INDEX idx_h88_settlement_utc (settled_at_utc,id);
