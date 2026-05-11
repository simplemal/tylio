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
 */
final class Mailer
{
    private ?SymfonyMailer $mailer = null;
    private ?string $lastError = null;

    public function __construct(
        private Config $config,
        private I18n $i18n,
    ) {}

    public function isEnabled(): bool
    {
        return (string)$this->config->get('MAIL_DSN', '') !== '';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function fromAddress(): string
    {
        return (string)$this->config->get('MAIL_FROM_ADDRESS', 'hello@tylio.app');
    }

    public function fromName(): string
    {
        return (string)$this->config->get('MAIL_FROM_NAME', 'tylio');
    }

    public function privacyAddress(): string
    {
        return (string)$this->config->get('MAIL_PRIVACY_ADDRESS', $this->fromAddress());
    }

    public function supportAddress(): string
    {
        return (string)$this->config->get('MAIL_SUPPORT_ADDRESS', $this->fromAddress());
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
     * Table-based HTML body with 100% inline styles (email clients ignore
     * external CSS and <style> in head for compat). Dracula palette.
     */
    public function sendInvite(string $to, string $slug, string $username, string $tempPassword, ?string $locale = null): bool
    {
        $this->applyLocale($locale);

        $url = "https://{$slug}.tylio.app";
        $adminUrl = "{$url}/admin";

        $subject = $this->i18n->t('mail.invite.subject');
        $text = $this->i18n->t('mail.invite.body_text', [
            'url' => $url,
            'admin_url' => $adminUrl,
            'username' => $username,
            'temp_password' => $tempPassword,
            'support' => $this->supportAddress(),
            'privacy' => $this->privacyAddress(),
        ]);

        $html = $this->renderInviteHtml($slug, $username, $tempPassword, $url, $adminUrl);
        return $this->send($to, $subject, $text, $html);
    }

    private function renderInviteHtml(string $slug, string $username, string $tempPwd, string $url, string $adminUrl): string
    {
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $eUrl = $esc($url);
        $eAdmin = $esc($adminUrl);
        $eUser = $esc($username);
        $ePwd = $esc($tempPwd);
        $eSlug = $esc($slug);
        $eSupport = $esc($this->supportAddress());
        $ePrivacy = $esc($this->privacyAddress());
        $lang = $esc($this->i18n->currentLocale());

        return $this->i18n->t('mail.invite.body_html', [
            'lang' => $lang,
            'url' => $eUrl,
            'admin_url' => $eAdmin,
            'username' => $eUser,
            'temp_password' => $ePwd,
            'slug' => $eSlug,
            'support' => $eSupport,
            'privacy' => $ePrivacy,
        ]);
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

        $hostLabel = $siteHost ?: 'tylio';
        $subject = $this->i18n->t('mail.contact_notification.subject', ['host' => $hostLabel]);

        $lines = [];
        foreach ($payload as $k => $v) {
            $label = ucfirst(str_replace('_', ' ', (string)$k));
            $val = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $lines[] = "{$label}: {$val}";
        }
        $ipLine = $ip ? $this->i18n->t('mail.contact_notification.ip_line', ['ip' => $ip]) : '';
        $textBody = $this->i18n->t('mail.contact_notification.body_text', [
            'host' => $hostLabel,
            'fields' => implode("\n", $lines),
            'ip_line' => $ipLine,
            'block_id' => (string)$blockId,
        ]);

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

        return $this->i18n->t('mail.contact_notification.body_html', [
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
        ]);
    }

    public function sendPasswordReset(string $to, string $slug, string $username, string $resetUrl, int $expiresMinutes, ?string $locale = null): bool
    {
        $this->applyLocale($locale);

        $subject = $this->i18n->t('mail.password_reset.subject');
        $body = $this->i18n->t('mail.password_reset.body_text', [
            'slug' => $slug,
            'username' => $username,
            'reset_url' => $resetUrl,
            'expires_minutes' => (string)$expiresMinutes,
        ]);

        return $this->send($to, $subject, $body);
    }

    private function getMailer(): SymfonyMailer
    {
        if ($this->mailer === null) {
            $dsn = (string)$this->config->get('MAIL_DSN', '');
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
