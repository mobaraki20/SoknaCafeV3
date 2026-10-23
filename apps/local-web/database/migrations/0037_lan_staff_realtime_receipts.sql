-- H142 — durable idempotency fence shared by LAN-direct and Public realtime transport.
CREATE TABLE IF NOT EXISTS lan_staff_realtime_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id VARCHAR(96) NOT NULL,
    request_id VARCHAR(96) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    kind VARCHAR(64) NOT NULL,
    actor_projection_id VARCHAR(96) NOT NULL,
    state VARCHAR(24) NOT NULL,
    result_json JSON NULL,
    error_code VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    completed_at DATETIME NULL,
    CONSTRAINT ck_h142_lan_realtime_state CHECK (state IN ('processing','committed','rejected','unknown_review')),
    UNIQUE KEY uq_h142_lan_realtime_request (installation_id,request_id),
    INDEX idx_h142_lan_realtime_actor (installation_id,actor_projection_id,updated_at),
    INDEX idx_h142_lan_realtime_state (state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
