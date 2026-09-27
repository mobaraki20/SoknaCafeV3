-- G3.2 Local -> Public projection/publish synchronization state.
-- Business authority remains Local; this table stores only delivery evidence.
CREATE TABLE IF NOT EXISTS public_sync_state (
    channel VARCHAR(40) PRIMARY KEY,
    source_version CHAR(64) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'never',
    last_http_status SMALLINT UNSIGNED NULL,
    detail_json JSON NULL,
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_g32_public_sync_status CHECK (status IN ('never','ok','error','disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
