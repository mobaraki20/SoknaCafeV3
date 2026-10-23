-- H289 — resilient Public transport v2: durable realtime ACK outbox + per-model delivery cursors.

CREATE TABLE IF NOT EXISTS public_realtime_ack_outbox (
    request_id VARCHAR(96) NOT NULL PRIMARY KEY,
    lease_token VARCHAR(128) NOT NULL,
    state VARCHAR(24) NOT NULL,
    result_json JSON NOT NULL,
    error_code VARCHAR(80) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    last_attempt_at DATETIME(6) NULL,
    INDEX idx_h289_public_ack_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_projection_model_delivery (
    model_key VARCHAR(64) NOT NULL PRIMARY KEY,
    ack_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_version CHAR(64) NULL,
    last_attempt_at DATETIME(6) NULL,
    last_success_at DATETIME(6) NULL,
    last_error_code VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
