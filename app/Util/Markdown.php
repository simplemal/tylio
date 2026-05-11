<?php
declare(strict_types=1);

namespace Tylio\Util;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown rendering based on league/commonmark.
 *
 * Hardened configuration:
 *  - html_input = strip      → raw HTML tags in the source are removed (no XSS via raw HTML)
 *  - allow_unsafe_links = false → blocks javascript:, vbscript:, data:, file:
 *  - ExternalLinkExtension   → all http(s) links get rel="noopener noreferrer" and target=_blank
 *
 * Replaces a regex-based implementation that was hard to audit.
 */
final class Markdown
{
    private static ?MarkdownConverter $converter = null;

    public static function render(string $md): string
    {
        return self::converter()->convert($md)->getContent();
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter === null) {
            $env = new Environment([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'external_link' => [
                    'internal_hosts' => [],
                    'open_in_new_window' => true,
                    'noopener' => 'external',
                    'noreferrer' => 'external',
                ],
            ]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new AutolinkExtension());
            $env->addExtension(new ExternalLinkExtension());
            self::$converter = new MarkdownConverter($env);
        }
        return self::$converter;
    }
}
