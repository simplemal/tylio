<?php
declare(strict_types=1);

namespace Tylio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tylio\Util\Markdown;

/**
 * Markdown rendering — security regression suite.
 *
 * Critical: all these patterns were known XSS / open-redirect vectors in the
 * regex-based micro-renderers. We verify that the replacement with
 * league/commonmark (config: html_input=strip, allow_unsafe_links=false,
 * ExternalLinkExtension) blocks them all.
 */
final class MarkdownTest extends TestCase
{
    public function test_basic_inline_renders(): void
    {
        $out = Markdown::render('**bold** and *italic* and `code`');
        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<em>italic</em>', $out);
        $this->assertStringContainsString('<code>code</code>', $out);
    }

    public function test_headings_render(): void
    {
        $out = Markdown::render("# H1\n\n## H2\n\n### H3");
        $this->assertStringContainsString('<h1>H1</h1>', $out);
        $this->assertStringContainsString('<h2>H2</h2>', $out);
        $this->assertStringContainsString('<h3>H3</h3>', $out);
    }

    public function test_unordered_list_renders(): void
    {
        $out = Markdown::render("- a\n- b\n- c");
        $this->assertStringContainsString('<ul>', $out);
        $this->assertStringContainsString('<li>a</li>', $out);
        $this->assertStringContainsString('<li>c</li>', $out);
    }

    public function test_external_link_gets_rel_and_target(): void
    {
        $out = Markdown::render('[click](https://example.com)');
        $this->assertStringContainsString('href="https://example.com"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function test_javascript_url_is_blocked(): void
    {
        $out = Markdown::render('[click](javascript:alert(1))');
        // The href with the javascript: scheme must have been removed (link without href).
        $this->assertStringNotContainsString('href="javascript:', $out);
        $this->assertStringNotContainsString('javascript:alert', $out);
    }

    public function test_data_url_is_blocked(): void
    {
        $out = Markdown::render('[x](data:text/html,<script>alert(1)</script>)');
        $this->assertStringNotContainsString('href="data:', $out);
        // The payload content must not appear as executable HTML.
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function test_vbscript_url_is_blocked(): void
    {
        $out = Markdown::render('[x](vbscript:msgbox)');
        $this->assertStringNotContainsString('href="vbscript:', $out);
    }

    public function test_raw_html_is_stripped(): void
    {
        $out = Markdown::render("Plain text\n\n<script>alert('xss')</script>\n\nMore text");
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString("alert('xss')", $out);
    }

    public function test_iframe_in_markdown_is_stripped(): void
    {
        $out = Markdown::render('<iframe src="https://evil.example"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_onclick_attribute_in_raw_html_is_stripped(): void
    {
        $out = Markdown::render('<a href="x" onclick="alert(1)">click</a>');
        // Raw HTML must be stripped; at most the "click" text survives.
        $this->assertStringNotContainsString('onclick=', $out);
    }

    public function test_code_block_preserves_content_as_text(): void
    {
        $out = Markdown::render("```\n<script>alert(1)</script>\n```");
        // The code block escapes the HTML as text and does not execute it.
        $this->assertStringContainsString('<pre>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>alert', $out);
    }

    public function test_relative_link_preserved(): void
    {
        $out = Markdown::render('[home](/about)');
        $this->assertStringContainsString('href="/about"', $out);
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame('', trim(Markdown::render('')));
    }
}
