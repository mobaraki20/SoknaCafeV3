-- Account-scoped UI preferences. Browser localStorage is only a cache/fallback.
CREATE TABLE IF NOT EXISTS user_ui_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    preferences_json JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_ui_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
