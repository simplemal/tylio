<?php
declare(strict_types=1);

namespace Tylio\Controllers;

use Tylio\Services\DB;
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
    public function __construct(protected DB $db, protected I18n $i18n) {}

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
        $stmt = $this->db->pdo()->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))"
        );
        foreach ($settings as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9._-]*$/i', $key)) continue;
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
        $user = $request->getAttribute('user');
        $params = $request->getServerParams();
        $this->db->insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'action' => 'settings.update',
            'ip' => (string)($params['REMOTE_ADDR'] ?? ''),
        ]);
        return $this->index($request, $response);
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
