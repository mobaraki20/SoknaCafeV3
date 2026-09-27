-- G4.4 — immutable .soknaprint packages on the canonical Print Agent renderer.

CREATE TABLE IF NOT EXISTS print_template_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    version VARCHAR(40) NOT NULL,
    document_kind VARCHAR(32) NOT NULL,
    package_format VARCHAR(64) NOT NULL,
    package_json JSON NOT NULL,
    template_json JSON NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    description VARCHAR(500) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_print_template_key_version (template_key,version),
    UNIQUE KEY uq_print_template_package_sha (content_sha256),
    KEY idx_print_template_kind_created (document_kind,created_at),
    CONSTRAINT fk_print_template_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS print_template_activations (
    document_kind VARCHAR(32) PRIMARY KEY,
    package_id INT UNSIGNED NOT NULL,
    activated_by_user_id INT UNSIGNED NOT NULL,
    activated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_print_template_active_package FOREIGN KEY (package_id) REFERENCES print_template_packages(id),
    CONSTRAINT fk_print_template_activated_by FOREIGN KEY (activated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
