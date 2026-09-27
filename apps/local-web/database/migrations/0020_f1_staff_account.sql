-- F1.4 — Staff Account accounting semantics.
-- Staff Account remains a dedicated ledger. Benefit is calculated before debt;
-- waiver is a post-debt financial event and never rewrites the consumption snapshot.

-- MariaDB 11.4 does not allow a generated column expression to reference the
-- consumption_id foreign-key column. Charges and charge reversals are the only account
-- entries that carry consumption_id, so the composite unique key preserves one entry of
-- each ledger type per consumption while keeping the ledger append-only.
ALTER TABLE staff_account_ledger
    ADD COLUMN occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER idempotency_key,
    ADD UNIQUE KEY uq_f14_staff_account_consumption_type (consumption_id,entry_type),
    ADD INDEX idx_f14_staff_account_personnel_occurred (personnel_id,occurred_at,id),
    ADD INDEX idx_f14_staff_account_period_occurred (financial_period_id,occurred_at,id);
