-- H303 / ARCH-08 — retain ambiguous Public ACK outcomes for explicit reconciliation.
ALTER TABLE public_realtime_ack_outbox
    ADD COLUMN IF NOT EXISTS delivery_state VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER error_code,
    ADD COLUMN IF NOT EXISTS last_delivery_error VARCHAR(80) NULL AFTER delivery_state,
    ADD COLUMN IF NOT EXISTS attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_delivery_error;

ALTER TABLE public_realtime_ack_outbox
    ADD INDEX IF NOT EXISTS idx_h303_ack_delivery_state (delivery_state, last_attempt_at, updated_at);

UPDATE public_realtime_ack_outbox
SET delivery_state='pending'
WHERE delivery_state IS NULL OR delivery_state='';
