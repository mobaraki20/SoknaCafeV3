-- H193 — Guest public presentation/operator settings become part of the canonical Draft -> Publish -> Revision lifecycle.
-- Nullable JSON columns preserve upgrade compatibility; the domain supplies defaults when an older row has no value yet.

ALTER TABLE guest_content_config ADD COLUMN draft_public_settings_json JSON NULL AFTER draft_copy_json;
ALTER TABLE guest_content_config ADD COLUMN published_public_settings_json JSON NULL AFTER published_copy_json;
ALTER TABLE guest_content_revisions ADD COLUMN public_settings_json JSON NULL AFTER copy_json;
