-- M5.6 dependency — Local Deferred business receipt/review ledger.
-- Public owns pending transport. Local owns durable business result/idempotency.
-- financial_period_id is intentionally nullable and not FK-bound until the
-- Financial Period owner is migrated in its ordered M5 slice.

CREATE TABLE IF NOT EXISTS deferred_work_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id VARCHAR(96) NOT NULL,
    request_id VARCHAR(96) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    kind VARCHAR(64) NOT NULL,
    actor_projection_id VARCHAR(96) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    envelope_json JSON NOT NULL,
    state VARCHAR(20) NOT NULL,
    result_json JSON NULL,
    error_code VARCHAR(80) NULL,
    financial_period_id BIGINT UNSIGNED NULL,
    public_reconcile_pending TINYINT(1) NOT NULL DEFAULT 0,
    committed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m56_deferred_receipt_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m56_deferred_receipt_state CHECK (state IN ('committed','needs_review','rejected')),
    UNIQUE KEY uq_deferred_receipt_request (installation_id,request_id),
    INDEX idx_m56_deferred_receipt_state (state,updated_at),
    INDEX idx_m56_deferred_receipt_period (financial_period_id,state,occurred_at),
    INDEX idx_m56_deferred_receipt_reconcile (public_reconcile_pending,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deferred_review_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    review_type VARCHAR(32) NOT NULL,
    financial_period_id BIGINT UNSIGNED NULL,
    reason_code VARCHAR(80) NOT NULL,
    message VARCHAR(500) NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    resolved_by_user_id INT UNSIGNED NULL,
    resolution_reason VARCHAR(500) NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m56_deferred_review_receipt FOREIGN KEY (receipt_id) REFERENCES deferred_work_receipts(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_deferred_review_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m56_deferred_review_state CHECK (state IN ('pending','approved','rejected')),
    UNIQUE KEY uq_deferred_review_receipt (receipt_id),
    INDEX idx_m56_deferred_review_state (state,review_type,created_at),
    INDEX idx_m56_deferred_review_period (financial_period_id,state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
