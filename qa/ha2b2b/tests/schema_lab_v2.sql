-- Disposable laboratory database ONLY (name sokna_ha2_lab_<8..16 lowercase hex>).
-- No changes to business schema or installed public records.
CREATE TABLE sokna_ha2_lab_guard (
 installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
 fence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
 phase VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'LAB_IDLE_UNQUALIFIED',
 tx_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 stage_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
) ENGINE=InnoDB;
CREATE TABLE sokna_ha2_lab_events (
 installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sequence_no BIGINT UNSIGNED NOT NULL,
 fence_no BIGINT UNSIGNED NOT NULL,
 tx_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_name VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY (installation_id,sequence_no),
 CONSTRAINT sokna_ha2_lab_events_guard_fk FOREIGN KEY (installation_id) REFERENCES sokna_ha2_lab_guard (installation_id)
) ENGINE=InnoDB;
