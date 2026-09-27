-- G3.3 Public Emergency / updater / machine takeover state.
ALTER TABLE installations
  ADD COLUMN revoked_at DATETIME NULL AFTER order_intake_enabled,
  ADD INDEX idx_g33_installations_revoked (revoked_at);

CREATE TABLE IF NOT EXISTS installation_pairing_secrets (
  installation_id VARCHAR(96) PRIMARY KEY,
  secret_ciphertext TEXT NOT NULL,
  rotated_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_g33_pairing_installation FOREIGN KEY (installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_takeovers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  old_installation_id VARCHAR(96) NOT NULL,
  new_installation_id VARCHAR(96) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_g33_takeover_new_status (new_installation_id,status),
  INDEX idx_g33_takeover_old (old_installation_id,status),
  CONSTRAINT ck_g33_takeover_status CHECK (status IN ('pending','completed','cancelled','expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_emergency_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(80) NOT NULL,
  installation_id VARCHAR(96) NULL,
  actor_hint VARCHAR(120) NULL,
  details_json JSON NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_g33_emergency_audit_time (occurred_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
