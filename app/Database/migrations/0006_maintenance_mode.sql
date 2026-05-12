-- Migration 0006 — maintenance mode settings.
--
-- Adds two settings used by `PageController::home` to short-circuit public
-- rendering with a dedicated maintenance page. Both are user-editable from
-- Settings → "Maintenance" in the admin SPA. Defaults are off / empty so
-- existing installs are unaffected.
--
--   site.maintenance         boolean → when true, non-logged visitors get
--                            the maintenance page (HTTP 503 + Retry-After).
--                            The logged-in admin keeps seeing the site
--                            normally so they can still preview their work.
--   site.maintenance_message free-text → optional message shown on the
--                            maintenance page. If empty, the template
--                            falls back to a localized default.

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('site.maintenance', json('false')),
    ('site.maintenance_message', json_quote(''));
