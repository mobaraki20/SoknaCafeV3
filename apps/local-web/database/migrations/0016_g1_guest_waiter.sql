-- G1.1b — Guest order ownership and waiter-call persistence.
-- Preserves post-UI behavior while keeping Public as transport/projection only.

CREATE TABLE IF NOT EXISTS table_session_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    device_token VARCHAR(80) NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_g11_session_clients_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_g11_session_device (session_id,device_token),
    INDEX idx_g11_session_clients_seen (session_id,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waiter_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_code VARCHAR(32) NOT NULL UNIQUE,
    client_token VARCHAR(80) NOT NULL UNIQUE,
    device_token VARCHAR(80) NULL,
    table_id INT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    active_table_guard INT UNSIGNED NULL,
    accepted_by_user_id INT UNSIGNED NULL,
    accepted_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(40) NULL,
    cancelled_by_user_id INT UNSIGNED NULL,
    business_date DATE NOT NULL,
    business_shift_key VARCHAR(40) NOT NULL,
    business_shift_label VARCHAR(80) NOT NULL,
    business_cutoff_snapshot CHAR(5) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_g11_waiter_table FOREIGN KEY (table_id) REFERENCES cafe_tables(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_g11_waiter_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_g11_waiter_accepted FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_g11_waiter_cancelled FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_g11_waiter_status CHECK (status IN ('new','accepted','completed','cancelled')),
    UNIQUE KEY uq_g11_waiter_active_table (active_table_guard),
    INDEX idx_g11_waiter_table_created (table_id,created_at),
    INDEX idx_g11_waiter_status_created (status,created_at),
    INDEX idx_g11_waiter_device_created (device_token,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('orders_accepting.cafe','1'),
('orders_accepting.kitchen','1'),
('orders_accepting.bar','1'),
('waiter_call_enabled','1'),
('public_waiter_call_enabled','0'),
('new_device_alert_minutes','20')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
