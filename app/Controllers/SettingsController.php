<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
use Tylio\Services\EmailVerification;
use Tylio\Services\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Settings CRUD (`site.*`, `seo.*`, `contact.*`) + stats aggregate
 * (`visits`, `submissions`) for the admin dashboard. Server-side
 * validation runs in `validateSettings()` (protected — sub-classes
 * inherit it).
 *
 * **Extendable by design.** Non-`final`; the multi-tenant overlay
 * subclasses to scope every settings read/write by `tenant_id`,
 * reusing the validation pipeline as-is.
 */
class SettingsController
{
    public function __construct(
        protected DB $db,
        protected I18n $i18n,
        protected EmailVerification $emailVerification,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = $this->db->all('SELECT key, value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = json_decode($r['value'], true);
        }
        return AuthController::json($response, ['settings' => $out]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $settings = $body['settings'] ?? null;
        if (!is_array($settings)) {
            return AuthController::json($response, ['error' => 'invalid_settings'], 422);
        }
        // Validate fields that have a strict format. The client already
        // blocks invalid values via HTML5 pattern, but a malicious client
        // (curl, Postman) can bypass that — so we re-validate server-side
        // before saving.
        // Localize validation messages to the caller's locale (Accept-Language).
        $this->i18n->setLocale($this->i18n->negotiate($request->getHeaderLine('Accept-Language')));
        $errors = $this->validateSettings($settings);
        if (!empty($errors)) {
            return AuthController::json($response, ['error' => 'invalid_value', 'fields' => $errors], 422);
        }

        // Detect a CHANGE in `site.admin_email` so we can auto-trigger
        // a fresh verification right after persisting. Reading the
        // current value BEFORE the write keeps the logic OSS-compatible
        // (overrides scope by tenant). Empty string and absent setting
        // are normalized so toggling "set value → clear value" doesn't
        // accidentally count as a no-op.
        $oldEmail = $this->resolveStringSetting($request, 'site.admin_email');
        $newEmail = isset($settings['site.admin_email']) && is_string($settings['site.admin_email'])
            ? trim($settings['site.admin_email'])
            : $oldEmail;
        $emailChanged = $newEmail !== $oldEmail;

        $this->persistSettings($request, $settings, $emailChanged ? $newEmail : null);

        $user = $request->getAttribute('user');
        $params = $request->getServerParams();
        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'settings.update',
            'ip' => (string)($params['REMOTE_ADDR'] ?? ''),
        ]);

        // Fire-and-forget verification request on email change. We don't
        // surface a 422 if the mailer rejected — the UI also exposes a
        // manual "Resend code" button via EmailVerificationController.
        if ($emailChanged && $newEmail !== '') {
            $locale = $this->resolveStringSetting($request, 'site.locale');
            $this->emailVerification->requestCode(
                $newEmail,
                $locale !== '' ? $locale : null,
                $this->tenantIdFromRequest($request),
            );
            $this->db->insert('audit_log', [
                'user_id' => $user['id'] ?? null,
                'action' => 'email_verification.request_on_change',
                'resource' => $newEmail,
                'ip' => (string)($params['REMOTE_ADDR'] ?? ''),
            ]);
        }

        $payload = $this->indexPayload($request);
        $payload['email_changed'] = $emailChanged && $newEmail !== '';
        return AuthController::json($response, $payload);
    }

    /**
     * Default OSS implementation: write every key into the flat
     * `settings` table. When the admin email changes, we also reset
     * `site.admin_email_verified_at` to NULL so downstream consumers
     * (SubmissionsController, Mailer welcome flow) refuse to use the
     * new address until verification completes.
     *
     * Extracted from `update()` so the SaaS overlay can override the
     * write path (tenant-scoped INSERTs) without copy-pasting the
     * validation pipeline.
     *
     * @param array<array-key,mixed> $settings
     */
    protected function persistSettings(
        ServerRequestInterface $request,
        array $settings,
        ?string $newAdminEmailAfterChange,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        );
        foreach ($settings as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9._-]*$/i', $key)) continue;
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
        if ($newAdminEmailAfterChange !== null) {
            $stmt->execute(['site.admin_email_verified_at', json_encode(null)]);
        }
    }

    /**
     * Returns the JSON shape produced by `index()`, as a plain array so
     * `update()` can decorate it with `email_changed` before returning.
     *
     * @return array{settings: array<string,mixed>}
     */
    protected function indexPayload(ServerRequestInterface $request): array
    {
        $rows = $this->db->all('SELECT key, value FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = json_decode($r['value'], true);
        }
        return ['settings' => $out];
    }

    /**
     * Tenant context resolver — null on OSS. SaaS overlay overrides to
     * return `$tenant->id` so EmailVerification scopes its queries.
     */
    protected function tenantIdFromRequest(ServerRequestInterface $request): ?int
    {
        return null;
    }

    /**
     * Read a single string-valued setting. Overridden by the SaaS
     * overlay to scope by `tenant_id`.
     */
    protected function resolveStringSetting(ServerRequestInterface $request, string $key): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ? LIMIT 1', [$key]);
        if ($row === null) return '';
        $decoded = json_decode((string)($row['value'] ?? ''), true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Server-side validation for settings fields with a strict format.
     * Returns a `key => message` map of errors, or an empty array if OK.
     *
     * Mirrors the client logic in `Settings.vue` — the regexes must stay
     * in sync. Only the fields listed here are validated; the rest (site
     * title, description, …) are free-form text.
     *
     * **Extensible:** `protected` so sub-classes that override `update()`
     * (e.g. the multi-tenant SaaS overlay) can reuse the validation
     * pipeline without copy-pasting the regexes.
     *
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    protected function validateSettings(array $settings): array
    {
        $errors = [];

        $locale = $settings['site.locale'] ?? null;
        if (is_string($locale) && $locale !== '' && !preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $locale)) {
            $errors['site.locale'] = $this->i18n->t('settings.errors.invalid_locale');
        }

        $canonical = $settings['seo.canonical_url'] ?? null;
        if (is_string($canonical) && $canonical !== '' && !preg_match('#^https?://.+#i', $canonical)) {
            $errors['seo.canonical_url'] = $this->i18n->t('settings.errors.invalid_canonical_url');
        }

        $notifyEmail = $settings['contact.notify_email'] ?? null;
        if (is_string($notifyEmail) && $notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contact.notify_email'] = $this->i18n->t('settings.errors.invalid_email');
        }

        // `site.admin_email` is the new (verified) identity field that
        // supersedes `contact.notify_email` for outbound flows. Optional,
        // but if supplied must be a syntactically valid address.
        $adminEmail = $settings['site.admin_email'] ?? null;
        if (is_string($adminEmail) && $adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['site.admin_email'] = $this->i18n->t('settings.errors.invalid_email');
        }

        return $errors;
    }

    /**
     * Stats payload consumed by the admin SPA `Stats.vue` view.
     *
     * Shape (matches `Stats` in `admin-src/src/types.ts`):
     *   - `totals`: aggregate counters
     *       (total_visits, today_visits, unique_days,
     *        submissions_total, submissions_unread)
     *   - `by_day`:   last 30 days of visit counts, ASC by day
     *   - `by_block`: top 10 most-clicked tiles (clicks per block_id)
     */
    public function stats(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $today = date('Y-m-d');
        $thirtyAgo = date('Y-m-d', strtotime('-30 days'));

        $totals = [
            'total_visits' => (int)$this->db->value('SELECT COUNT(*) FROM visits'),
            'today_visits' => (int)$this->db->value('SELECT COUNT(*) FROM visits WHERE day = ?', [$today]),
            'unique_days' => (int)$this->db->value('SELECT COUNT(DISTINCT day) FROM visits'),
            'submissions_total' => (int)$this->db->value('SELECT COUNT(*) FROM submissions'),
            'submissions_unread' => (int)$this->db->value('SELECT COUNT(*) FROM submissions WHERE read_at IS NULL'),
        ];

        $byDay = $this->db->all(
            'SELECT day, COUNT(*) AS visits FROM visits
             WHERE day >= ?
             GROUP BY day ORDER BY day',
            [$thirtyAgo],
        );

        $byBlock = $this->db->all(
            'SELECT b.id, b.type, COUNT(v.id) AS clicks
             FROM blocks b
             LEFT JOIN visits v ON v.block_id = b.id
             GROUP BY b.id, b.type
             HAVING clicks > 0
             ORDER BY clicks DESC LIMIT 10',
        );

        return AuthController::json($response, [
            'totals' => $totals,
            'by_day' => $byDay,
            'by_block' => $byBlock,
        ]);
    }
}
