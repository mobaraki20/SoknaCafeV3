-- H338B / A14 — Local machine-owned authority epoch state.
-- Excluded from business backup restore so a restored business snapshot cannot revive an old Public authority generation.
CREATE TABLE IF NOT EXISTS public_authority_state (
  installation_id VARCHAR(96) NOT NULL PRIMARY KEY,
  authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
  phase VARCHAR(32) NOT NULL DEFAULT 'active',
  reason VARCHAR(96) NULL,
  blocked_at DATETIME(6) NULL,
  reenrolled_at DATETIME(6) NULL,
  activated_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT ck_public_authority_phase CHECK (phase IN ('active','reenroll_required','resync_required'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
