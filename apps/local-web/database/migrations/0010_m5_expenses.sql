-- M5.8 — Expenses + minimal Financial Period identity prerequisite.
-- Financial Period close/summary/invoice sequence mutation remains M5.9.
-- Expense rows are append-only; correction = reversal + replacement.

CREATE TABLE IF NOT EXISTS financial_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(80) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    next_invoice_sequence INT UNSIGNED NOT NULL DEFAULT 1,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_by_user_id INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    closed_by_user_id INT UNSIGNED NULL,
    close_summary_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_m58_period_opened_by FOREIGN KEY (opened_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m58_period_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m58_period_status CHECK (status IN ('open','closed')),
    UNIQUE KEY uq_financial_period_dates (start_date,end_date),
    INDEX idx_m58_financial_period_status (status,start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
    category_key VARCHAR(64) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    system_category TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_expense_category_name (name),
    INDEX idx_m58_expense_category_active (active,sort_order,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO expense_categories(category_key,name,active,system_category,sort_order) VALUES
('rent','اجاره',1,1,10),
('utilities','آب، برق و انرژی',1,1,20),
('maintenance','تعمیر و نگهداری',1,1,30),
('transport','حمل‌ونقل',1,1,40),
('services','خدمات',1,1,50),
('equipment','تجهیزات',1,1,60),
('other','سایر',1,1,70)
ON DUPLICATE KEY UPDATE category_key=VALUES(category_key);

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_period_id INT UNSIGNED NOT NULL,
    category_key VARCHAR(64) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    description VARCHAR(500) NULL,
    occurred_at DATETIME NOT NULL,
    committed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_user_id INT UNSIGNED NULL,
    source_request_id VARCHAR(96) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'committed',
    reverses_expense_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m58_expense_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m58_expense_category FOREIGN KEY (category_key) REFERENCES expense_categories(category_key) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m58_expense_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_m58_expense_reversal FOREIGN KEY (reverses_expense_id) REFERENCES expenses(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_m58_expense_status CHECK (status IN ('committed','reversal')),
    UNIQUE KEY uq_expense_source_request (source_request_id),
    UNIQUE KEY uq_expense_reversal (reverses_expense_id),
    INDEX idx_m58_expense_period_occurred (financial_period_id,occurred_at),
    INDEX idx_m58_expense_category_occurred (category_key,occurred_at),
    INDEX idx_m58_expense_status_occurred (status,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE deferred_work_receipts
    MODIFY COLUMN financial_period_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_m58_deferred_receipt_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE deferred_review_items
    MODIFY COLUMN financial_period_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_m58_deferred_review_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT;
