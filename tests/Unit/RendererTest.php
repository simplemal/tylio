<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tylio\Services\Renderer;

/**
 * Tests for the Renderer's "pure" helpers (safeUrl, cssColor, embedUrl).
 * They are security-critical: invoked on every user-controlled href on the
 * public site (links, gallery, social, footer, products, apps).
 *
 * We use `newInstanceWithoutConstructor()` because these methods don't touch
 * `$this->db / $registry / $config` — a real DB isn't needed for the test.
 */
final class RendererTest extends TestCase
{
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = (new ReflectionClass(Renderer::class))->newInstanceWithoutConstructor();
    }

    // ===== safeUrl =====================================================

    public function test_safeurl_keeps_https(): void
    {
        $this->assertSame('https://example.com', $this->renderer->safeUrl('https://example.com'));
    }

    public function test_safeurl_keeps_http(): void
    {
        $this->assertSame('http://example.com', $this->renderer->safeUrl('http://example.com'));
    }

    public function test_safeurl_keeps_mailto(): void
    {
        $this->assertSame('mailto:a@b.it', $this->renderer->safeUrl('mailto:a@b.it'));
    }

    public function test_safeurl_keeps_tel(): void
    {
        $this->assertSame('tel:+391234567', $this->renderer->safeUrl('tel:+391234567'));
    }

    public function test_safeurl_keeps_relative_path(): void
    {
        $this->assertSame('/about', $this->renderer->safeUrl('/about'));
        $this->assertSame('/foo/bar?x=1', $this->renderer->safeUrl('/foo/bar?x=1'));
    }

    public function test_safeurl_keeps_anchor(): void
    {
        $this->assertSame('#section', $this->renderer->safeUrl('#section'));
    }

    public function test_safeurl_blocks_javascript(): void
    {
        $this->assertSame('#', $this->renderer->safeUrl('javascript:alert(1)'));
        $this->assertSame('#', $this->renderer->safeUrl('JAVASCRIPT:alert(1)')); // case-insensitive
    }

    public function test_safeurl_blocks_data(): void
    {
        $this->assertSame('#', $this->renderer->safeUrl('data:text/html,<script>'));
    }

    public function test_safeurl_blocks_vbscript(): void
    {
        $this->assertSame('#', $this->renderer->safeUrl('vbscript:msgbox'));
    }

    public function test_safeurl_blocks_file(): void
    {
        $this->assertSame('#', $this->renderer->safeUrl('file:///etc/passwd'));
    }

    public function test_safeurl_blocks_protocol_relative(): void
    {
        // // is ambiguous (inherits the parent's scheme); better to block it.
        $this->assertSame('#', $this->renderer->safeUrl('//evil.example.com'));
    }

    public function test_safeurl_blocks_control_chars(): void
    {
        // Possible header injection / smuggling
        $this->assertSame('#', $this->renderer->safeUrl("https://ok\r\nLocation: evil"));
        $this->assertSame('#', $this->renderer->safeUrl("https://ok\x00x"));
    }

    public function test_safeurl_uses_custom_fallback(): void
    {
        $this->assertSame('', $this->renderer->safeUrl('javascript:x', ''));
        $this->assertSame('/safe', $this->renderer->safeUrl('javascript:x', '/safe'));
    }

    public function test_safeurl_empty_returns_fallback(): void
    {
        $this->assertSame('#', $this->renderer->safeUrl(''));
        $this->assertSame('#', $this->renderer->safeUrl('   '));
    }

    // ===== cssColor =====================================================

    public function test_csscolor_accepts_hex_short(): void
    {
        $this->assertSame('#abc', $this->renderer->cssColor('#abc'));
    }

    public function test_csscolor_accepts_hex_long(): void
    {
        $this->assertSame('#aabbcc', $this->renderer->cssColor('#aabbcc'));
    }

    public function test_csscolor_accepts_rgb(): void
    {
        $this->assertSame('rgb(10, 20, 30)', $this->renderer->cssColor('rgb(10, 20, 30)'));
    }

    public function test_csscolor_accepts_rgba(): void
    {
        $this->assertSame('rgba(10,20,30,0.5)', $this->renderer->cssColor('rgba(10,20,30,0.5)'));
    }

    public function test_csscolor_rejects_color_name(): void
    {
        // For now color names (red, blue, …) are not supported: they return null
        // so nothing un-validated is passed into `style="..."`.
        $this->assertNull($this->renderer->cssColor('red'));
    }

    public function test_csscolor_rejects_css_injection(): void
    {
        // Attack pattern: color name that closes the declaration and opens a
        // new one.
        $this->assertNull($this->renderer->cssColor('red;background:url(x)'));
        $this->assertNull($this->renderer->cssColor('#fff;}body{display:none'));
        $this->assertNull($this->renderer->cssColor('expression(alert(1))'));
    }

    public function test_csscolor_rejects_empty(): void
    {
        $this->assertNull($this->renderer->cssColor(''));
        $this->assertNull($this->renderer->cssColor('   '));
    }

    // ===== embedUrl =====================================================

    public function test_embedurl_youtube_watch(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $this->renderer->embedUrl('youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
        );
    }

    public function test_embedurl_youtube_short(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $this->renderer->embedUrl('youtube', 'https://youtu.be/dQw4w9WgXcQ'),
        );
    }

    public function test_embedurl_vimeo(): void
    {
        $this->assertSame(
            'https://player.vimeo.com/video/12345',
            $this->renderer->embedUrl('vimeo', 'https://vimeo.com/12345'),
        );
    }

    public function test_embedurl_spotify_track(): void
    {
        $this->assertSame(
            'https://open.spotify.com/embed/track/abc123',
            $this->renderer->embedUrl('spotify', 'https://open.spotify.com/track/abc123'),
        );
    }

    public function test_embedurl_iframe_only_https(): void
    {
        $this->assertSame(
            'https://example.com/embed',
            $this->renderer->embedUrl('iframe', 'https://example.com/embed'),
        );
        // No scheme → null
        $this->assertNull($this->renderer->embedUrl('iframe', 'example.com/embed'));
        // exotic scheme → null
        $this->assertNull($this->renderer->embedUrl('iframe', 'javascript:alert(1)'));
    }

    public function test_embedurl_unknown_provider(): void
    {
        $this->assertNull($this->renderer->embedUrl('unknown', 'https://example.com'));
    }

    public function test_embedurl_empty_url(): void
    {
        $this->assertNull($this->renderer->embedUrl('youtube', ''));
        $this->assertNull($this->renderer->embedUrl('vimeo', ''));
    }

    // ===== escape ======================================================

    public function test_escape_handles_html_special_chars(): void
    {
        $this->assertSame('&lt;script&gt;', $this->renderer->escape('<script>'));
        $this->assertSame('&quot;hi&quot;', $this->renderer->escape('"hi"'));
        $this->assertSame('&amp;', $this->renderer->escape('&'));
    }
}
