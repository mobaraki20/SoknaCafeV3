-- M5.4 — Preparation permission/action owner.
-- Scope: assigned preparation areas and order-area claim ownership only.
-- Preparation adjustments remain out of this migration until their canonical
-- Order-item adjustment producer is migrated.

CREATE TABLE IF NOT EXISTS user_preparation_areas (
    user_id INT UNSIGNED NOT NULL,
    area_key VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,area_key),
    CONSTRAINT fk_m54_user_prep_area_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_m54_user_prep_area_key CHECK (area_key IN ('kitchen','bar')),
    INDEX idx_m54_user_prep_area (area_key,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_preparation_claims (
    order_id BIGINT UNSIGNED NOT NULL,
    area_key VARCHAR(20) NOT NULL,
    claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_by_user_id INT UNSIGNED NULL,
    item_signature CHAR(64) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id,area_key),
    CONSTRAINT fk_m54_prep_claim_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m54_prep_claim_user FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m54_prep_claim_area CHECK (area_key IN ('kitchen','bar')),
    INDEX idx_m54_prep_claim_area (area_key,claimed_at),
    INDEX idx_m54_prep_claim_user (claimed_by_user_id,claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
