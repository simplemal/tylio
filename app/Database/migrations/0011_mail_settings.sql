-- Migration 0011 — SMTP configuration as settings (instead of env-only).
--
-- Until v0.3.5 the Mailer read its DSN from `.env` (MAIL_DSN, MAIL_FROM_*).
-- Self-hosters on shared / managed hosting can't always edit `.env` —
-- they expect a settings UI. So we expose SMTP config as `settings` rows
-- and let `Mailer` build the DSN from them, falling back to env on null.
--
-- Keys (all JSON-encoded strings, like the rest of `settings`):
--   mail.host             smtp.example.com   (empty = SMTP not configured)
--   mail.port             "587" (or "465" for SMTPS, "25" plain)
--   mail.security         "tls" | "ssl" | "none"
--   mail.user             SMTP auth username (empty if the server allows
--                         unauth — rare on the public internet)
--   mail.pass             SMTP auth password
--   mail.from_address     What appears in the `From:` header
--   mail.from_name        Display name in the `From:` header
--   mail.privacy_address  Footer "privacy" contact; falls back to from_address
--   mail.support_address  Footer "support" contact; falls back to from_address
--
-- Why empty seed (instead of legacy env values): the migration runs
-- everywhere, including production servers that already have env set.
-- Leaving settings empty makes the Mailer prefer env (back-compat).
-- The admin can opt-in by filling the form, at which point settings win.

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('mail.host', json_quote('')),
    ('mail.port', json_quote('587')),
    ('mail.security', json_quote('tls')),
    ('mail.user', json_quote('')),
    ('mail.pass', json_quote('')),
    ('mail.from_address', json_quote('')),
    ('mail.from_name', json_quote('')),
    ('mail.privacy_address', json_quote('')),
    ('mail.support_address', json_quote(''));
