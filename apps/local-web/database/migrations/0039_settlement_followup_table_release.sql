-- H183 working — release a physical table while preserving an unresolved financial account.
-- The session stays financially active, but live_table_guard is cleared so a new live session may open on the table.

ALTER TABLE table_sessions
    ADD COLUMN service_detached_at DATETIME NULL AFTER ended_at,
    ADD COLUMN service_detached_by_user_id INT UNSIGNED NULL AFTER service_detached_at,
    ADD COLUMN service_detach_reason VARCHAR(300) NULL AFTER service_detached_by_user_id,
    ADD COLUMN followup_settlement_destination VARCHAR(30) NULL AFTER service_detach_reason,
    ADD COLUMN followup_settlement_updated_at DATETIME NULL AFTER followup_settlement_destination,
    ADD COLUMN followup_settlement_updated_by_user_id INT UNSIGNED NULL AFTER followup_settlement_updated_at,
    ADD CONSTRAINT fk_h183_session_detached_by FOREIGN KEY (service_detached_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT fk_h183_session_followup_by FOREIGN KEY (followup_settlement_updated_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT ck_h183_session_followup_destination CHECK (followup_settlement_destination IS NULL OR followup_settlement_destination IN ('direct','subscriber','accommodation')),
    ADD INDEX idx_h183_session_followup (service_detached_at,status,followup_settlement_destination);

CREATE TABLE IF NOT EXISTS settlement_followup_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    from_destination VARCHAR(30) NULL,
    to_destination VARCHAR(30) NULL,
    note VARCHAR(300) NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_h183_followup_session FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_h183_followup_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_h183_followup_event_type CHECK (event_type IN ('detached','method_changed','settled','reopened')),
    CONSTRAINT ck_h183_followup_from_destination CHECK (from_destination IS NULL OR from_destination IN ('direct','subscriber','accommodation')),
    CONSTRAINT ck_h183_followup_to_destination CHECK (to_destination IS NULL OR to_destination IN ('direct','subscriber','accommodation')),
    INDEX idx_h183_followup_session (session_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
