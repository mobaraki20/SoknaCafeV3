-- R257 QA FIXTURE ONLY. Not a versioned production migration.
-- No changes to local/public databases until exact installed source is qualified.
CREATE TABLE public_move_owner_receipts (
  operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  public_instance_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  local_installation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nonce CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  challenge_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  receipt_json LONGTEXT NOT NULL,
  expires_epoch BIGINT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_public_owner_receipts(public_instance_id, expires_epoch),
  KEY idx_local_owner_receipts(local_installation_id,expires_epoch)
) ENGINE=InnoDB;
