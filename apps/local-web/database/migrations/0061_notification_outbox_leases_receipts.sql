-- H321 Phase 3B: durable claim leases, delivery evidence, priority and TTL.
ALTER TABLE notification_outbox
    ADD COLUMN IF NOT EXISTS priority TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER channel,
    ADD COLUMN IF NOT EXISTS ttl_expires_at DATETIME NULL AFTER next_attempt_at,
    ADD COLUMN IF NOT EXISTS processing_token CHAR(32) NULL AFTER state,
    ADD COLUMN IF NOT EXISTS processing_started_at DATETIME NULL AFTER processing_token,
    ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER processing_started_at,
    ADD COLUMN IF NOT EXISTS bridge_accepted_at DATETIME NULL AFTER delivered_at,
    ADD COLUMN IF NOT EXISTS delivery_receipt_json JSON NULL AFTER bridge_accepted_at;
CREATE INDEX IF NOT EXISTS idx_h321_notification_claim ON notification_outbox(state,lease_expires_at,id);
CREATE INDEX IF NOT EXISTS idx_h321_notification_priority ON notification_outbox(state,next_attempt_at,priority,id);
