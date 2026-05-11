<?php
declare(strict_types=1);

namespace Tylio\Services;

use Tylio\Config;

/**
 * Lightweight i18n service for server-rendered templates and PHP-emitted
 * user-facing strings (public site, install screen, placeholder shells,
 * relative-date helpers, …).
 *
 * Storage: one PHP file per locale under `app/Locales/<code>.php`, each
 * returning a flat associative array `key => template`. Templates can
 * contain `{name}` placeholders interpolated via `t($key, ['name' => …])`.
 *
 * **Locale resolution priority** (caller orchestrates by passing the right
 * value to `setLocale()`):
 *   1. Site owner's explicit `settings.site.locale` (admin choice).
 *   2. `Accept-Language` header negotiated against `availableLocales()`.
 *   3. Hard default `en`.
 *
 * **Fallback:** if a key is missing in the chosen locale, the default
 * locale is consulted. If it's missing there too, the key itself is
 * returned (developer-visible signal that a string needs adding).
 *
 * **Extendable by design.** Non-`final`; sub-classes can override
 * `load()` to plug in a different storage (DB, JSON, CMS) without
 * touching the rest of the pipeline.
 */
class I18n
{
    /** @var array<string, array<string, string>> locale → key => template */
    protected array $cache = [];

    protected string $locale;
    protected string $defaultLocale = 'en';

    /**
     * @param list<string> $available locales available in `app/Locales/`.
     *   Adding a new locale = drop a `xx.php` file + extend this list.
     */
    public function __construct(
        protected Config $config,
        protected array $available = ['en', 'it'],
        string $defaultLocale = 'en',
    ) {
        $this->defaultLocale = in_array($defaultLocale, $available, true) ? $defaultLocale : 'en';
        $this->locale = $this->defaultLocale;
    }

    /** @return list<string> */
    public function availableLocales(): array
    {
        return $this->available;
    }

    public function currentLocale(): string
    {
        return $this->locale;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Set the active locale. Unknown locales are silently ignored (the
     * caller doesn't need to validate — we keep the previous choice).
     */
    public function setLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') return;
        // Accept "it-IT", "it_IT" → "it"
        $short = preg_split('/[-_]/', $locale)[0] ?? $locale;
        if (in_array($short, $this->available, true)) {
            $this->locale = $short;
        }
    }

    /**
     * Negotiate the best locale from an `Accept-Language` header value
     * like `it-IT,it;q=0.9,en;q=0.8`. Returns the highest-q match against
     * `availableLocales()`; falls back to the default locale.
     */
    public function negotiate(string $acceptLanguage): string
    {
        $acceptLanguage = trim($acceptLanguage);
        if ($acceptLanguage === '') return $this->defaultLocale;

        $candidates = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $q = 1.0;
            $code = $part;
            if (str_contains($part, ';')) {
                [$code, $params] = explode(';', $part, 2);
                $code = trim($code);
                if (preg_match('/q\s*=\s*([0-9.]+)/i', $params, $m)) {
                    $q = (float)$m[1];
                }
            }
            $short = strtolower(preg_split('/[-_]/', $code)[0] ?? $code);
            if ($short !== '' && in_array($short, $this->available, true)) {
                // Keep the highest q-value per locale.
                if (!isset($candidates[$short]) || $candidates[$short] < $q) {
                    $candidates[$short] = $q;
                }
            }
        }
        if (empty($candidates)) return $this->defaultLocale;
        arsort($candidates);
        return (string)array_key_first($candidates);
    }

    /**
     * Translate a key. Placeholders use `{name}` syntax and are replaced
     * with values from `$vars` (cast to string, HTML-escaped is the
     * caller's responsibility — this is a pure string helper).
     *
     * @param array<string, scalar|\Stringable|null> $vars
     */
    public function t(string $key, array $vars = []): string
    {
        $tpl = $this->lookup($this->locale, $key);
        if ($tpl === null && $this->locale !== $this->defaultLocale) {
            $tpl = $this->lookup($this->defaultLocale, $key);
        }
        if ($tpl === null) {
            return $key; // dev-visible signal
        }
        if (!$vars) return $tpl;
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{' . $k . '}'] = (string)($v ?? '');
        }
        return strtr($tpl, $repl);
    }

    protected function lookup(string $locale, string $key): ?string
    {
        if (!isset($this->cache[$locale])) {
            $this->cache[$locale] = $this->load($locale);
        }
        $value = $this->cache[$locale][$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Load and return the key=>template map for a locale.
     *
     * @return array<string, string>
     */
    protected function load(string $locale): array
    {
        $path = $this->config->path("app/Locales/$locale.php");
        if (!is_file($path)) return [];
        /** @var mixed $data */
        $data = require $path;
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) $out[$k] = $v;
        }
        return $out;
    }
}
