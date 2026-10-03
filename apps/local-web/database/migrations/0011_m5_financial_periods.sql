-- M5.9 — Financial Period controls that are independent of Settlement.
-- Final period close remains disabled until M5.10 Settlement can provide the
-- canonical financial summary. This slice owns numbering, close preflight
-- evidence and audited Deferred override records only.

CREATE TABLE IF NOT EXISTS financial_period_close_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    financial_period_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    public_status_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_m59_period_close_override_period FOREIGN KEY (financial_period_id) REFERENCES financial_periods(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m59_period_close_override_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_m59_period_close_override_period (financial_period_id,created_at),
    INDEX idx_m59_period_close_override_actor (actor_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
