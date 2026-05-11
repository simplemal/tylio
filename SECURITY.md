# Security Policy

## Supported versions

Active support for:

| Version           | Supported |
|-------------------|-----------|
| `main`            | ✅         |
| Latest release    | ✅         |
| Previous releases | ⚠️ best-effort (critical security fixes only) |

## Reporting a vulnerability

**Please do not open public issues for security vulnerabilities.**

Send an email to **maurizio.natali@gmail.com** with:

- Vulnerability description
- Steps to reproduce
- Expected impact (e.g. RCE, info disclosure, XSS, CSRF, auth bypass)
- Affected version (commit hash or tag)
- PoC if available

You'll get a reply within **72 business hours**. For severe vulnerabilities I aim to ship a patch within 7 days and credit the reporter in `CHANGELOG.md` (if you want — anonymous is fine).

## Scope

In scope:

- Code execution or file disclosure through public or auth-gated endpoints
- Auth bypass (including 2FA bypass)
- SQL injection
- Stored or reflected XSS
- CSRF on state-changing endpoints
- Path traversal in uploads or static export
- Subdomain takeover, host header injection

Out of scope:

- Voluntarily exposed configuration (e.g. `APP_DEBUG=true` visible, `/install` not disabled after install)
- Login brute force (rate-limit exists, but IP-based tuning is the self-hoster's responsibility)
- Missing security headers on instances served WITHOUT the bundled `.htaccess` (e.g. nginx without equivalent config)
- Vulnerabilities requiring physical server / SQLite database access
- Automated scanner reports without a working PoC

## Hardening tips for self-hosters

- Keep PHP up-to-date (8.2+, ideally the latest minor)
- Keep `APP_DEBUG=false` in production
- Use HTTPS (`__Host-` cookies require Secure)
- Put a reverse proxy / WAF in front of Apache if exposed to the Internet
- Regular backups of `data/db.sqlite` + `public/uploads/`
- Consider enabling TOTP 2FA for the admin user (admin → Settings → 2FA)
- Restrict SELinux / AppArmor on the vhost
- `data/` must stay outside the webserver's DocumentRoot — it already is in the default layout; don't move it into `public/`

## Credits

Reporters who disclose responsibly will be credited in `CHANGELOG.md` (unless they prefer to remain anonymous).
