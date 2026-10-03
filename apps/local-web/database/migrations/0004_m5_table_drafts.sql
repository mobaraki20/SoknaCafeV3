-- M5.3 — Staff Quick Order + server-persistent Table Draft.
-- Table Draft is Local-owned business state. Public may relay realtime commands
-- but never stores these tables. No auto-expiry exists; only Finalize/Cancel
-- closes an active draft.

CREATE TABLE IF NOT EXISTS table_drafts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_id INT UNSIGNED NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'active',
    active_table_guard INT UNSIGNED NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    expected_session_id BIGINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    finalized_by_user_id INT UNSIGNED NULL,
    cancelled_by_user_id INT UNSIGNED NULL,
    final_order_id BIGINT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m53_drafts_table FOREIGN KEY (table_id) REFERENCES cafe_tables(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_m53_drafts_expected_session FOREIGN KEY (expected_session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m53_drafts_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m53_drafts_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m53_drafts_finalized_by FOREIGN KEY (finalized_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m53_drafts_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m53_drafts_final_order FOREIGN KEY (final_order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m53_drafts_state CHECK (state IN ('active','finalized','cancelled')),
    UNIQUE KEY uq_m53_drafts_one_active_table (active_table_guard),
    INDEX idx_m53_drafts_table_state (table_id,state,updated_at),
    INDEX idx_m53_drafts_final_order (final_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_draft_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    item_name_snapshot VARCHAR(160) NOT NULL,
    unit_price_snapshot BIGINT UNSIGNED NOT NULL,
    sellable_kind_snapshot VARCHAR(20) NOT NULL DEFAULT 'menu_item',
    quantity SMALLINT UNSIGNED NOT NULL,
    item_note VARCHAR(500) NULL,
    fulfillment_mode VARCHAR(16) NOT NULL DEFAULT 'dine_in',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_m53_draft_items_draft FOREIGN KEY (draft_id) REFERENCES table_drafts(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m53_draft_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m53_draft_items_kind CHECK (sellable_kind_snapshot IN ('menu_item','service_item')),
    CONSTRAINT ck_m53_draft_items_fulfillment CHECK (fulfillment_mode IN ('dine_in','takeaway')),
    UNIQUE KEY uq_m53_draft_item_mode (draft_id,item_id,fulfillment_mode),
    INDEX idx_m53_draft_items_draft_sort (draft_id,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
