-- P4 Hall / Preparation — canonical post-commit quantity adjustment ledger.
-- Order remains the financial/source owner. Preparation only acknowledges deltas that happen
-- after an area has already claimed the order; Inventory receives a durable delta event.

CREATE TABLE IF NOT EXISTS order_item_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(96) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    area_key VARCHAR(20) NULL,
    from_quantity SMALLINT UNSIGNED NOT NULL,
    to_quantity SMALLINT UNSIGNED NOT NULL,
    delta_quantity SMALLINT NOT NULL,
    preparation_claimed_before TINYINT(1) NOT NULL DEFAULT 0,
    preparation_ack_status VARCHAR(20) NOT NULL DEFAULT 'not_required',
    inventory_quantity_delta SMALLINT NOT NULL DEFAULT 0,
    inventory_effect VARCHAR(40) NOT NULL DEFAULT 'none',
    actor_user_id INT UNSIGNED NULL,
    acknowledged_by_user_id INT UNSIGNED NULL,
    acknowledged_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_item_adjustment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_order_item_adjustment_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_order_item_adjustment_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_order_item_adjustment_ack_user FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_order_item_adjustment_area CHECK (area_key IS NULL OR area_key IN ('kitchen','bar')),
    CONSTRAINT ck_order_item_adjustment_ack CHECK (preparation_ack_status IN ('not_required','pending','acknowledged')),
    CONSTRAINT ck_order_item_adjustment_delta CHECK (delta_quantity<>0),
    CONSTRAINT ck_order_item_adjustment_inventory_effect CHECK (inventory_effect IN ('none','consume_delta','reverse_unprepared','preserve_consumption_after_claim')),
    UNIQUE KEY uq_order_item_adjustment_request (request_id),
    INDEX idx_order_item_adjustment_order (order_id,id),
    INDEX idx_order_item_adjustment_item (order_item_id,id),
    INDEX idx_order_item_adjustment_pending (preparation_ack_status,area_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
