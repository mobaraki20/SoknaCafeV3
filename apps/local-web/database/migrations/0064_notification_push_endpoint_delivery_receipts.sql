-- H334 / A08: durable per-endpoint Push delivery receipts.
CREATE TABLE IF NOT EXISTS notification_push_deliveries (
    outbox_id BIGINT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(300) NULL,
    last_attempt_at DATETIME NULL,
    accepted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(outbox_id,endpoint_hash),
    CONSTRAINT fk_h334_push_delivery_outbox FOREIGN KEY(outbox_id) REFERENCES notification_outbox(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_h334_push_delivery_state CHECK(state IN ('pending','transient','accepted','gone','fatal')),
    INDEX idx_h334_push_delivery_retry(outbox_id,state),
    INDEX idx_h334_push_delivery_endpoint(endpoint_hash,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
