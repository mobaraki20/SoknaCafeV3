-- F1.6 — bounded Staff Consumption reporting indexes.
ALTER TABLE staff_consumptions
    ADD INDEX idx_f16_staff_consumption_report (business_date,status,consumer_personnel_id,id);

ALTER TABLE staff_account_ledger
    ADD INDEX idx_f16_staff_account_report (occurred_at,entry_type,personnel_id,id);
