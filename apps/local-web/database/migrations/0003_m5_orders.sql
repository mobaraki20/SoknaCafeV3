-- M5.2 — canonical Orders authority.
-- Scope: canonical order commit, immutable line snapshots, idempotency token,
-- and business-day numbering. Draft, preparation, inventory, tax, settlement,
-- receipts, printing and finance remain later M5 sub-slices.

CREATE TABLE IF NOT EXISTS cafe_tables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    table_number SMALLINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    access_token VARCHAR(80) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_m52_tables_number (table_number),
    INDEX idx_m52_tables_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_token VARCHAR(80) NOT NULL UNIQUE,
    table_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    live_table_guard INT UNSIGNED NULL,
    opened_by_user_id INT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    business_date DATE NOT NULL,
    business_shift_key VARCHAR(40) NOT NULL,
    business_shift_label VARCHAR(80) NOT NULL,
    business_cutoff_snapshot CHAR(5) NOT NULL,
    ended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m52_sessions_table FOREIGN KEY (table_id) REFERENCES cafe_tables(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_m52_sessions_opened_by FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_m52_sessions_live_table (live_table_guard),
    INDEX idx_m52_sessions_table_status (table_id, status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_business_sequences (
    business_date DATE PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_code VARCHAR(32) NOT NULL UNIQUE,
    client_token VARCHAR(80) NOT NULL UNIQUE,
    device_token VARCHAR(80) NULL,
    table_id INT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NULL,
    order_source VARCHAR(20) NOT NULL DEFAULT 'guest',
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    customer_note TEXT NULL,
    total_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    accepted_at DATETIME NULL,
    accepted_by_user_id INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NULL,
    business_order_number INT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    business_shift_key VARCHAR(40) NOT NULL,
    business_shift_label VARCHAR(80) NOT NULL,
    business_cutoff_snapshot CHAR(5) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m52_orders_table FOREIGN KEY (table_id) REFERENCES cafe_tables(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m52_orders_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m52_orders_accepted_by FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m52_orders_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m52_orders_source CHECK (order_source IN ('guest','staff')),
    UNIQUE KEY uq_m52_orders_business_number (business_date,business_order_number),
    INDEX idx_m52_orders_status_created (status,created_at),
    INDEX idx_m52_orders_table_created (table_id,created_at),
    INDEX idx_m52_orders_session_created (session_id,created_at),
    INDEX idx_m52_orders_source_created (order_source,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NULL,
    item_name VARCHAR(160) NOT NULL,
    sellable_kind_snapshot VARCHAR(20) NULL,
    unit_price BIGINT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    ordered_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    item_note VARCHAR(500) NULL,
    fulfillment_mode VARCHAR(16) NOT NULL DEFAULT 'dine_in',
    preparation_station VARCHAR(30) NOT NULL DEFAULT 'other',
    line_total BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m52_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m52_order_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m52_order_items_sellable_kind CHECK (sellable_kind_snapshot IS NULL OR sellable_kind_snapshot IN ('menu_item','service_item')),
    CONSTRAINT ck_m52_order_items_fulfillment CHECK (fulfillment_mode IN ('dine_in','takeaway')),
    INDEX idx_m52_order_items_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m52_status_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m52_status_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_m52_status_order_created (order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
