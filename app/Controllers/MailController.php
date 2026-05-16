<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\Mailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/admin/mail/test` — sends a test email to the admin's
 * own address (or one supplied in the body) so the Settings UI can
 * tell the user immediately whether their SMTP configuration works.
 *
 * Auth-gated + CSRF (the route group registers both middlewares).
 * Returns:
 *   - 200 `{ok: true, to}`            on successful delivery
 *   - 422 `{ok: false, error: 'not_configured'}` when no DSN yields
 *   - 502 `{ok: false, error: 'send_failed', detail}` on SMTP errors
 *   - 422 `{ok: false, error: 'invalid_recipient'}` if `to` isn't a
 *     valid email address (rare — the SPA pre-fills site.admin_email)
 *
 * **Extendable by design.** Non-`final`. The SaaS overlay overrides
 * to use tenant-scoped settings (no separate test endpoint needed —
 * the tenant admin's Settings page hits this and the overlay's
 * Mailer subclass reads from the right scope).
 */
class MailController
{
    public function __construct(
        protected Mailer $mailer,
        protected DB $db,
    ) {}

    public function test(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        @set_time_limit(20);
        $body = (array)$request->getParsedBody();
        $to = trim((string)($body['to'] ?? ''));
        if ($to === '') {
            // Default to the admin email — that's what 99% of the use
            // cases want and saves a click in the UI.
            $to = $this->readAdminEmail();
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return AuthController::json($response, [
                'ok' => false,
                'error' => 'invalid_recipient',
                'detail' => 'Indirizzo destinatario non valido. Impostalo in `mail.from_address` o passalo nel body.',
            ], 422);
        }
        if (!$this->mailer->isEnabled()) {
            return AuthController::json($response, [
                'ok' => false,
                'error' => 'not_configured',
                'detail' => 'SMTP non configurato. Compila almeno `mail.host` in Impostazioni.',
            ], 422);
        }

        $sent = $this->mailer->send(
            $to,
            'tylio · test SMTP',
            "Se ricevi questa email, la configurazione SMTP di tylio funziona.\n\n"
            . 'Inviato dall\'amministratore tramite Impostazioni → SMTP → "Invia email di prova".',
            '<p style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;'
            . 'background:#1a1c25;color:#f8f8f2;padding:24px;border-radius:12px;">'
            . 'Se ricevi questa email, la configurazione SMTP di tylio funziona.'
            . '<br><span style="color:#97a3c2;font-size:13px;">'
            . 'Inviato dall\'amministratore tramite Impostazioni → SMTP → "Invia email di prova".'
            . '</span></p>',
        );

        if ($sent) {
            return AuthController::json($response, [
                'ok' => true,
                'to' => $to,
            ]);
        }
        return AuthController::json($response, [
            'ok' => false,
            'error' => 'send_failed',
            'detail' => $this->mailer->lastError() ?? 'Errore SMTP sconosciuto. Vedi il log in `data/logs/mail.log`.',
        ], 502);
    }

    protected function readAdminEmail(): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', ['site.admin_email']);
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }
}
