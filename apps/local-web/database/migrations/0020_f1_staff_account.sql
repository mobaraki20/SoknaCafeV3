-- F1.4 — Staff Account accounting semantics.
-- Staff Account remains a dedicated ledger. Benefit is calculated before debt;
-- waiver is a post-debt financial event and never rewrites the consumption snapshot.

ALTER TABLE staff_account_ledger
    ADD COLUMN occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER idempotency_key,
    ADD COLUMN charge_consumption_guard BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN entry_type='charge' THEN consumption_id ELSE NULL END
    ) STORED AFTER occurred_at,
    ADD UNIQUE KEY uq_f14_staff_account_charge_consumption (charge_consumption_guard),
    ADD INDEX idx_f14_staff_account_personnel_occurred (personnel_id,occurred_at,id),
    ADD INDEX idx_f14_staff_account_period_occurred (financial_period_id,occurred_at,id);
