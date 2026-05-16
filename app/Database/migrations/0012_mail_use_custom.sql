-- Migration 0012 — flag "Usa il tuo server di posta" (SaaS toggle).
--
-- Default `false`: i tenant SaaS partono con il Mailer centralizzato
-- della piattaforma (legge env MAIL_DSN) — il caso "Usa l'SMTP di
-- tylio.app", che è quello pre-v0.3.6. Se l'admin del tenant abilita
-- il toggle in Settings → SMTP, `mail.use_custom_smtp` diventa true e
-- il `TenantMailer` (overlay platform) inizia a leggere
-- `settings.mail.*` per il DSN — caso "Usa il tuo server SMTP".
--
-- Sull'OSS standalone questo flag esiste ma è IGNORATO: il Mailer
-- OSS legge sempre `settings.mail.*` con fallback env (l'install
-- self-host deve configurare SMTP comunque, niente toggle nascosto).

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('mail.use_custom_smtp', json('false'));
