-- G1.6b — SOKNA Center/Core integration retired by explicit product decision.
-- Historical migration 0013 is immutable for upgrade safety; this migration removes its Local runtime state.
DROP TABLE IF EXISTS center_entitlement_cache;
DROP TABLE IF EXISTS center_projection_receipts;
DELETE FROM settings WHERE setting_key='module.center.enabled';
