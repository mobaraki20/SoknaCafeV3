-- SOKNA financial corrections (QA candidate only).
-- The original grant and its allocations remain immutable. Revisions are
-- append-only replacement snapshots of the ORIGINAL unpaid cohort, never of
-- settled lines. Only permitted while the receipt watermark is unchanged.
ALTER TABLE remaining_account_discount_grants
    ADD COLUMN paid_receipt_count_at_grant INT UNSIGNED NULL AFTER actor_user_id,
    ADD COLUMN paid_max_settlement_id_at_grant BIGINT UNSIGNED NULL AFTER paid_receipt_count_at_grant,
    ADD COLUMN paid_total_at_grant BIGINT UNSIGNED NULL AFTER paid_max_settlement_id_at_grant;

CREATE TABLE IF NOT EXISTS remaining_account_discount_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grant_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    request_id VARCHAR(96) NOT NULL,
    discount_type VARCHAR(12) NULL,
    discount_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_unpaid_subtotal BIGINT UNSIGNED NOT NULL,
    discount_amount BIGINT UNSIGNED NOT NULL,
    remaining_total_after BIGINT UNSIGNED NOT NULL,
    allocation_json LONGTEXT NOT NULL,
    reason VARCHAR(300) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remaining_discount_rev_grant FOREIGN KEY (grant_id)
      REFERENCES remaining_account_discount_grants(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_remaining_discount_rev_actor FOREIGN KEY (actor_user_id)
      REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_remaining_discount_rev_type CHECK
      (discount_type IN ('percent','fixed') OR discount_type IS NULL),
    CONSTRAINT ck_remaining_discount_rev_amount CHECK (discount_amount<=base_unpaid_subtotal),
    CONSTRAINT ck_remaining_discount_rev_zero CHECK
      ((discount_type IS NULL AND discount_value=0 AND discount_amount=0)
       OR (discount_type IS NOT NULL AND discount_value>0 AND discount_amount>0)),
    UNIQUE KEY uq_remaining_discount_rev_number (grant_id,revision_no),
    UNIQUE KEY uq_remaining_discount_rev_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
