<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * Thin wrapper over Symfony Mailer.
 *
 * Behavior:
 *   - If MAIL_DSN is empty/unset → no-op mode: every send() is logged to
 *     `data/logs/mail.log` as a JSON record but NO mail is actually sent.
 *     Useful in dev and on a fresh OSS install.
 *   - From: reads MAIL_FROM_ADDRESS / MAIL_FROM_NAME. If either is missing,
 *     falls back to "tylio <hello@tylio.app>".
 *   - SMTP errors are logged but NOT thrown: signup must not fail if the
 *     welcome mail can't be delivered. Callers can read ::lastError().
 *
 * Localization: every user-facing string (subject, body, HTML, lang="…")
 * is fetched through `I18n` under the `mail.*` namespace. The caller
 * passes an explicit `$locale` to each `send*` method (typically the
 * site owner's `settings.site.locale`). When omitted, the Mailer falls
 * back to whatever locale the shared `I18n` instance is currently
 * tracking — which on a fresh boot is the configured default.
 *
 * The Mailer DOES NOT read locale from the DB itself: doing so would
 * make the service couple to a `settings` table schema (single-row in
 * OSS, tenant-scoped in the SaaS overlay), which is exactly the kind of
 * assumption that breaks when the same code is reused under a different
 * data shape. Keeping locale resolution at the call site is the cleanest
 * way to make the Mailer reusable across deployment topologies.
 *
 * **Extendable by design.** Non-`final`; test fakes and SaaS-overlay
 * adapters (e.g. a "queue rather than send" transport) can subclass.
 */
class Mailer
{
    private ?SymfonyMailer $mailer = null;
    private ?string $lastError = null;

    public function __construct(
        protected Config $config,
        protected I18n $i18n,
        protected ?DB $db = null,
    ) {}

    /**
     * Configured DSN. Built from `settings.mail.*` if `mail.host` is
     * non-empty; otherwise falls back to the env `MAIL_DSN`. Returns
     * empty string when neither path yields a usable DSN — that's the
     * "Mailer disabled" state (no SMTP set up yet).
     *
     * **Extendable by design.** SaaS overlay overrides to pull from
     * tenant-scoped settings.
     */
    protected function dsn(): string
    {
        $host = $this->settingsString('mail.host');
        if ($host !== '') {
            $port = $this->settingsString('mail.port');
            $port = $port !== '' ? $port : '587';
            $security = $this->settingsString('mail.security');
            if ($security === '') $security = 'tls';
            $user = rawurlencode($this->settingsString('mail.user'));
            $pass = rawurlencode($this->settingsString('mail.pass'));
            $scheme = $security === 'ssl' ? 'smtps' : 'smtp';
            $auth = ($user !== '' || $pass !== '') ? "$user:$pass@" : '';
            $params = [];
            if ($security === 'tls') $params[] = 'encryption=tls';
            $params[] = 'timeout=10';
            $query = '?' . implode('&', $params);
            return "$scheme://$auth$host:$port$query";
        }
        return (string)$this->config->get('MAIL_DSN', '');
    }

    public function isEnabled(): bool
    {
        return $this->dsn() !== '';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function fromAddress(): string
    {
        $settings = $this->settingsString('mail.from_address');
        if ($settings !== '') return $settings;
        return (string)$this->config->get('MAIL_FROM_ADDRESS', 'hello@example.com');
    }

    public function fromName(): string
    {
        $settings = $this->settingsString('mail.from_name');
        if ($settings !== '') return $settings;
        return (string)$this->config->get('MAIL_FROM_NAME', 'tylio');
    }

    public function privacyAddress(): string
    {
        $settings = $this->settingsString('mail.privacy_address');
        if ($settings !== '') return $settings;
        return (string)$this->config->get('MAIL_PRIVACY_ADDRESS', $this->fromAddress());
    }

    public function supportAddress(): string
    {
        $settings = $this->settingsString('mail.support_address');
        if ($settings !== '') return $settings;
        return (string)$this->config->get('MAIL_SUPPORT_ADDRESS', $this->fromAddress());
    }

    /**
     * Read a settings row value. Returns empty string if no DB was
     * injected (legacy bootstrap), if the row is missing, or if the
     * decoded value isn't a string.
     */
    protected function settingsString(string $key): string
    {
        if ($this->db === null) return '';
        try {
            $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        } catch (\Throwable) {
            return '';
        }
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Lettura boolean dello stesso store. Per i settings come
     * `mail.use_custom_smtp` che sono encoded come `json('false')` /
     * `json('true')` — settingsString restituirebbe '' perché il
     * JSON-decoded value non è string.
     */
    protected function settingsBool(string $key): bool
    {
        if ($this->db === null) return false;
        try {
            $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        } catch (\Throwable) {
            return false;
        }
        if ($row === null) return false;
        return (bool)json_decode((string)($row['value'] ?? ''), true);
    }

    /**
     * Brand label displayed in transactional emails (subject / footer /
     * "powered by …"). Reads `APP_NAME` so a fork rebranding the
     * deployment (`APP_NAME=mosaicio`) automatically rebrands every
     * email. Falls back to a neutral `tylio` when unset.
     */
    public function brand(): string
    {
        $name = trim((string)$this->config->get('APP_NAME', ''));
        return $name !== '' ? $name : 'tylio';
    }

    /**
     * Apply the caller-supplied locale to the shared I18n instance for
     * the duration of this send. Empty/null/unsupported values fall back
     * to the I18n default locale, so callers can always pass the raw
     * `settings.site.locale` string without sanitizing it themselves.
     */
    private function applyLocale(?string $locale): void
    {
        if ($locale !== null && $locale !== '') {
            $this->i18n->setLocale($locale);
        }
    }

    /**
     * Augments every email-template parameter set with the shared
     * `{brand}` placeholder so locale strings stay deploy-neutral.
     *
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function withBrand(array $params): array
    {
        return ['brand' => $this->brand()] + $params;
    }

    /**
     * Send a plain-text email. Returns true if sent (or correctly enqueued
     * to the transport), false on failure or when the mailer is disabled.
     */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $this->lastError = null;

        if (!$this->isEnabled()) {
            $this->logToFile('disabled', $to, $subject, $textBody);
            return false;
        }

        @ini_set('default_socket_timeout', '10');

        $host = $this->settingsString('mail.host');
        if ($host !== '') {
            $port = (int)($this->settingsString('mail.port') ?: '587');
            $errno = 0;
            $errstr = '';
            $probe = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
            if ($probe === false) {
                $this->lastError = "Impossibile contattare $host:$port entro 5s ($errstr).";
                $this->logToFile('error: ' . $this->lastError, $to, $subject, $textBody);
                error_log('[tylio mailer] preflight failed: ' . $this->lastError);
                return false;
            }
            fclose($probe);
        }

        try {
            $email = (new Email())
                ->from(new Address($this->fromAddress(), $this->fromName()))
                ->to($to)
                ->subject($subject)
                ->text($textBody);

            if ($htmlBody !== null) {
                $email->html($htmlBody);
            }

            $this->getMailer()->send($email);
            $this->logToFile('sent', $to, $subject, $textBody);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logToFile('error: ' . $e->getMessage(), $to, $subject, $textBody);
            error_log('[tylio mailer] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invitation email with initial credentials (username + temporary
     * password). Used by any flow that auto-generates the password
     * (`bin2hex(random_bytes(6))` is typical caller code) and forces the
     * user to change it on first login (must_change_password=1 on the
     * users row).
     *
     * The caller owns the URL shape: in the SaaS this is
     * `https://<slug>.<apex>`, in the OSS clone it's `APP_URL`. We never
     * derive the URL from the slug here — that would couple the OSS
     * service to a SaaS deploy convention.
     *
     * `$siteLabel` is the human-readable form of the URL (e.g.
     * `foo.tylio.app` or `example.com`) shown in the email body; if
     * empty, defaults to the host part of `$url`.
     *
     * Table-based HTML body with 100% inline styles (email clients ignore
     * external CSS and <style> in head for compat). Dracula palette.
     */
    public function sendInvite(
        string $to,
        string $url,
        string $adminUrl,
        string $username,
        string $tempPassword,
        ?string $siteLabel = null,
        ?string $locale = null,
    ): bool {
        $this->applyLocale($locale);

        $label = $siteLabel !== null && $siteLabel !== ''
            ? $siteLabel
            : (string)(parse_url($url, PHP_URL_HOST) ?: $url);

        $subject = $this->i18n->t('mail.invite.subject', $this->withBrand([]));
        $text = $this->i18n->t('mail.invite.body_text', $this->withBrand([
            'url' => $url,
            'admin_url' => $adminUrl,
            'username' => $username,
            'temp_password' => $tempPassword,
            'site_label' => $label,
            'support' => $this->supportAddress(),
            'privacy' => $this->privacyAddress(),
        ]));

        $html = $this->renderInviteHtml($label, $username, $tempPassword, $url, $adminUrl);
        return $this->send($to, $subject, $text, $html);
    }

    private function renderInviteHtml(string $siteLabel, string $username, string $tempPwd, string $url, string $adminUrl): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $eUrl = $esc($url);
        $eAdmin = $esc($adminUrl);
        $eUser = $esc($username);
        $ePwd = $esc($tempPwd);
        $eLabel = $esc($siteLabel);
        $eSupport = $esc($this->supportAddress());
        $ePrivacy = $esc($this->privacyAddress());
        $lang = $esc($this->i18n->currentLocale());

        return $this->i18n->t('mail.invite.body_html', $this->withBrand([
            'lang' => $lang,
            'url' => $eUrl,
            'admin_url' => $eAdmin,
            'username' => $eUser,
            'temp_password' => $ePwd,
            'site_label' => $eLabel,
            'support' => $eSupport,
            'privacy' => $ePrivacy,
        ]));
    }

    /**
     * Forward a message received via the Contact form to the site owner.
     * Returns a status string:
     *   - 'sent'           delivered correctly
     *   - 'no_dsn'         MAIL_DSN empty: nothing can be sent
     *   - 'no_recipient'   `contact.notify_email` not configured by the user
     *   - 'error: <msg>'   transport threw (SMTP down, auth, DNS, …)
     *
     * We return a string rather than a bool because the caller persists the
     * status in `submissions.mail_status` so the admin sees right away why
     * a message didn't reach the inbox.
     *
     * Reply-To is set to the visitor's email (when present in the payload),
     * so clicking "Reply" in the mail client goes to the real sender, not
     * to `hello@tylio.app`.
     */
    public function sendContactNotification(
        string $notifyTo,
        ?string $siteHost,
        array $payload,
        ?string $ip,
        int $blockId,
        ?string $locale = null,
    ): string {
        if ($notifyTo === '') {
            $this->logToFile('contact:no_recipient', '(none)', "form #{$blockId}", '');
            return 'no_recipient';
        }
        if (!$this->isEnabled()) {
            $this->logToFile('contact:no_dsn', $notifyTo, "form #{$blockId}", '');
            return 'no_dsn';
        }

        $this->applyLocale($locale);

        $hostLabel = $siteHost ?: $this->brand();
        $subject = $this->i18n->t('mail.contact_notification.subject', $this->withBrand(['host' => $hostLabel]));

        $lines = [];
        foreach ($payload as $k => $v) {
            $label = ucfirst(str_replace('_', ' ', (string)$k));
            $val = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $lines[] = "{$label}: {$val}";
        }
        $ipLine = $ip ? $this->i18n->t('mail.contact_notification.ip_line', ['ip' => $ip]) : '';
        $textBody = $this->i18n->t('mail.contact_notification.body_text', $this->withBrand([
            'host' => $hostLabel,
            'fields' => implode("\n", $lines),
            'ip_line' => $ipLine,
            'block_id' => (string)$blockId,
        ]));

        $replyTo = null;
        foreach ($payload as $k => $v) {
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $replyTo = $v;
                break;
            }
        }

        $htmlBody = $this->renderContactNotificationHtml($hostLabel, $payload, $ip, $blockId, $replyTo);

        $this->lastError = null;
        try {
            $email = (new Email())
                ->from(new Address($this->fromAddress(), $this->fromName()))
                ->to($notifyTo)
                ->subject($subject)
                ->text($textBody)
                ->html($htmlBody);
            if ($replyTo !== null) {
                $email->replyTo(new Address($replyTo));
            }
            $this->getMailer()->send($email);
            $this->logToFile('contact:sent', $notifyTo, $subject, $textBody);
            return 'sent';
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logToFile('contact:error: ' . $e->getMessage(), $notifyTo, $subject, $textBody);
            error_log('[tylio mailer:contact] ' . $e->getMessage());
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * HTML email for the contact-form notification — same "dracula
     * table-based" template as the account/password emails (see
     * renderInviteHtml). 100% inline styles because email clients ignore
     * external CSS.
     */
    private function renderContactNotificationHtml(
        string $hostLabel,
        array $payload,
        ?string $ip,
        int $blockId,
        ?string $replyTo,
    ): string {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Dracula palette (aligned with renderInviteHtml + landing.php).
        // Kept in PHP (not i18n) because they're visual styling, not text.
        $bg = '#1a1c25';
        $surface = '#282a36';
        $text = '#f8f8f2';
        $muted = '#97a3c2';
        $accent = '#bd93f9';
        $border = 'rgba(248,248,242,0.12)';

        $eHost = $esc($hostLabel);
        $eSupport = $esc($this->supportAddress());
        $ePrivacy = $esc($this->privacyAddress());
        $lang = $esc($this->i18n->currentLocale());

        // Rows for the form fields (Name / Email / Message / etc.) — the site
        // admin receives them exactly as the user typed them, regardless of
        // which fields they configured on the Contact tile.
        $rowsHtml = '';
        foreach ($payload as $k => $v) {
            $label = $esc(ucfirst(str_replace('_', ' ', (string)$k)));
            $val = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $eVal = nl2br($esc((string)$val));
            $rowsHtml .= <<<ROW
            <tr>
              <td style="padding:14px 16px;border-top:1px solid {$border};">
                <div style="color:{$muted};font-size:12px;letter-spacing:.04em;text-transform:uppercase;">{$label}</div>
                <div style="color:{$text};font-size:15px;margin-top:4px;line-height:1.5;word-break:break-word;">{$eVal}</div>
              </td>
            </tr>
            ROW;
        }
        if ($rowsHtml === '') {
            $emptyMsg = $esc($this->i18n->t('mail.contact_notification.no_fields'));
            $rowsHtml = <<<EMPTY
            <tr><td style="padding:14px 16px;color:{$muted};font-size:14px;">{$emptyMsg}</td></tr>
            EMPTY;
        }

        $replyHtml = $replyTo
            ? $this->i18n->t('mail.contact_notification.reply_hint_html', [
                'muted' => $muted,
                'accent' => $accent,
                'email' => $esc($replyTo),
            ])
            : $this->i18n->t('mail.contact_notification.reply_none_html', [
                'muted' => $muted,
            ]);

        $ipLineHtml = $ip
            ? '<div style="color:' . $muted . ';font-size:12px;margin-top:4px;">' . $esc($this->i18n->t('mail.contact_notification.ip_label', ['ip' => $esc($ip)])) . '</div>'
            : '';

        return $this->i18n->t('mail.contact_notification.body_html', $this->withBrand([
            'lang' => $lang,
            'host' => $eHost,
            'support' => $eSupport,
            'privacy' => $ePrivacy,
            'rows_html' => $rowsHtml,
            'reply_html' => $replyHtml,
            'ip_line_html' => $ipLineHtml,
            'block_id' => (string)$blockId,
            'bg' => $bg,
            'surface' => $surface,
            'text' => $text,
            'muted' => $muted,
            'accent' => $accent,
            'border' => $border,
        ]));
    }

    /**
     * Verification-code email for the admin email address.
     *
     * SECURITY: body deliberately omits username, admin URL, and the
     * site URL beyond a generic "you entered this email to access a
     * {brand} site" referrer. The address may belong to an unrelated
     * person (typo on install); leaking the username/URL would let them
     * try to log in. The only payload is the code + the standard
     * "if it wasn't you, ignore this email" line.
     *
     * Subject also avoids carrying the code (some clients log subjects
     * in plaintext to indexers / push notifications).
     */
    public function sendVerificationCode(
        string $to,
        string $code,
        int $ttlMinutes,
        ?string $locale = null,
    ): bool {
        $this->applyLocale($locale);

        $subject = $this->i18n->t('mail.verification.subject', $this->withBrand([]));
        $text = $this->i18n->t('mail.verification.body_text', $this->withBrand([
            'code' => $code,
            'ttl_minutes' => (string)$ttlMinutes,
        ]));
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $this->i18n->t('mail.verification.body_html', $this->withBrand([
            'lang' => $esc($this->i18n->currentLocale()),
            'code' => $esc($code),
            'ttl_minutes' => (string)$ttlMinutes,
            'support' => $esc($this->supportAddress()),
            'privacy' => $esc($this->privacyAddress()),
        ]));

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * Welcome email sent ONCE after the admin email is verified. Carries
     * the credentials/URLs the user actually needs to keep using the
     * site. Caller is responsible for gating delivery behind
     * `site.welcome_sent_at IS NULL` so a later email change doesn't
     * resend this content.
     *
     * Mirror of `sendInvite()` for the post-install flow, but for users
     * who picked their own password (no temporary credential to ship).
     */
    public function sendWelcomeAfterVerified(
        string $to,
        string $username,
        string $siteUrl,
        string $adminUrl,
        ?string $siteLabel = null,
        ?string $locale = null,
    ): bool {
        $this->applyLocale($locale);

        $label = $siteLabel !== null && $siteLabel !== ''
            ? $siteLabel
            : (string)(parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl);

        $subject = $this->i18n->t('mail.welcome_verified.subject', $this->withBrand([
            'site_label' => $label,
        ]));
        $text = $this->i18n->t('mail.welcome_verified.body_text', $this->withBrand([
            'url' => $siteUrl,
            'site_url' => $siteUrl,
            'admin_url' => $adminUrl,
            'username' => $username,
            'site_label' => $label,
            'support' => $this->supportAddress(),
            'privacy' => $this->privacyAddress(),
        ]));

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $this->i18n->t('mail.welcome_verified.body_html', $this->withBrand([
            'lang' => $esc($this->i18n->currentLocale()),
            'url' => $esc($siteUrl),
            'admin_url' => $esc($adminUrl),
            'username' => $esc($username),
            'site_label' => $esc($label),
            'support' => $esc($this->supportAddress()),
            'privacy' => $esc($this->privacyAddress()),
        ]));

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * Password-reset email. `$siteUrl` is the canonical URL of the site
     * the user is recovering access for — caller-supplied to avoid
     * coupling the OSS service to a SaaS deploy convention (see
     * {@see sendInvite} for the same rationale).
     */
    public function sendPasswordReset(
        string $to,
        string $siteUrl,
        string $username,
        string $resetUrl,
        int $expiresMinutes,
        ?string $locale = null,
    ): bool {
        $this->applyLocale($locale);

        $subject = $this->i18n->t('mail.password_reset.subject', $this->withBrand([]));
        $body = $this->i18n->t('mail.password_reset.body_text', $this->withBrand([
            'site_url' => $siteUrl,
            'username' => $username,
            'reset_url' => $resetUrl,
            'expires_minutes' => (string)$expiresMinutes,
        ]));

        return $this->send($to, $subject, $body);
    }

    private function getMailer(): SymfonyMailer
    {
        if ($this->mailer === null) {
            $dsn = $this->dsn();
            $transport = Transport::fromDsn($dsn);
            $this->mailer = new SymfonyMailer($transport);
        }
        return $this->mailer;
    }

    private function logToFile(string $status, string $to, string $subject, string $body): void
    {
        $logDir = $this->config->path('data/logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0770, true);
        }
        $logPath = $logDir . '/mail.log';
        $record = json_encode([
            'ts' => date('c'),
            'status' => $status,
            'to' => $to,
            'subject' => $subject,
            'body_preview' => mb_substr($body, 0, 200),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($logPath, $record . "\n", FILE_APPEND | LOCK_EX);
    }
}
