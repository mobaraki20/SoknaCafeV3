ALTER TABLE remote_user_credentials
    ADD COLUMN IF NOT EXISTS source_mode VARCHAR(32) NOT NULL DEFAULT 'legacy_separate' AFTER credential_version;

CREATE INDEX IF NOT EXISTS idx_remote_user_credentials_source_mode
    ON remote_user_credentials(source_mode);
