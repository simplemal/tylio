<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Config;
use Tylio\Services\DB;
use Tylio\Services\Mailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contact-form submissions: accept POST from the public site, validate,
 * persist, and optionally forward by email.
 *
 * **Extendable by design.** Non-`final`. Sub-classes can override
 * `forwardByEmail()` (protected) to change recipient resolution — e.g. a
 * multi-site fork that resolves `contact.notify_email` per site instead
 * of globally. Stable public API across minor versions.
 */
class SubmissionsController
{
    private const TOKEN_TTL = 1800;        // 30 min
    private const TOKEN_MIN_AGE = 2;       // bot che POST entro 2s al token sono filtrati
    private const TOKEN_SECRET_FALLBACK = 'tylio-contact-token';

    /**
     * Proof-of-work difficulty in leading zero bits required on the
     * SHA-256 hash. 17 bits = ~131k attempts on average. On the client
     * (async crypto.subtle.digest, one await per call) modern Chrome
     * runs it in ~300ms-1.5s — acceptable with a spinner. For a bot
     * every submit costs real CPU: 1000 spam attempts = minutes of
     * dedicated CPU, making mass spamming inefficient.
     */
    private const POW_DIFFICULTY = 17;

    public function __construct(
        protected DB $db,
        protected Config $config,
        protected Mailer $mailer,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->all('SELECT * FROM submissions ORDER BY id DESC LIMIT 200');
        foreach ($rows as &$r) {
            $r['payload'] = json_decode($r['payload'] ?? '{}', true);
        }
        return AuthController::json($response, ['submissions' => $rows]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $blockId = (int)$args['blockId'];
        $body = (array)$request->getParsedBody();

        // Anti-spam honeypot: hidden field humans don't see. Generic bots
        // fill it in "for completeness" → busted.
        if (!empty($body['nickname']) || !empty($body['_hp'])) {
            return AuthController::json($response, ['ok' => true]);
        }

        // Self-built captcha: verifies HMAC token + user-interaction signal
        // + minimum fill time. The check is "soft" — if any of the three
        // fails we return OK without saving (bots don't notice they were
        // blocked; humans never see this path because the client handles
        // the token and _h correctly).
        if (!$this->verifyCaptcha($body)) {
            error_log("[tylio submit] captcha failed for block={$blockId}");
            return AuthController::json($response, ['ok' => true]);
        }

        $block = $this->db->one('SELECT * FROM blocks WHERE id = ? AND type = "contact"', [$blockId]);
        if (!$block) {
            return AuthController::json($response, ['error' => 'invalid_block'], 404);
        }

        $payload = [];
        foreach ($body as $k => $v) {
            if (str_starts_with($k, '_')) continue;
            if ($k === 'nickname') continue;
            if (is_string($v)) {
                $v = trim($v);
                if (mb_strlen($v) > 5000) $v = mb_substr($v, 0, 5000);
            }
            $payload[$k] = $v;
        }

        $params = $request->getServerParams();
        $ip = (string)($params['REMOTE_ADDR'] ?? '');

        [$mailStatus, $mailError] = $this->forwardByEmail($request, $blockId, $payload, $ip);

        $this->db->insert('submissions', [
            'block_id' => $blockId,
            'type' => 'contact',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'mail_status' => $mailStatus,
            'mail_error' => $mailError,
        ]);

        return AuthController::json($response, ['ok' => true]);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $this->db->pdo()
            ->prepare('UPDATE submissions SET read_at = datetime(\'now\') WHERE id = ? AND read_at IS NULL')
            ->execute([$id]);
        return AuthController::json($response, ['ok' => true]);
    }

    public function markAllRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->db->pdo()->exec('UPDATE submissions SET read_at = datetime(\'now\') WHERE read_at IS NULL');
        return AuthController::json($response, ['ok' => true]);
    }

    public function destroyOne(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $this->db->pdo()->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
        return AuthController::json($response, ['ok' => true]);
    }

    public function destroyAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->db->pdo()->exec('DELETE FROM submissions');
        return AuthController::json($response, ['ok' => true]);
    }

    public function unreadCount(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $count = (int)$this->db->value('SELECT COUNT(*) FROM submissions WHERE read_at IS NULL');
        return AuthController::json($response, ['count' => $count]);
    }

    /**
     * Issue the self-built captcha challenge as a proof-of-work: the
     * client must find a `nonce` such that SHA-256(`token:nonce`) has
     * POW_DIFFICULTY leading zero bits. Stateless: the token carries
     * salt+ts signed with HMAC, no server-side DB.
     *
     * Difference vs. a math captcha: the bot can't "guess" the answer
     * — it MUST burn CPU. ~262k SHA-256 at 18-bit difficulty (~0.5-1.5s
     * on a modern client). For someone spamming thousands of forms the
     * aggregate CPU cost makes the activity inefficient.
     *
     * @return array{token: string, difficulty: int}
     */
    public static function issueCaptchaChallenge(Config $config): array
    {
        $salt = bin2hex(random_bytes(8));
        $ts = time();
        $data = "{$salt}:{$ts}";
        $secret = (string)$config->get('APP_KEY', self::TOKEN_SECRET_FALLBACK);
        $sig = hash_hmac('sha256', $data, $secret);
        return [
            'token' => "{$data}.{$sig}",
            'difficulty' => self::POW_DIFFICULTY,
        ];
    }

    /**
     * Verify the four PoW captcha signals:
     *   - `_tok` is shaped `salt:ts.sig` with a valid HMAC (no
     *     cross-install replay: the secret is APP_KEY, shared by the
     *     deploy; changing the difficulty requires a server deploy)
     *   - timestamp within the TTL window (and ≥ TOKEN_MIN_AGE)
     *   - `_nonce` is the number that makes the PoW work:
     *     SHA-256(tok:nonce) has POW_DIFFICULTY leading zero bits →
     *     costs the bot CPU
     *   - `_h=1` flag set by JS on first interaction (focus/click/key)
     */
    protected function verifyCaptcha(array $body): bool
    {
        $tok = (string)($body['_tok'] ?? '');
        if (!str_contains($tok, '.')) return false;
        [$data, $sig] = explode('.', $tok, 2);

        $secret = (string)$this->config->get('APP_KEY', self::TOKEN_SECRET_FALLBACK);
        $expectedSig = hash_hmac('sha256', $data, $secret);
        if (!hash_equals($expectedSig, $sig)) return false;

        $parts = explode(':', $data);
        if (count($parts) !== 2) return false;
        [, $tsStr] = $parts;
        if (!ctype_digit($tsStr)) return false;

        $age = time() - (int)$tsStr;
        if ($age < self::TOKEN_MIN_AGE) return false;
        if ($age > self::TOKEN_TTL) return false;

        $nonce = (string)($body['_nonce'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nonce)) return false;
        if (!self::powValid($tok, $nonce, self::POW_DIFFICULTY)) return false;

        $human = (string)($body['_h'] ?? '');
        if ($human !== '1') return false;

        return true;
    }

    /**
     * Verifica che SHA-256(token:nonce) abbia almeno $bits bit zero
     * iniziali. Implementazione bit-a-bit per coerenza con il client JS.
     */
    private static function powValid(string $token, string $nonce, int $bits): bool
    {
        $hash = hash('sha256', $token . ':' . $nonce, true);
        $len = strlen($hash);
        $remaining = $bits;
        for ($i = 0; $i < $len && $remaining > 0; $i++) {
            $byte = ord($hash[$i]);
            if ($remaining >= 8) {
                if ($byte !== 0) return false;
                $remaining -= 8;
            } else {
                return ($byte >> (8 - $remaining)) === 0;
            }
        }
        return $remaining <= 0;
    }

    /**
     * Tenta l'inoltro via email del messaggio al notify_email configurato dal
     * proprietario del sito. Ritorna [status, error|null] da salvare in DB.
     *
     * `protected` per consentire l'override da una sottoclasse (es. una
     * sovrastruttura multi-tenant che scopa `contact.notify_email` per tenant).
     */
    protected function forwardByEmail(
        ServerRequestInterface $request,
        int $blockId,
        array $payload,
        string $ip,
    ): array {
        // Preferred recipient: the verified site.admin_email. Falls back
        // to the legacy contact.notify_email so existing installs keep
        // working during the migration window (0007 already copied a
        // non-empty legacy value into site.admin_email, but the user may
        // not have completed the verification step yet, in which case
        // we still want SOMETHING in the inbox rather than a silent
        // drop). The fallback is deliberately UNVERIFIED — the explicit
        // status code makes the reasoning auditable in the Submissions
        // admin view.
        $adminEmail = $this->resolveSetting('site.admin_email', $request);
        $verifiedAt = $this->resolveSetting('site.admin_email_verified_at', $request);

        $notify = '';
        $statusOverride = null;
        if ($adminEmail !== '' && $verifiedAt !== '') {
            $notify = $adminEmail;
        } elseif ($adminEmail !== '') {
            // The admin has configured an email but never verified it.
            // Refuse to forward — the address might belong to the wrong
            // person (typo). Record the reason in mail_status so the
            // admin sees it inline in Submissions.
            $statusOverride = 'unverified_recipient';
        } else {
            // No admin email at all → try the legacy field as a last
            // resort. New OSS installs will have neither set.
            $notify = $this->resolveSetting('contact.notify_email', $request);
        }

        if ($statusOverride !== null) {
            return [$statusOverride, null];
        }

        $locale = $this->resolveSetting('site.locale', $request);
        $host = $request->getUri()->getHost();
        $status = $this->mailer->sendContactNotification(
            $notify, $host, $payload, $ip, $blockId, $locale !== '' ? $locale : null,
        );
        $error = str_starts_with($status, 'error:') ? trim(substr($status, 6)) : null;
        $statusKey = $error ? 'error' : $status;
        return [$statusKey, $error];
    }

    /**
     * Read a single value out of the `settings` table, JSON-decoded.
     *
     * The `$request` argument is unused in this default implementation
     * — `settings` is a flat table in the OSS schema. It exists in the
     * signature so a sub-class can extract tenant context from the
     * request and scope the lookup accordingly (the multi-tenant SaaS
     * overlay does exactly that).
     *
     * **Extensible:** `protected` so a sub-class can override the lookup
     * without having to copy the surrounding `forwardByEmail` logic.
     */
    protected function resolveSetting(string $key, ?ServerRequestInterface $request = null): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }
}
