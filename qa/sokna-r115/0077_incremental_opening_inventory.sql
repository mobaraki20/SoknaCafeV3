-- An uncounted ingredient is not a zero-balance ingredient. Activation is durable even for a physical zero count.
CREATE TABLE IF NOT EXISTS inventory_opening_activations (
    inventory_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
    count_session_id BIGINT UNSIGNED NOT NULL,
    count_line_id BIGINT UNSIGNED NOT NULL UNIQUE,
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_user_id INT UNSIGNED NULL,
    CONSTRAINT fk_opening_activation_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_opening_activation_session FOREIGN KEY (count_session_id) REFERENCES inventory_count_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_opening_activation_line FOREIGN KEY (count_line_id) REFERENCES inventory_count_lines(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_opening_activation_session (count_session_id, activated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
