-- Migration 0007 — admin email + verification infrastructure.
--
-- Adds a verified admin email distinct from the legacy `contact.notify_email`
-- (which until now was the only email setting). The new setting drives:
--   * the welcome mail after install/verification,
--   * forwarding of contact-form submissions,
--   * future password-reset and 2FA-via-email flows (channel ready,
--     consumers TBD — see memory/tylio_todo.md).
--
-- The email is NOT trusted until the admin pastes a 6-char code received
-- by mail (see `Tylio\Services\EmailVerification`). Until then,
-- `site.admin_email_verified_at` stays NULL and downstream mail flows
-- refuse to forward (`mail_status='unverified_recipient'`).
--
-- Settings keys introduced:
--   site.admin_email            string — the candidate email address.
--                               Empty = no email configured. Auto-filled
--                               from `contact.notify_email` if present
--                               (one-shot migration of the legacy value).
--   site.admin_email_verified_at  ISO timestamp — when the current value
--                               of `site.admin_email` was successfully
--                               verified. NULL = not verified (pending
--                               or absent code).
--   site.welcome_sent_at        ISO timestamp — when the welcome mail
--                               with credentials/URL was delivered for
--                               this install. NULL until the FIRST
--                               successful verification. Subsequent
--                               email changes do NOT re-send the welcome.
--
-- Tenant scoping: every settings row carries a `tenant_id` column in the
-- platform overlay (migration 1000). The OSS schema has no tenant_id on
-- `settings`, so the INSERT OR IGNOREs below run against the bare schema.

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('site.admin_email', json_quote('')),
    ('site.admin_email_verified_at', json('null')),
    ('site.welcome_sent_at', json('null'));

-- One-shot migration: if a non-empty `contact.notify_email` exists and
-- `site.admin_email` is still empty after the seed above, copy the legacy
-- value over. The new email is NOT auto-verified (the admin must still
-- complete the code-paste flow), but it preserves the user's intent.
UPDATE settings
   SET value = (SELECT value FROM settings WHERE key = 'contact.notify_email')
 WHERE key = 'site.admin_email'
   AND (value IS NULL OR value = '""' OR value = '')
   AND EXISTS (
       SELECT 1 FROM settings
        WHERE key = 'contact.notify_email'
          AND value IS NOT NULL
          AND value != '""'
          AND value != ''
   );

-- =====================================================================
-- email_verifications: pending verification codes (hashed).
-- =====================================================================
-- One row per (email, request) cycle. We hash the code with a server
-- pepper (EMAIL_VERIFICATION_PEPPER) before storing — see
-- `EmailVerification::hashCode()`. Codes expire in 24h and are wiped
-- after consumption or after 5 wrong attempts.
--
-- `tenant_id` defaults to 1 so the column is queryable on both OSS
-- (single bucket) and the platform overlay (after migration 1000
-- introduced multi-tenant scoping on every operational table).
CREATE TABLE IF NOT EXISTS email_verifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL DEFAULT 1,
    email TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    consumed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_email_verif_pending
    ON email_verifications(email, consumed_at);
CREATE INDEX IF NOT EXISTS idx_email_verif_tenant_pending
    ON email_verifications(tenant_id, email, consumed_at);
