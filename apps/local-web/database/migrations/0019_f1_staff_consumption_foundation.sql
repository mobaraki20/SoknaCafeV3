-- F1.1 — Staff Consumption / Benefits / Personnel Account foundation.
-- ADR-F1-001: independent Staff Consumption authority with canonical Order operational projection.
-- Existing table orders remain table_service by default. Staff consumption uses explicit non-table context.

ALTER TABLE orders
    MODIFY COLUMN table_id INT UNSIGNED NULL,
    ADD COLUMN order_context VARCHAR(32) NOT NULL DEFAULT 'table_service' AFTER order_source,
    ADD INDEX idx_f11_orders_context_created (order_context,created_at),
    ADD CONSTRAINT ck_f11_orders_context CHECK (
        (order_context='table_service' AND table_id IS NOT NULL)
        OR
        (order_context='staff_consumption' AND table_id IS NULL AND session_id IS NULL)
    );

CREATE TABLE IF NOT EXISTS staff_benefit_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_f11_staff_policy_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_f11_staff_policy_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_f11_staff_policy_key (policy_key),
    INDEX idx_f11_staff_policy_active_priority (active,is_default,priority,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_benefit_policy_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_id BIGINT UNSIGNED NOT NULL,
    scope_type VARCHAR(20) NOT NULL DEFAULT 'all',
    scope_id BIGINT UNSIGNED NULL,
    benefit_type VARCHAR(20) NOT NULL DEFAULT 'none',
    percent_bps SMALLINT UNSIGNED NULL,
    fixed_amount BIGINT UNSIGNED NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_f11_staff_policy_rule_policy FOREIGN KEY (policy_id) REFERENCES staff_benefit_policies(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_f11_staff_policy_rule_scope CHECK (scope_type IN ('all','category','item')),
    CONSTRAINT ck_f11_staff_policy_rule_scope_id CHECK ((scope_type='all' AND scope_id IS NULL) OR (scope_type IN ('category','item') AND scope_id IS NOT NULL)),
    CONSTRAINT ck_f11_staff_policy_rule_benefit CHECK (benefit_type IN ('none','free','percent','fixed')),
    CONSTRAINT ck_f11_staff_policy_rule_value CHECK (
        (benefit_type IN ('none','free') AND percent_bps IS NULL AND fixed_amount IS NULL)
        OR (benefit_type='percent' AND percent_bps IS NOT NULL AND percent_bps<=10000 AND fixed_amount IS NULL)
        OR (benefit_type='fixed' AND fixed_amount IS NOT NULL AND percent_bps IS NULL)
    ),
    INDEX idx_f11_staff_policy_rule_resolution (policy_id,active,scope_type,scope_id,priority,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_benefit_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    personnel_id INT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATE NULL,
    valid_until DATE NULL,
    notes VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_f11_staff_profile_personnel FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_profile_policy FOREIGN KEY (policy_id) REFERENCES staff_benefit_policies(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_profile_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_f11_staff_profile_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_f11_staff_profile_dates CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until>=valid_from),
    UNIQUE KEY uq_f11_staff_profile_personnel (personnel_id),
    INDEX idx_f11_staff_profile_policy_active (policy_id,active,personnel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_benefit_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    personnel_id INT UNSIGNED NOT NULL,
    scope_type VARCHAR(20) NOT NULL DEFAULT 'all',
    scope_id BIGINT UNSIGNED NULL,
    benefit_type VARCHAR(20) NOT NULL,
    percent_bps SMALLINT UNSIGNED NULL,
    fixed_amount BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    reason VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_f11_staff_override_personnel FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_override_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_f11_staff_override_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_f11_staff_override_scope CHECK (scope_type IN ('all','category','item')),
    CONSTRAINT ck_f11_staff_override_scope_id CHECK ((scope_type='all' AND scope_id IS NULL) OR (scope_type IN ('category','item') AND scope_id IS NOT NULL)),
    CONSTRAINT ck_f11_staff_override_benefit CHECK (benefit_type IN ('none','free','percent','fixed')),
    CONSTRAINT ck_f11_staff_override_value CHECK (
        (benefit_type IN ('none','free') AND percent_bps IS NULL AND fixed_amount IS NULL)
        OR (benefit_type='percent' AND percent_bps IS NOT NULL AND percent_bps<=10000 AND fixed_amount IS NULL)
        OR (benefit_type='fixed' AND fixed_amount IS NOT NULL AND percent_bps IS NULL)
    ),
    CONSTRAINT ck_f11_staff_override_dates CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until>=valid_from),
    INDEX idx_f11_staff_override_resolution (personnel_id,active,scope_type,scope_id,valid_from,valid_until,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_consumptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_code VARCHAR(32) NOT NULL,
    client_token VARCHAR(80) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    consumer_personnel_id INT UNSIGNED NOT NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    benefit_policy_id BIGINT UNSIGNED NULL,
    benefit_profile_id BIGINT UNSIGNED NULL,
    benefit_override_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'posted',
    menu_value_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    benefit_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payable_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    known_cost_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    consumer_name_snapshot VARCHAR(160) NOT NULL,
    policy_snapshot_json JSON NULL,
    calculation_snapshot_json JSON NOT NULL,
    business_date DATE NOT NULL,
    business_shift_key VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_f11_staff_consumption_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_personnel FOREIGN KEY (consumer_personnel_id) REFERENCES personnel(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_policy FOREIGN KEY (benefit_policy_id) REFERENCES staff_benefit_policies(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_profile FOREIGN KEY (benefit_profile_id) REFERENCES staff_benefit_profiles(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_override FOREIGN KEY (benefit_override_id) REFERENCES staff_benefit_overrides(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_f11_staff_consumption_status CHECK (status IN ('posted','reversed')),
    CONSTRAINT ck_f11_staff_consumption_amounts CHECK (benefit_amount+discount_amount<=menu_value_amount AND payable_amount=menu_value_amount-benefit_amount-discount_amount),
    UNIQUE KEY uq_f11_staff_consumption_public (public_code),
    UNIQUE KEY uq_f11_staff_consumption_client (client_token),
    UNIQUE KEY uq_f11_staff_consumption_order (order_id),
    INDEX idx_f11_staff_consumption_personnel_date (consumer_personnel_id,business_date,id),
    INDEX idx_f11_staff_consumption_recorder_date (recorded_by_user_id,business_date,id),
    INDEX idx_f11_staff_consumption_status_created (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_consumption_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consumption_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NULL,
    item_name_snapshot VARCHAR(160) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    menu_unit_price BIGINT UNSIGNED NOT NULL,
    menu_line_amount BIGINT UNSIGNED NOT NULL,
    benefit_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payable_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    known_cost_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calculation_snapshot_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_f11_staff_consumption_line_doc FOREIGN KEY (consumption_id) REFERENCES staff_consumptions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_f11_staff_consumption_line_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_consumption_line_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_f11_staff_consumption_line_amounts CHECK (benefit_amount+discount_amount<=menu_line_amount AND payable_amount=menu_line_amount-benefit_amount-discount_amount),
    UNIQUE KEY uq_f11_staff_consumption_line_order_item (order_item_id),
    INDEX idx_f11_staff_consumption_line_doc (consumption_id,id),
    INDEX idx_f11_staff_consumption_line_item (item_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_account_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    personnel_id INT UNSIGNED NOT NULL,
    financial_period_id INT UNSIGNED NOT NULL,
    consumption_id BIGINT UNSIGNED NULL,
    entry_type VARCHAR(24) NOT NULL,
    amount_delta BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    related_entry_id BIGINT UNSIGNED NULL,
    payment_method VARCHAR(32) NULL,
    reference VARCHAR(120) NULL,
    reason VARCHAR(500) NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_f11_staff_account_personnel FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_account_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_account_consumption FOREIGN KEY (consumption_id) REFERENCES staff_consumptions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_account_related FOREIGN KEY (related_entry_id) REFERENCES staff_account_ledger(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_f11_staff_account_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_f11_staff_account_entry CHECK (entry_type IN ('charge','payment','waiver','charge_reversal','payment_reversal','waiver_reversal','adjustment')),
    CONSTRAINT ck_f11_staff_account_method CHECK (payment_method IS NULL OR payment_method IN ('cash','card','bank','payroll','other')),
    UNIQUE KEY uq_f11_staff_account_idempotency (idempotency_key),
    UNIQUE KEY uq_f11_staff_account_related (related_entry_id),
    INDEX idx_f11_staff_account_personnel (personnel_id,created_at,id),
    INDEX idx_f11_staff_account_period (financial_period_id,created_at,id),
    INDEX idx_f11_staff_account_consumption (consumption_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('module.staff_consumption.enabled','1')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
