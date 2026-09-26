-- M4: Guest Publish, Guest Runtime projection persistence and Remote Read Models.
-- Canonical business data and business mutation owners remain Local.

CREATE TABLE IF NOT EXISTS guest_publish_revisions (
  installation_id VARCHAR(96) NOT NULL,
  revision_id VARCHAR(64) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  snapshot_json JSON NOT NULL,
  media_manifest_json JSON NOT NULL,
  generated_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,revision_id),
  UNIQUE KEY uq_guest_publish_hash(installation_id,content_hash),
  CONSTRAINT fk_guest_revision_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_active_revisions (
  installation_id VARCHAR(96) PRIMARY KEY,
  revision_id VARCHAR(64) NOT NULL,
  activated_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_active_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE,
  CONSTRAINT fk_guest_active_revision FOREIGN KEY(installation_id,revision_id) REFERENCES guest_publish_revisions(installation_id,revision_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_availability_state (
  installation_id VARCHAR(96) PRIMARY KEY,
  version CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  generated_at DATETIME NOT NULL,
  last_sync_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_availability_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_read_models (
  installation_id VARCHAR(96) NOT NULL,
  model_key VARCHAR(64) NOT NULL,
  source_version CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  generated_at DATETIME NOT NULL,
  last_sync_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,model_key),
  INDEX idx_remote_read_sync(installation_id,last_sync_at),
  CONSTRAINT fk_remote_read_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
