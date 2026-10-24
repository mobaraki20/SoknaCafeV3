-- Explicit fulfilled-before-entry accounting: no kitchen preparation/printing.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS accounting_origin VARCHAR(24) NOT NULL DEFAULT 'regular'
    AFTER order_context;
CREATE TABLE IF NOT EXISTS late_served_accounting_receipts (
    request_id VARCHAR(96) NOT NULL PRIMARY KEY,
    request_fingerprint CHAR(64) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    session_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_late_served_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_late_served_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_late_served_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;