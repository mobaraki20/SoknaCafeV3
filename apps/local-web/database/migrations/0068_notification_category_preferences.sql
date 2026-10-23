-- Notification preference categories: account-level routing, device subscription remains Public PWA-owned.
ALTER TABLE notification_preferences
  ADD COLUMN push_operations_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER push_enabled,
  ADD COLUMN push_management_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER push_operations_enabled;
