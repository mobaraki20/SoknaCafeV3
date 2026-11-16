-- R257 forward-only candidate. QA integration on the exact R249/R256 schema only.
-- Not authorized for direct application on an operational host.
CREATE TABLE IF NOT EXISTS public_move_owner_receipts (
  operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  local_installation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nonce CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  challenge_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  receipt_json LONGTEXT NOT NULL,
  expires_epoch BIGINT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_public_owner_receipts(public_instance_id,expires_epoch),
  KEY idx_local_owner_receipts(local_installation_id,expires_epoch)
) ENGINE=InnoDB;
