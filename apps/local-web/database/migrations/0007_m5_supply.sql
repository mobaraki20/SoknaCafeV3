-- M5.6 — Supply/Purchase canonical authority.
-- Need -> Preparing -> physical Receive. Only Receive may increase Inventory.
-- Supply owns demand/receipt allocation state; Inventory owns stock/movement effects.

CREATE TABLE IF NOT EXISTS inventory_supply_needs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NULL,
    item_name_snapshot VARCHAR(160) NOT NULL,
    base_unit VARCHAR(12) NOT NULL,
    requested_quantity_base BIGINT UNSIGNED NOT NULL,
    fulfilled_quantity_base BIGINT UNSIGNED NOT NULL DEFAULT 0,
    preparing_quantity_base BIGINT UNSIGNED NOT NULL DEFAULT 0,
    preparing_by_user_id INT UNSIGNED NULL,
    preparing_at DATETIME NULL,
    department VARCHAR(20) NOT NULL DEFAULT 'shared',
    source VARCHAR(20) NOT NULL DEFAULT 'staff',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    last_outcome VARCHAR(24) NULL,
    note VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    closed_by_user_id INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    open_item_guard VARCHAR(255) NULL,
    CONSTRAINT fk_m56_supply_need_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_supply_need_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m56_supply_need_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m56_supply_need_preparing_by FOREIGN KEY (preparing_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m56_supply_need_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m56_supply_need_unit CHECK (base_unit IN ('g','ml','count')),
    CONSTRAINT ck_m56_supply_need_department CHECK (department IN ('bar','kitchen','shared')),
    CONSTRAINT ck_m56_supply_need_source CHECK (source IN ('staff','manager','low_stock')),
    CONSTRAINT ck_m56_supply_need_status CHECK (status IN ('open','closed','cancelled')),
    UNIQUE KEY uq_inventory_supply_need_open_item (open_item_guard),
    INDEX idx_m56_supply_need_status_department (status,department,updated_at,id),
    INDEX idx_m56_supply_need_item (inventory_item_id,status,updated_at),
    INDEX idx_m56_supply_need_preparing (status,preparing_quantity_base,preparing_at,id),
    INDEX idx_m56_supply_need_creator (created_by_user_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_supply_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supply_need_id BIGINT UNSIGNED NOT NULL,
    inventory_item_id INT UNSIGNED NOT NULL,
    requested_quantity_snapshot BIGINT UNSIGNED NOT NULL,
    received_quantity_base BIGINT UNSIGNED NOT NULL,
    remaining_quantity_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
    purchase_unit_id INT UNSIGNED NULL,
    purchase_unit_name_snapshot VARCHAR(160) NULL,
    purchase_unit_count DECIMAL(14,3) NULL,
    conversion_base_quantity_snapshot BIGINT UNSIGNED NULL,
    total_cost BIGINT UNSIGNED NULL,
    supplier VARCHAR(160) NULL,
    note VARCHAR(500) NULL,
    request_token CHAR(32) NOT NULL,
    movement_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m56_supply_receipt_need FOREIGN KEY (supply_need_id) REFERENCES inventory_supply_needs(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_supply_receipt_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_supply_receipt_unit FOREIGN KEY (purchase_unit_id) REFERENCES inventory_purchase_units(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m56_supply_receipt_movement FOREIGN KEY (movement_id) REFERENCES inventory_movements(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_supply_receipt_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_inventory_supply_receipt_request (request_token),
    INDEX idx_m56_supply_receipt_need (supply_need_id,created_at,id),
    INDEX idx_m56_supply_receipt_item (inventory_item_id,created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_supply_receipt_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    supply_need_id BIGINT UNSIGNED NOT NULL,
    allocated_quantity_base BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m56_supply_allocation_receipt FOREIGN KEY (receipt_id) REFERENCES inventory_supply_receipts(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m56_supply_allocation_need FOREIGN KEY (supply_need_id) REFERENCES inventory_supply_needs(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_inventory_supply_allocation_receipt_need (receipt_id,supply_need_id),
    INDEX idx_m56_supply_allocation_need (supply_need_id,receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES('module.supply.enabled','1')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
