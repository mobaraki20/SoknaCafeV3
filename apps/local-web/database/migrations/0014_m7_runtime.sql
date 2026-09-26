-- M7 — Runtime -> Local trigger receipt. Runtime never owns business tables.
CREATE TABLE IF NOT EXISTS runtime_trigger_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    runtime_instance_id VARCHAR(128) NOT NULL,
    request_id VARCHAR(96) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    trigger_key VARCHAR(64) NOT NULL,
    correlation_id VARCHAR(128) NOT NULL,
    state VARCHAR(20) NOT NULL,
    result_json JSON NOT NULL,
    requested_at DATETIME NOT NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_m7_runtime_trigger_state CHECK (state IN ('accepted','completed','failed')),
    UNIQUE KEY uq_m7_runtime_request (request_id),
    INDEX idx_m7_runtime_trigger (trigger_key,accepted_at),
    INDEX idx_m7_runtime_instance (runtime_instance_id,accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
