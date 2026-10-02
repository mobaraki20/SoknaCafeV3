-- Q4 Public Staff PWA / Web Push transport state. Local remains notification authority.
CREATE TABLE IF NOT EXISTS staff_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id VARCHAR(96) NOT NULL,
    projection_id VARCHAR(128) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint_url TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(255) NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_q4_staff_push_endpoint(installation_id,projection_id,endpoint_hash),
    INDEX idx_q4_staff_push_active(installation_id,projection_id,active),
    INDEX idx_q4_staff_push_session(session_token_hash,active),
    CONSTRAINT fk_q4_staff_push_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_push_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id VARCHAR(96) NOT NULL,
    projection_id VARCHAR(128) NOT NULL,
    event_key VARCHAR(160) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(600) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_error VARCHAR(300) NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_q4_staff_push_event(installation_id,projection_id,event_key),
    INDEX idx_q4_staff_push_pending(state,next_attempt_at,id),
    CONSTRAINT fk_q4_staff_push_outbox_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_q4_staff_push_state CHECK(state IN ('pending','processing','delivered','failed','waiting_subscription'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
