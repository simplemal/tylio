-- Migration 0009 — in-app upgrade state.
--
-- The Settings UI exposes "Aggiorna ora" which posts to
-- `POST /api/admin/update/apply` (handled by `UpdateController::apply`
-- via `UpdateApplier`). The applier is sync within a single request,
-- but we still persist a few flags so:
--   1. A concurrent admin who hits "Aggiorna ora" while another tab
--      is already running it gets a clear "in_progress" response.
--   2. The Settings card can render the last-successful-update line
--      ("Aggiornato a v0.3.0 il 2026-05-15") without grepping logs.
--   3. A crashed/timed-out apply leaves a recoverable marker —
--      the SPA shows "Aggiornamento interrotto · ripristina da backup"
--      with the actual backup path that was written.
--
-- Keys (all JSON-encoded scalars, like the rest of `settings`):
--   site.update_in_progress  bool  — set true at start of apply(),
--                                    false at end (success or failure).
--   site.last_update_at      string — ISO timestamp of the last
--                                    successful apply() completion.
--   site.last_update_version string — tag installed by the last
--                                    successful apply() (e.g. "v0.3.0").
--   site.last_update_error   string — non-empty if the last apply()
--                                    failed; the SPA surfaces it as a
--                                    red banner. Reset to '' on next OK.
--   site.last_update_backup  string — absolute path of the .backup/
--                                    snapshot from the last apply()
--                                    (success or failure). Used for the
--                                    "ripristina da" hint.

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('site.update_in_progress', json('false')),
    ('site.last_update_at', json_quote('')),
    ('site.last_update_version', json_quote('')),
    ('site.last_update_error', json_quote('')),
    ('site.last_update_backup', json_quote(''));
