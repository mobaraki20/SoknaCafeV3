-- H87 — durable payment tender allocations for direct settlements.
-- Destination (direct/subscriber/accommodation) and tender (cash/POS/bank transfer) are separate concepts.

ALTER TABLE settlement_records
    ADD COLUMN tender_capture_status VARCHAR(24) NOT NULL DEFAULT 'not_applicable' AFTER settlement_kind,
    ADD CONSTRAINT ck_h87_tender_capture_status CHECK (tender_capture_status IN ('recorded','legacy_missing','not_applicable'));

UPDATE settlement_records
SET tender_capture_status=CASE WHEN destination='direct' THEN 'legacy_missing' ELSE 'not_applicable' END;

CREATE TABLE IF NOT EXISTS settlement_tender_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    tender_type VARCHAR(24) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_h87_tender_settlement FOREIGN KEY (settlement_id) REFERENCES settlement_records(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_h87_tender_type CHECK (tender_type IN ('cash','pos','bank_transfer')),
    CONSTRAINT ck_h87_tender_amount CHECK (amount > 0),
    UNIQUE KEY uq_h87_settlement_tender (settlement_id,tender_type),
    INDEX idx_h87_tender_type (tender_type,settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
