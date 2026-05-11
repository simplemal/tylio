-- TOTP 2FA for tenant users. Same logic as the superadmin (pure-PHP Totp,
-- base32 secret + SHA-256 hashed backup codes), but per-user on the DB
-- instead of a singleton JSON file.
--
-- - `totp_secret`: base32 string (empty = 2FA not active for that user)
-- - `totp_backup_codes`: JSON array of SHA-256 hashes of the backup codes
--   (default '[]'). Each hash disappears after use (single-use).
-- - `totp_enabled_at`: activation timestamp (NULL if not active).
--
-- On the `sessions` table it adds `pending_2fa`: 1 = step 1 (password) ok but
-- step 2 (TOTP) not yet passed. AuthMiddleware rejects these sessions for
-- authenticated routes; the client must complete /api/auth/login/2fa.
ALTER TABLE users ADD COLUMN totp_secret TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NOT NULL DEFAULT '[]';
ALTER TABLE users ADD COLUMN totp_enabled_at TEXT;
ALTER TABLE sessions ADD COLUMN pending_2fa INTEGER NOT NULL DEFAULT 0;
