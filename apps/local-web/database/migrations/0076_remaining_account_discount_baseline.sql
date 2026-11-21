-- SOKNA QA workstream (2026-11-21) — restore the schema contract already
-- required by SettlementService::accountTx() and ::applyRemainingDiscountTx().
-- Forward-only: never update existing receipts, session financial totals, or
-- grants. The installer/migration gate remains a separate future release step.

CREATE TABLE IF NOT EXISTS remaining_account_discount_grants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    discount_type VARCHAR(12) NOT NULL,
    discount_value BIGINT UNSIGNED NOT NULL,
    base_unpaid_subtotal BIGINT UNSIGNED NOT NULL,
    discount_amount BIGINT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remaining_discount_grant_session
      FOREIGN KEY (session_id) REFERENCES table_sessions(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_remaining_discount_grant_actor
      FOREIGN KEY (actor_user_id) REFERENCES users(id)
      ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_remaining_discount_type CHECK (discount_type IN ('percent','fixed')),
    CONSTRAINT ck_remaining_discount_value CHECK (discount_value > 0),
    CONSTRAINT ck_remaining_discount_amount CHECK
      (discount_amount > 0 AND discount_amount <= base_unpaid_subtotal),
    UNIQUE KEY uq_remaining_discount_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remaining_account_discount_lines (
    grant_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    quantity_snapshot SMALLINT UNSIGNED NOT NULL,
    paid_quantity_at_grant SMALLINT UNSIGNED NOT NULL,
    original_discount_snapshot BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    invoice_tax_after_grant BIGINT UNSIGNED NOT NULL DEFAULT 0,
    invoice_taxable_after_grant BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (grant_id, order_item_id),
    CONSTRAINT fk_remaining_discount_line_grant
      FOREIGN KEY (grant_id) REFERENCES remaining_account_discount_grants(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_remaining_discount_line_item
      FOREIGN KEY (order_item_id) REFERENCES order_items(id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_remaining_discount_line_qty CHECK
      (paid_quantity_at_grant <= quantity_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
