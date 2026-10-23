-- Catalog tags restored on current V3 ownership.
CREATE TABLE IF NOT EXISTS catalog_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_key VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_tags_key(tag_key),
    UNIQUE KEY uq_catalog_tags_name(name),
    INDEX idx_catalog_tags_active_sort(active,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_tags (
    item_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(item_id,tag_id),
    CONSTRAINT fk_item_tags_item FOREIGN KEY(item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_item_tags_tag FOREIGN KEY(tag_id) REFERENCES catalog_tags(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_item_tags_tag(tag_id,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
