-- M5.1 — explicit Sellables/catalog authority.
-- Scope is intentionally limited to catalog/sellable data. Orders, preparation,
-- inventory, finance and order-line snapshots remain later M5 sub-slices.

CREATE TABLE IF NOT EXISTS menus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_key VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0,
    schedule_days VARCHAR(30) NULL,
    daily_start TIME NULL,
    daily_end TIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_menus_key (menu_key),
    INDEX idx_menus_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    audience VARCHAR(20) NOT NULL DEFAULT 'guest_staff',
    image_path VARCHAR(255) NULL,
    icon_key VARCHAR(40) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_key (category_key),
    INDEX idx_categories_active_sort (active, sort_order),
    INDEX idx_categories_audience (audience, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_categories (
    menu_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_id, category_id),
    CONSTRAINT fk_m5_menu_categories_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m5_menu_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_m5_menu_categories_order (menu_id, sort_order, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(80) NULL,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    price BIGINT UNSIGNED NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    available TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    cancelled_at DATETIME NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    staff_only TINYINT(1) NOT NULL DEFAULT 0,
    sellable_kind VARCHAR(20) NOT NULL DEFAULT 'menu_item',
    takeaway_allowed TINYINT(1) NOT NULL DEFAULT 1,
    preparation_station VARCHAR(30) NOT NULL DEFAULT 'other',
    schedule_start DATETIME NULL,
    schedule_end DATETIME NULL,
    schedule_days VARCHAR(30) NULL,
    daily_start TIME NULL,
    daily_end TIME NULL,
    suggested_item_id INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_m5_items_code (item_code),
    CONSTRAINT fk_m5_items_category FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_m5_items_suggested FOREIGN KEY (suggested_item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_m5_items_sellable_kind CHECK (sellable_kind IN ('menu_item','service_item')),
    INDEX idx_m5_items_menu (category_id, active, available, sort_order),
    INDEX idx_m5_items_featured (featured, active),
    INDEX idx_m5_items_sellable_kind (sellable_kind, active),
    INDEX idx_m5_items_schedule (active, schedule_start, schedule_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    menu_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_id, item_id),
    CONSTRAINT fk_m5_menu_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_m5_menu_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_m5_menu_items_item (item_id, menu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
