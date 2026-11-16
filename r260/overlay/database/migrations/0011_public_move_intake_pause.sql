-- R260: one essential safety gate for empty Public replacement.
-- The standard Local installation projection cannot enable guest ordering
-- while this flag is set. Cleared only through authenticated activation after sync.
-- Existing pairings retain default 0 and their current behavior.
ALTER TABLE installations
  ADD COLUMN public_move_intake_paused TINYINT(1) NOT NULL DEFAULT 0;
