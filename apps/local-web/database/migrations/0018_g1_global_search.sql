-- G1.6c — bounded prefix-search indexes for the Local Global Search command palette.
-- Search remains a read-model; these indexes do not create new business authority.

ALTER TABLE items ADD INDEX IF NOT EXISTS idx_g16c_items_active_name (active,name);
ALTER TABLE categories ADD INDEX IF NOT EXISTS idx_g16c_categories_active_name (active,name);
ALTER TABLE menus ADD INDEX IF NOT EXISTS idx_g16c_menus_name (name);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_g16c_users_active_display (active,display_name);
ALTER TABLE cafe_tables ADD INDEX IF NOT EXISTS idx_g16c_tables_active_name (active,name);
