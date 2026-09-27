-- G1.2b Local Admin Controls and Personnel identity foundation.
-- Personnel identity is independent from the optional System User login account.

ALTER TABLE cafe_tables ADD COLUMN IF NOT EXISTS zone_label VARCHAR(80) NULL AFTER table_number;
ALTER TABLE cafe_tables ADD COLUMN IF NOT EXISTS previous_access_token VARCHAR(80) NULL AFTER access_token;
ALTER TABLE cafe_tables ADD COLUMN IF NOT EXISTS qr_rotated_at DATETIME NULL AFTER previous_access_token;
ALTER TABLE cafe_tables ADD COLUMN IF NOT EXISTS qr_rotated_by_user_id INT UNSIGNED NULL AFTER qr_rotated_at;
ALTER TABLE cafe_tables ADD INDEX IF NOT EXISTS idx_g12b_tables_zone_active (zone_label,active,table_number);
ALTER TABLE cafe_tables ADD CONSTRAINT fk_g12b_tables_qr_rotated_by FOREIGN KEY (qr_rotated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS personnel (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    linked_user_id INT UNSIGNED NULL,
    personnel_code VARCHAR(40) NULL,
    job_title VARCHAR(120) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(500) NULL,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_g12b_personnel_user (linked_user_id),
    UNIQUE KEY uq_g12b_personnel_code (personnel_code),
    INDEX idx_g12b_personnel_active_name (active,display_name),
    CONSTRAINT fk_g12b_personnel_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key,setting_value) VALUES
('cafe.name','SOKNA'),
('business_day_cutoff','04:00'),
('waiter_call_enabled','1')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
