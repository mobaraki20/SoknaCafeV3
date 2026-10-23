-- P2 Authentication parity: per-user failed-login throttling owned by Local Auth.
CREATE TABLE IF NOT EXISTS auth_login_rate_limits (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    last_failed_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    CONSTRAINT fk_auth_login_rate_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_auth_login_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
