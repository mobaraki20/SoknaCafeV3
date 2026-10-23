-- H321 Phase 3A: one active account owner per browser Push endpoint.
CREATE TABLE IF NOT EXISTS notification_push_endpoint_owners (
    endpoint_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    endpoint_url TEXT NOT NULL,
    last_seen_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_h321_push_owner_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_h321_push_owner_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_push_endpoint_owners(endpoint_hash,user_id,endpoint_url,last_seen_at)
SELECT s.endpoint_hash,s.user_id,s.endpoint_url,COALESCE(s.last_seen_at,s.updated_at,UTC_TIMESTAMP())
FROM notification_push_subscriptions s
JOIN (
    SELECT endpoint_hash,MAX(id) AS id
    FROM notification_push_subscriptions
    WHERE active=1
    GROUP BY endpoint_hash
) latest ON latest.id=s.id
ON DUPLICATE KEY UPDATE
    user_id=VALUES(user_id),
    endpoint_url=VALUES(endpoint_url),
    last_seen_at=VALUES(last_seen_at);
