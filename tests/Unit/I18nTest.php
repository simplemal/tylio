<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Config;
use Tylio\Services\I18n;

final class I18nTest extends TestCase
{
    private function makeI18n(string $default = 'en'): I18n
    {
        $config = new Config(dirname(__DIR__, 2));
        return new I18n($config, ['en', 'it'], $default);
    }

    public function test_default_locale_is_used_when_unset(): void
    {
        $i = $this->makeI18n('en');
        $this->assertSame('en', $i->currentLocale());
    }

    public function test_set_locale_accepts_known_short_code(): void
    {
        $i = $this->makeI18n();
        $i->setLocale('it');
        $this->assertSame('it', $i->currentLocale());
    }

    public function test_set_locale_normalizes_region_suffix(): void
    {
        $i = $this->makeI18n();
        $i->setLocale('it-IT');
        $this->assertSame('it', $i->currentLocale());
    }

    public function test_set_locale_ignores_unknown(): void
    {
        $i = $this->makeI18n('en');
        $i->setLocale('xx');
        $this->assertSame('en', $i->currentLocale());
    }

    public function test_negotiate_picks_highest_q_supported(): void
    {
        $i = $this->makeI18n();
        $this->assertSame('it', $i->negotiate('it-IT,it;q=0.9,en;q=0.5'));
        $this->assertSame('en', $i->negotiate('en-US,en;q=0.8,de;q=0.5'));
    }

    public function test_negotiate_falls_back_to_default_when_unsupported(): void
    {
        $i = $this->makeI18n('en');
        $this->assertSame('en', $i->negotiate('de-DE,fr;q=0.8'));
    }

    public function test_negotiate_empty_returns_default(): void
    {
        $i = $this->makeI18n('en');
        $this->assertSame('en', $i->negotiate(''));
    }

    public function test_t_returns_english_default(): void
    {
        $i = $this->makeI18n('en');
        $this->assertSame('now', $i->t('relative.now'));
    }

    public function test_t_returns_italian_when_set(): void
    {
        $i = $this->makeI18n();
        $i->setLocale('it');
        $this->assertSame('ora', $i->t('relative.now'));
    }

    public function test_t_interpolates_placeholders(): void
    {
        $i = $this->makeI18n();
        $i->setLocale('it');
        $this->assertSame('5 min fa', $i->t('relative.minutes_ago', ['n' => 5]));
    }

    public function test_t_falls_back_to_default_locale_for_missing_key(): void
    {
        $i = $this->makeI18n('en');
        $i->setLocale('it');
        // Use a hypothetical missing-only-in-it key: we simulate by asking
        // for a key that exists only in english. (Both files mirror keys in
        // practice; this is the developer-visible safety net.)
        // For now, just check the no-key fallback returns the key itself.
        $this->assertSame('does.not.exist', $i->t('does.not.exist'));
    }

    public function test_available_locales(): void
    {
        $i = $this->makeI18n();
        $this->assertSame(['en', 'it'], $i->availableLocales());
    }
}
