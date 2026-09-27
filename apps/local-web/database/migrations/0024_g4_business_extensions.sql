-- G4.3 Business extensions: marketing/events, reporting support and notifications/push.

CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    headline VARCHAR(200) NOT NULL,
    body TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    public_visible TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_campaign_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_g43_campaign_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_g43_campaign_status CHECK(status IN ('draft','scheduled','active','paused','completed','archived')),
    INDEX idx_g43_campaign_status_window(status,public_visible,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    public_visible TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    capacity INT UNSIGNED NULL,
    location_label VARCHAR(180) NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_event_created_by FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_g43_event_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_g43_event_status CHECK(status IN ('draft','scheduled','active','completed','cancelled','archived')),
    INDEX idx_g43_event_status_window(status,public_visible,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
    push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_notification_pref_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint_url TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_push_sub_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_g43_push_endpoint(user_id,endpoint_hash),
    INDEX idx_g43_push_user_active(user_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(600) NOT NULL,
    target_url VARCHAR(500) NULL,
    payload_json JSON NOT NULL,
    idempotency_key VARCHAR(120) NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    last_error VARCHAR(300) NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_outbox_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_g43_outbox_channel CHECK(channel IN ('in_app','web_push')),
    CONSTRAINT ck_g43_outbox_state CHECK(state IN ('pending','processing','delivered','failed','skipped')),
    UNIQUE KEY uq_g43_outbox_idempotency(idempotency_key),
    INDEX idx_g43_outbox_pending(state,next_attempt_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_inbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    outbox_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(600) NOT NULL,
    target_url VARCHAR(500) NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_g43_inbox_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_g43_inbox_outbox FOREIGN KEY(outbox_id) REFERENCES notification_outbox(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_g43_inbox_delivery(user_id,outbox_id),
    INDEX idx_g43_inbox_user_unread(user_id,read_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('notifications.push_bridge_url',''),
('notifications.push_bridge_token',''),
('notifications.vapid_public_key','')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

CREATE INDEX idx_g43_settlement_report ON settlement_records(business_date,status,destination);
CREATE INDEX idx_g43_order_report ON orders(business_date,status,order_source);
