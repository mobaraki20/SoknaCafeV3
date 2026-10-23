-- Phase 7 H100 — brand identity master + derived Local/Public assets.
-- Identity reuses canonical guest media assets. Public identity changes only on Guest publish.

ALTER TABLE guest_content_config ADD COLUMN draft_identity_media_key VARCHAR(80) NULL AFTER draft_copy_json;
ALTER TABLE guest_content_config ADD COLUMN draft_identity_version CHAR(64) NULL AFTER draft_identity_media_key;
ALTER TABLE guest_content_config ADD COLUMN published_identity_media_key VARCHAR(80) NULL AFTER published_copy_json;
ALTER TABLE guest_content_config ADD COLUMN published_identity_version CHAR(64) NULL AFTER published_identity_media_key;

ALTER TABLE guest_content_revisions ADD COLUMN identity_media_key VARCHAR(80) NULL AFTER copy_json;
ALTER TABLE guest_content_revisions ADD COLUMN identity_version CHAR(64) NULL AFTER identity_media_key;
