-- Phase 7 H98 — immutable .soknatheme registry + publish history.
-- Imported theme packages are data-only JSON manifests. They never carry executable PHP/JS/CSS.

CREATE TABLE IF NOT EXISTS guest_theme_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    theme_key VARCHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    package_json JSON NOT NULL,
    source_kind VARCHAR(16) NOT NULL DEFAULT 'imported',
    installed_by_user_id INT UNSIGNED NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guest_theme_key_version(theme_key,version),
    UNIQUE KEY uq_guest_theme_fingerprint(fingerprint),
    CONSTRAINT ck_guest_theme_source_kind CHECK (source_kind IN ('builtin','imported')),
    CONSTRAINT fk_guest_theme_installer FOREIGN KEY(installed_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guest_content_config ADD COLUMN draft_theme_package_id BIGINT UNSIGNED NULL AFTER draft_theme_key;
ALTER TABLE guest_content_config ADD COLUMN published_theme_package_id BIGINT UNSIGNED NULL AFTER published_theme_key;
ALTER TABLE guest_content_config ADD CONSTRAINT fk_guest_content_draft_theme_package FOREIGN KEY(draft_theme_package_id) REFERENCES guest_theme_packages(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE guest_content_config ADD CONSTRAINT fk_guest_content_published_theme_package FOREIGN KEY(published_theme_package_id) REFERENCES guest_theme_packages(id) ON UPDATE CASCADE ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS guest_content_revisions (
    revision BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    theme_package_id BIGINT UNSIGNED NOT NULL,
    theme_key VARCHAR(64) NOT NULL,
    theme_version VARCHAR(32) NOT NULL,
    theme_fingerprint CHAR(64) NOT NULL,
    theme_settings_json JSON NOT NULL,
    copy_json JSON NOT NULL,
    published_at DATETIME NOT NULL,
    published_by_user_id INT UNSIGNED NULL,
    CONSTRAINT fk_guest_revision_theme_package FOREIGN KEY(theme_package_id) REFERENCES guest_theme_packages(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_guest_revision_publisher FOREIGN KEY(published_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_guest_revision_published_at(published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
