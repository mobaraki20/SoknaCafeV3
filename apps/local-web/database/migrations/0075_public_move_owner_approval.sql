-- R259: forward-only consent-preflight records, independent of live Public binding.
-- These tables never authorize a transfer or change orders, inventory, printing
-- or authority. No production install is allowed until cumulative source audit.
CREATE TABLE IF NOT EXISTS public_move_auth_slots (
  local_installation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_move_auth_operations (
  operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  local_installation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  challenge_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  challenge_json LONGTEXT NOT NULL,
  nonce CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_epoch BIGINT NOT NULL,
  approval_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  approval_receipt_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  approved_at TIMESTAMP(6) NULL DEFAULT NULL,
  claimed_at TIMESTAMP(6) NULL DEFAULT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_local_operation (local_installation_id,status,expires_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
