-- M5.11 — Accommodation / Subscriber adapters.
-- These tables own adapter state only. Settlement remains the canonical finance owner.

CREATE TABLE IF NOT EXISTS subscribers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    mobile VARCHAR(30) NOT NULL,
    mobile_normalized VARCHAR(20) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m511_subscriber_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m511_subscriber_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_m511_subscriber_mobile (mobile_normalized),
    INDEX idx_m511_subscriber_active_name (active,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscriber_id BIGINT UNSIGNED NOT NULL,
    financial_period_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(24) NOT NULL,
    amount_delta BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    table_session_id BIGINT UNSIGNED NULL,
    related_entry_id BIGINT UNSIGNED NULL,
    reference VARCHAR(120) NULL,
    reason VARCHAR(300) NULL,
    invoice_snapshot_json JSON NULL,
    actor_user_id INT UNSIGNED NULL,
    idempotency_key VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m511_subscriber_ledger_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_subscriber_ledger_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_subscriber_ledger_session FOREIGN KEY (table_session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_subscriber_ledger_related FOREIGN KEY (related_entry_id) REFERENCES subscriber_ledger(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_subscriber_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m511_subscriber_entry CHECK (entry_type IN ('invoice','payment','invoice_reversal','payment_reversal')),
    UNIQUE KEY uq_m511_subscriber_reversal_entry (related_entry_id),
    UNIQUE KEY uq_m511_subscriber_ledger_idempotency (idempotency_key),
    INDEX idx_m511_subscriber_ledger_account (subscriber_id,created_at),
    INDEX idx_m511_subscriber_ledger_period (financial_period_id,created_at),
    INDEX idx_m511_subscriber_ledger_session (table_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accommodation_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    financial_period_id INT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    reservation_code VARCHAR(80) NOT NULL,
    guest_name_snapshot VARCHAR(160) NOT NULL,
    room_name_snapshot VARCHAR(240) NOT NULL,
    phone_hint_snapshot VARCHAR(40) NULL,
    amount BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(40) NOT NULL,
    invoice_snapshot_json JSON NOT NULL,
    account_signature CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    remote_transaction_id VARCHAR(120) NULL,
    remote_void_transaction_id VARCHAR(120) NULL,
    remote_original_transaction_id VARCHAR(120) NULL,
    remote_tracking_id VARCHAR(120) NULL,
    operator_user_id INT UNSIGNED NULL,
    void_requested_by_user_id INT UNSIGNED NULL,
    voided_by_user_id INT UNSIGNED NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    posted_at DATETIME NULL,
    voided_at DATETIME NULL,
    last_error_code VARCHAR(80) NULL,
    last_error VARCHAR(500) NULL,
    suspicious_response TINYINT(1) NOT NULL DEFAULT 0,
    local_finalize_pending TINYINT(1) NOT NULL DEFAULT 0,
    local_reversal_pending TINYINT(1) NOT NULL DEFAULT 0,
    resolved_at DATETIME NULL,
    resolved_by_user_id INT UNSIGNED NULL,
    resolution_method VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m511_accommodation_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_accommodation_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m511_accommodation_operator FOREIGN KEY (operator_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m511_accommodation_void_requested FOREIGN KEY (void_requested_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m511_accommodation_voided_by FOREIGN KEY (voided_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m511_accommodation_status CHECK (status IN ('pending','posted','failed','void_pending','void_failed','voided')),
    UNIQUE KEY uq_m511_accommodation_session (session_id),
    UNIQUE KEY uq_m511_accommodation_external_order (external_order_id),
    INDEX idx_m511_accommodation_status (status,updated_at),
    INDEX idx_m511_accommodation_period (financial_period_id,created_at),
    INDEX idx_m511_accommodation_reservation (reservation_code,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE settlement_records
    ADD CONSTRAINT fk_m511_settlement_subscriber_ledger FOREIGN KEY (subscriber_ledger_entry_id) REFERENCES subscriber_ledger(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    ADD CONSTRAINT fk_m511_settlement_accommodation_transfer FOREIGN KEY (accommodation_transfer_id) REFERENCES accommodation_transfers(id) ON UPDATE CASCADE ON DELETE RESTRICT;

INSERT INTO settings(setting_key,setting_value) VALUES
('module.subscribers.enabled','1'),
('module.accommodation.enabled','0')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
