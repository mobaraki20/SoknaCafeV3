-- M5.7 — optional Tax owner.
-- Catalog prices remain pre-tax. Existing order history remains tax-disabled/zero.
-- Settlement tax columns are deliberately deferred until the Settlement owner exists.

CREATE TABLE IF NOT EXISTS tax_rate_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_bps SMALLINT UNSIGNED NOT NULL,
    effective_from DATETIME NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m57_tax_rate_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m57_tax_rate_bps CHECK (rate_bps BETWEEN 0 AND 10000),
    INDEX idx_m57_tax_rate_effective (effective_from,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_item_policy_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    policy VARCHAR(24) NOT NULL DEFAULT 'inherit_default',
    custom_rate_bps SMALLINT UNSIGNED NULL,
    effective_from DATETIME NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m57_tax_item_policy_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m57_tax_item_policy_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m57_tax_item_policy CHECK (policy IN ('inherit_default','exempt','custom_rate')),
    CONSTRAINT ck_m57_tax_custom_rate CHECK (custom_rate_bps IS NULL OR custom_rate_bps BETWEEN 0 AND 10000),
    INDEX idx_m57_tax_item_policy_effective (item_id,effective_from,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE order_items
    ADD COLUMN tax_policy_snapshot VARCHAR(24) NOT NULL DEFAULT 'disabled' AFTER line_total,
    ADD COLUMN tax_rate_bps_snapshot SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_policy_snapshot,
    ADD COLUMN tax_rate_version_id BIGINT UNSIGNED NULL AFTER tax_rate_bps_snapshot,
    ADD COLUMN tax_item_policy_version_id BIGINT UNSIGNED NULL AFTER tax_rate_version_id,
    ADD CONSTRAINT fk_m57_order_items_tax_rate FOREIGN KEY (tax_rate_version_id) REFERENCES tax_rate_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    ADD CONSTRAINT fk_m57_order_items_tax_policy FOREIGN KEY (tax_item_policy_version_id) REFERENCES tax_item_policy_versions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    ADD CONSTRAINT ck_m57_order_tax_policy CHECK (tax_policy_snapshot IN ('disabled','inherit_default','exempt','custom_rate')),
    ADD CONSTRAINT ck_m57_order_tax_rate CHECK (tax_rate_bps_snapshot BETWEEN 0 AND 10000);

ALTER TABLE table_sessions
    ADD COLUMN checkout_taxable BIGINT UNSIGNED NULL AFTER business_cutoff_snapshot,
    ADD COLUMN checkout_tax BIGINT UNSIGNED NULL AFTER checkout_taxable;

INSERT INTO settings(setting_key,setting_value) VALUES('module.tax.enabled','0')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
