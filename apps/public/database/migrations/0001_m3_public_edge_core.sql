-- M3 Public Edge core persistence.
-- Source baseline: mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05 public_edge/database/schema.sql
-- Scope intentionally excludes M4 guest publish / remote read-model tables.

CREATE TABLE IF NOT EXISTS installations (
  installation_id VARCHAR(96) PRIMARY KEY,
  display_name VARCHAR(160) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  remote_enabled TINYINT(1) NOT NULL DEFAULT 1,
  order_intake_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_projections (
  installation_id VARCHAR(96) NOT NULL,
  projection_id VARCHAR(96) NOT NULL,
  username VARCHAR(160) NOT NULL,
  display_name VARCHAR(160) NULL,
  role VARCHAR(32) NULL,
  password_hash VARCHAR(255) NOT NULL,
  capabilities_json JSON NOT NULL,
  preparation_areas_json JSON NULL,
  projection_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,projection_id),
  UNIQUE KEY uq_auth_projection_username(installation_id,username),
  CONSTRAINT fk_auth_projection_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  installation_id VARCHAR(96) NOT NULL,
  projection_id VARCHAR(96) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_public_session_token(token_hash),
  INDEX idx_public_session_expiry(expires_at),
  CONSTRAINT fk_public_session_projection FOREIGN KEY(installation_id,projection_id) REFERENCES auth_projections(installation_id,projection_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  installation_id VARCHAR(96) NOT NULL,
  request_id VARCHAR(96) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  kind VARCHAR(64) NOT NULL,
  actor_projection_id VARCHAR(96) NOT NULL,
  envelope_json JSON NOT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'queued',
  lease_token_hash CHAR(64) NULL,
  lease_expires_at DATETIME NULL,
  claimed_at DATETIME NULL,
  result_json JSON NULL,
  error_code VARCHAR(96) NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_realtime_request(installation_id,request_id),
  INDEX idx_realtime_claim(installation_id,state,expires_at,lease_expires_at,id),
  CONSTRAINT fk_realtime_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_nonces (
  installation_id VARCHAR(96) NOT NULL,
  nonce VARCHAR(96) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,nonce),
  INDEX idx_nonce_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_heartbeats (
  installation_id VARCHAR(96) PRIMARY KEY,
  local_version VARCHAR(64) NULL,
  runtime_status VARCHAR(32) NULL,
  telemetry_json JSON NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_heartbeat_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deferred_work (
  installation_id VARCHAR(96) NOT NULL,
  request_id VARCHAR(96) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  kind VARCHAR(64) NOT NULL,
  actor_projection_id VARCHAR(96) NOT NULL,
  envelope_json JSON NOT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'pending_sync',
  occurred_at DATETIME NOT NULL,
  lease_token CHAR(64) NULL,
  lease_expires_at DATETIME NULL,
  claimed_at DATETIME NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  result_json JSON NULL,
  error_code VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,request_id),
  INDEX idx_deferred_claim(installation_id,state,lease_expires_at,created_at),
  INDEX idx_deferred_period(installation_id,occurred_at,state),
  INDEX idx_deferred_actor(installation_id,actor_projection_id,created_at),
  CONSTRAINT fk_deferred_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADR 0003 abuse-control hardening. These tables store only non-secret derived metadata.
CREATE TABLE IF NOT EXISTS auth_login_throttle (
  installation_id VARCHAR(96) NOT NULL,
  account_key CHAR(64) NOT NULL,
  origin_key CHAR(64) NOT NULL,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(installation_id,account_key,origin_key),
  INDEX idx_auth_throttle_blocked(installation_id,blocked_until),
  CONSTRAINT fk_auth_throttle_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_security_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  installation_id VARCHAR(96) NOT NULL,
  projection_id VARCHAR(96) NULL,
  event_key VARCHAR(64) NOT NULL,
  account_key CHAR(64) NULL,
  origin_key CHAR(64) NULL,
  correlation_id VARCHAR(96) NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_audit_installation(installation_id,created_at),
  INDEX idx_auth_audit_event(installation_id,event_key,created_at),
  CONSTRAINT fk_auth_audit_installation FOREIGN KEY(installation_id) REFERENCES installations(installation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
