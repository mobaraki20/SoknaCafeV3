-- G4.2 Guest Content Platform.
-- Local remains the source of truth for theme selection/settings, editable Guest copy,
-- media originals/derivatives and media references. Public only receives immutable replicas.

CREATE TABLE IF NOT EXISTS guest_content_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    draft_theme_key VARCHAR(64) NOT NULL DEFAULT 'sokna-house',
    draft_theme_settings_json JSON NOT NULL,
    published_theme_key VARCHAR(64) NOT NULL DEFAULT 'sokna-house',
    published_theme_settings_json JSON NOT NULL,
    draft_copy_json JSON NOT NULL,
    published_copy_json JSON NOT NULL,
    published_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    published_by_user_id INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_guest_content_publisher FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_guest_content_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO guest_content_config(
    id,draft_theme_settings_json,published_theme_settings_json,draft_copy_json,published_copy_json
) VALUES (1,'{}','{}','{}','{}');

CREATE TABLE IF NOT EXISTS guest_media_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_key VARCHAR(80) NOT NULL UNIQUE,
    sha256 CHAR(64) NOT NULL UNIQUE,
    mime VARCHAR(64) NOT NULL,
    extension VARCHAR(8) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    width_px INT UNSIGNED NULL,
    height_px INT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    alt_text VARCHAR(180) NOT NULL DEFAULT '',
    original_relpath VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_guest_media_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_guest_media_active_created(active,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_media_derivatives (
    media_id BIGINT UNSIGNED NOT NULL,
    variant_key VARCHAR(40) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    mime VARCHAR(64) NOT NULL,
    extension VARCHAR(8) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    width_px INT UNSIGNED NULL,
    height_px INT UNSIGNED NULL,
    path_relpath VARCHAR(255) NOT NULL,
    processor VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(media_id,variant_key),
    UNIQUE KEY uq_guest_media_derivative_hash(media_id,sha256,variant_key),
    CONSTRAINT fk_guest_media_derivative_asset FOREIGN KEY(media_id) REFERENCES guest_media_assets(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_media_references (
    media_id BIGINT UNSIGNED NOT NULL,
    owner_type VARCHAR(40) NOT NULL,
    owner_id VARCHAR(100) NOT NULL,
    slot_key VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(media_id,owner_type,owner_id,slot_key),
    UNIQUE KEY uq_guest_media_owner_slot(owner_type,owner_id,slot_key),
    CONSTRAINT fk_guest_media_reference_asset FOREIGN KEY(media_id) REFERENCES guest_media_assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_guest_media_reference_owner(owner_type,owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
