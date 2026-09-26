-- M5.5 — Inventory canonical authority.
-- Immutable movement ledger + balance projection + recipe snapshots + count draft/finalize.
-- Supply-owned receipt workflow and Finance remain outside this migration.

CREATE TABLE IF NOT EXISTS inventory_categories (
    category_key VARCHAR(64) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    system_category TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_m55_inventory_category_name (name),
    INDEX idx_m55_inventory_category_active (active,sort_order,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO inventory_categories(category_key,name,active,system_category,sort_order) VALUES
('ingredient','مواد اولیه',1,1,10),
('ready_drink','نوشیدنی آماده',1,1,20),
('ready_food','خوراکی آماده',1,1,30),
('packaging','بسته‌بندی و یک‌بارمصرف',1,1,40),
('consumable','ملزومات مصرفی',1,1,50)
ON DUPLICATE KEY UPDATE category_key=VALUES(category_key);

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'ingredient',
    base_unit VARCHAR(12) NOT NULL DEFAULT 'count',
    default_department VARCHAR(20) NOT NULL DEFAULT 'shared',
    warning_threshold BIGINT UNSIGNED NOT NULL DEFAULT 0,
    review_status VARCHAR(20) NOT NULL DEFAULT 'ready',
    review_note VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_inventory_items_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m55_inventory_items_category FOREIGN KEY (category) REFERENCES inventory_categories(category_key) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m55_inventory_base_unit CHECK (base_unit IN ('g','ml','count')),
    CONSTRAINT ck_m55_inventory_department CHECK (default_department IN ('bar','kitchen','shared')),
    CONSTRAINT ck_m55_inventory_review CHECK (review_status IN ('ready','needs_review')),
    INDEX idx_m55_inventory_items_active_name (active,name),
    INDEX idx_m55_inventory_items_review (review_status,active),
    INDEX idx_m55_inventory_items_category (category,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_purchase_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    conversion_mode VARCHAR(24) NOT NULL DEFAULT 'fixed',
    base_quantity BIGINT UNSIGNED NULL,
    review_status VARCHAR(20) NOT NULL DEFAULT 'ready',
    note VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_purchase_unit_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m55_purchase_unit_mode CHECK (conversion_mode IN ('fixed','actual_quantity')),
    CONSTRAINT ck_m55_purchase_unit_review CHECK (review_status IN ('ready','needs_review')),
    INDEX idx_m55_purchase_units_item (inventory_item_id,active,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_balances (
    inventory_item_id INT UNSIGNED PRIMARY KEY,
    quantity_base BIGINT NOT NULL DEFAULT 0,
    average_unit_cost DECIMAL(20,6) NULL,
    cost_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_inventory_balance_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m55_inventory_balance_cost_status CHECK (cost_status IN ('known','estimated','partial','unknown'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    movement_type VARCHAR(32) NOT NULL,
    quantity_base BIGINT NOT NULL,
    base_unit VARCHAR(12) NOT NULL,
    department VARCHAR(20) NULL,
    purchase_unit_id INT UNSIGNED NULL,
    purchase_unit_name_snapshot VARCHAR(160) NULL,
    purchase_unit_count DECIMAL(14,3) NULL,
    conversion_base_quantity_snapshot BIGINT UNSIGNED NULL,
    unit_cost_snapshot DECIMAL(20,6) NULL,
    total_cost_delta BIGINT NULL,
    cost_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    source_type VARCHAR(50) NULL,
    source_id VARCHAR(100) NULL,
    correction_of_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(190) NULL,
    metadata_json JSON NULL,
    note VARCHAR(500) NULL,
    actor_user_id INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_inventory_movement_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_movement_purchase_unit FOREIGN KEY (purchase_unit_id) REFERENCES inventory_purchase_units(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m55_inventory_movement_correction FOREIGN KEY (correction_of_id) REFERENCES inventory_movements(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_movement_reversal FOREIGN KEY (reversal_of_id) REFERENCES inventory_movements(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_movement_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m55_inventory_movement_unit CHECK (base_unit IN ('g','ml','count')),
    CONSTRAINT ck_m55_inventory_movement_department CHECK (department IS NULL OR department IN ('bar','kitchen','shared')),
    CONSTRAINT ck_m55_inventory_movement_cost_status CHECK (cost_status IN ('known','estimated','unknown')),
    UNIQUE KEY uq_inventory_movement_idempotency (idempotency_key),
    INDEX idx_m55_inventory_movement_item_created (inventory_item_id,created_at),
    INDEX idx_m55_inventory_movement_type_created (movement_type,created_at),
    INDEX idx_m55_inventory_movement_source (source_type,source_id),
    INDEX idx_m55_inventory_movement_department (department,created_at),
    INDEX idx_m55_inventory_movement_chronology (inventory_item_id,occurred_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_recipe_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by_user_id INT UNSIGNED NULL,
    retired_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_recipe_menu_item FOREIGN KEY (menu_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_recipe_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m55_recipe_status CHECK (status IN ('active','retired')),
    UNIQUE KEY uq_m55_inventory_recipe_version (menu_item_id,version_no),
    INDEX idx_m55_inventory_recipe_active (menu_item_id,status,version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_recipe_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_version_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id INT UNSIGNED NOT NULL,
    quantity_base BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_recipe_component_version FOREIGN KEY (recipe_version_id) REFERENCES inventory_recipe_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_recipe_component_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_m55_inventory_recipe_component (recipe_version_id,inventory_item_id),
    INDEX idx_m55_inventory_recipe_component_item (inventory_item_id,recipe_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_count_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    session_type VARCHAR(20) NOT NULL DEFAULT 'periodic',
    scope_type VARCHAR(20) NOT NULL DEFAULT 'full',
    scope_category_key VARCHAR(64) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    finalized_by_user_id INT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_inventory_count_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m55_inventory_count_finalized_by FOREIGN KEY (finalized_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m55_inventory_count_category FOREIGN KEY (scope_category_key) REFERENCES inventory_categories(category_key) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m55_inventory_count_type CHECK (session_type IN ('opening','periodic')),
    CONSTRAINT ck_m55_inventory_count_scope CHECK (scope_type IN ('full','category')),
    CONSTRAINT ck_m55_inventory_count_status CHECK (status IN ('draft','finalized','cancelled')),
    INDEX idx_m55_inventory_count_status (status,created_at),
    INDEX idx_m55_inventory_count_type (session_type,created_at),
    INDEX idx_m55_inventory_count_scope (scope_type,scope_category_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_count_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id INT UNSIGNED NOT NULL,
    system_quantity_snapshot BIGINT NOT NULL DEFAULT 0,
    unit_cost_snapshot DECIMAL(20,6) NULL,
    actual_quantity BIGINT UNSIGNED NULL,
    actual_total_cost BIGINT UNSIGNED NULL,
    difference_base BIGINT NULL,
    note VARCHAR(500) NULL,
    counted_by_user_id INT UNSIGNED NULL,
    counted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m55_inventory_count_line_session FOREIGN KEY (session_id) REFERENCES inventory_count_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_count_line_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_count_line_user FOREIGN KEY (counted_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_m55_inventory_count_line (session_id,inventory_item_id),
    INDEX idx_m55_inventory_count_line_item (inventory_item_id,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_order_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(30) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NULL,
    payload_json JSON NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    actor_user_id INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m55_inventory_order_event_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m55_inventory_order_event_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m55_inventory_order_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m55_inventory_order_event_type CHECK (event_type IN ('accounted','quantity_adjusted','cancelled','reaccounted')),
    CONSTRAINT ck_m55_inventory_order_event_status CHECK (status IN ('pending','done','failed')),
    UNIQUE KEY uq_inventory_order_event_idempotency (idempotency_key),
    INDEX idx_m55_inventory_order_event_pending (status,id),
    INDEX idx_m55_inventory_order_event_order (order_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('module.inventory.enabled','1'),
('inventory_initialized','0'),
('inventory_reconciliation_required','0')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
