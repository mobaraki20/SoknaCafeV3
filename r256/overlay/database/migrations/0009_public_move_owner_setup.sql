-- R256: independent Public-instance owner authentication for universal migration.
-- Must run after the eight pinned R249 Public migrations; forward-only.
-- Does not change an existing installation's owner, Local pairing or order intake.
CREATE TABLE IF NOT EXISTS public_move_owner_slots (
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_move_owner_setup_claims (
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_epoch BIGINT NOT NULL,
  used_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  consumed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_move_owner_accounts (
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until_epoch BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_move_owner_sessions (
  token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_epoch BIGINT NOT NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_public_owner_expiry (public_instance_id,expires_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
