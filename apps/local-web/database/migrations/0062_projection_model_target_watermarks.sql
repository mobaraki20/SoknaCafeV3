-- H322 / REV-21 capacity: maintain an indexable per-model invalidation watermark.
-- The target is monotonic and intentionally survives outbox delivery/pruning; comparing it
-- with public_projection_model_delivery.ack_sequence tells the relay whether a model is dirty.

CREATE TABLE IF NOT EXISTS public_projection_model_catalog (
    model_key VARCHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO public_projection_model_catalog(model_key) VALUES
('operations'),('preparation'),('inventory'),('inventory_cost'),('operations_workspace'),
('subscribers'),('reports'),('notifications'),('deferred_context'),('table_draft_context'),('guest_operational');

CREATE TABLE IF NOT EXISTS public_projection_model_targets (
    model_key VARCHAR(64) NOT NULL PRIMARY KEY,
    last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_h322_projection_target_catalog FOREIGN KEY(model_key) REFERENCES public_projection_model_catalog(model_key) ON DELETE CASCADE,
    INDEX idx_h322_projection_target_sequence(last_sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO public_projection_model_targets(model_key,last_sequence)
SELECT c.model_key,MAX(o.sequence_id)
FROM public_projection_model_catalog c
JOIN public_projection_outbox o ON FIND_IN_SET(c.model_key,o.model_keys)>0
GROUP BY c.model_key
ON DUPLICATE KEY UPDATE last_sequence=GREATEST(public_projection_model_targets.last_sequence,VALUES(last_sequence));

DROP TRIGGER IF EXISTS tr_h322_projection_outbox_model_targets_ai;

CREATE TRIGGER tr_h322_projection_outbox_model_targets_ai AFTER INSERT ON public_projection_outbox FOR EACH ROW INSERT INTO public_projection_model_targets(model_key,last_sequence) SELECT c.model_key,NEW.sequence_id FROM public_projection_model_catalog c WHERE FIND_IN_SET(c.model_key,NEW.model_keys)>0 ON DUPLICATE KEY UPDATE last_sequence=GREATEST(public_projection_model_targets.last_sequence,VALUES(last_sequence));
