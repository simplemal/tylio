<?php
declare(strict_types=1);

namespace Tylio\Util;

use Tylio\Config;

/**
 * Minimal fetcher for YouTube's public RSS feeds.
 *
 * YouTube exposes — without requiring an API key — two XML endpoints:
 *   - channel:   https://www.youtube.com/feeds/videos.xml?channel_id=UCxxx
 *   - playlist:  https://www.youtube.com/feeds/videos.xml?playlist_id=PLxxx
 *
 * The first `<entry>` is always the most recent video. We cache the XML
 * body on disk (data/cache/) for `CACHE_TTL` seconds so public pages with
 * a YouTube tile don't hammer www.youtube.com on every page-load.
 *
 * On network error, if a stale cache exists we still use it (degraded
 * mode preferable to a "broken block").
 */
final class YouTubeFeed
{
    private const CACHE_TTL = 3600;        // 1h
    private const FETCH_TIMEOUT = 5;
    private const MAX_RESPONSE = 1_048_576; // 1MB hardlimit

    /**
     * Resolve a user input (full URL, raw ID, @handle, /c/, /user/) into
     * `[type, id]` where type ∈ {'channel', 'playlist'} and id is the
     * canonical identifier (UC… for channels, PL/UU/OL/FL/RD… for
     * playlists). Returns `[null, null]` if unrecognized.
     *
     * For YouTube "vanity" URLs (`@handle`, `/c/X`, `/user/X`) we need
     * Config to fetch the HTML page and scrape the channel ID — those URLs
     * don't expose it in the path. Without Config (e.g. isolated tests)
     * we fall back to "unrecognized" and the template shows an explicit
     * error.
     *
     * @return array{0: 'channel'|'playlist'|null, 1: ?string}
     */
    public static function parseSource(string $input, ?Config $config = null): array
    {
        $s = trim($input);
        if ($s === '') return [null, null];

        // Channel URL: /channel/UCxxx (canonical format, always available
        // via YouTube's "Share" → "Copy channel link")
        if (preg_match('#/channel/(UC[\w-]{20,})#', $s, $m)) {
            return ['channel', $m[1]];
        }
        // Playlist URL: ?list=PLxxx (also inside watch URLs)
        if (preg_match('#[?&]list=([\w-]{10,})#', $s, $m)) {
            return ['playlist', $m[1]];
        }
        // Bare channel ID
        if (preg_match('#^UC[\w-]{20,}$#', $s)) {
            return ['channel', $s];
        }
        // Bare playlist ID (PL = public, UU = channel uploads, OL = others,
        // FL = favorites, RD = mix). UU<id> is equivalent to a channel's uploads.
        if (preg_match('#^(PL|UU|OL|FL|RD|LL)[\w-]+$#', $s)) {
            return ['playlist', $s];
        }

        // === Vanity URLs (need an HTML fetch to extract the channel_id) ===
        // Non-canonical YouTube channel URLs don't carry the channel_id in
        // the path:
        //   - /@handle           (current default)
        //   - /c/customname      (legacy custom URL with `c/` prefix)
        //   - /user/legacyname   (very old 2010s registrations)
        //   - /directvanityname  (prefix-less vanity, rare but still alive
        //                         for historical channels)
        // To resolve to the canonical UC… id we fetch + scrape the page
        // and cache the result for 30 days.
        //
        // The only filter here is negative: there are YouTube paths that
        // are NOT channel pages and we exclude them to avoid wasting HTTP.
        if ($config !== null
            && preg_match('#youtube\.com/[\w@.-]+#i', $s)
            && !preg_match(
                '#youtube\.com/(?:watch|embed|shorts|results|feed|signin|signout|account|attribution_link|redirect|oops|howyoutubeworks|terms|sub_confirmation|t/|tv\b|gaming\b|music\b|premium\b|live\b|playlist\?)#i',
                $s,
            )
        ) {
            $resolved = self::resolveVanityToChannelId($config, $s);
            if ($resolved !== null) return ['channel', $resolved];
        }

        return [null, null];
    }

    /**
     * Resolve a YouTube "vanity" URL (@handle, /c/, /user/) to its UC…
     * channel id by scraping the HTML page. The result is cached for 30
     * days (handle → channelId is effectively permanent).
     */
    private static function resolveVanityToChannelId(Config $config, string $url): ?string
    {
        $cacheDir = $config->path('data/cache');
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
        $cacheFile = $cacheDir . '/yt_vanity_' . md5($url) . '.txt';

        if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 86400 * 30) {
            $cached = trim((string)@file_get_contents($cacheFile));
            if (preg_match('#^UC[\w-]{20,}$#', $cached)) return $cached;
        }

        $html = self::httpGet($url);
        if ($html === null || strlen($html) < 200) return null;

        // Search in multiple places (YouTube changes its rendering often —
        // having fallbacks helps). Ordered by decreasing reliability.
        $patterns = [
            '#"channelId":"(UC[\w-]+)"#',                                            // YT JSON initialization
            '#<meta itemprop="(?:channelId|identifier)" content="(UC[\w-]+)"#i',    // microdata
            '#<link rel="canonical" href="https?://[^"]*?/channel/(UC[\w-]+)#i',    // canonical link
            '#"externalChannelId":"(UC[\w-]+)"#',                                   // alt key in payload
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $html, $m)) {
                @file_put_contents($cacheFile, $m[1]);
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Return the video ID of the latest video for a channel/playlist,
     * or null on fetch/parse failure. Thin back-compat wrapper around
     * fetchFeedInfo (the richer API).
     */
    public static function latestVideoId(Config $config, string $type, string $id): ?string
    {
        $info = self::fetchFeedInfo($config, $type, $id);
        return ($info !== null && !empty($info['entries'])) ? (string)$info['entries'][0]['video_id'] : null;
    }

    /**
     * Extract the full RSS feed in structured form: feed title (channel
     * name or playlist title), author, canonical link, and every entry
     * with videoId/title/date/thumbnail. Shares the same RSS cache file
     * as latestVideoId (1h TTL).
     *
     * @return array{
     *   feed_title: string,
     *   feed_link: string,
     *   author_name: string,
     *   author_uri: string,
     *   entries: list<array{video_id: string, title: string, published: string, thumbnail: string}>
     * }|null
     */
    public static function fetchFeedInfo(Config $config, string $type, string $id): ?array
    {
        $xml = self::fetchXml($config, $type, $id);
        if ($xml === null) return null;
        try {
            $prev = libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);
            libxml_use_internal_errors($prev);
            if ($doc === false) return null;
            $doc->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');

            $entries = [];
            foreach ($doc->entry as $entry) {
                $yt = $entry->children('yt', true);
                $vid = (string)$yt->videoId;
                if (!preg_match('/^[\w-]{11}$/', $vid)) continue;
                $entries[] = [
                    'video_id' => $vid,
                    'title' => (string)$entry->title,
                    'published' => (string)$entry->published,
                    'thumbnail' => "https://i.ytimg.com/vi/{$vid}/hqdefault.jpg",
                ];
            }

            $linkAttr = $doc->link ? (string)$doc->link['href'] : '';

            return [
                'feed_title' => (string)$doc->title,
                'feed_link' => $linkAttr,
                'author_name' => (string)$doc->author->name,
                'author_uri' => (string)$doc->author->uri,
                'entries' => $entries,
            ];
        } catch (\Throwable $e) {
            error_log('[tylio yt] feed parse: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extended channel metadata (name, avatar, banner, description)
     * scraped from the public HTML page `youtube.com/channel/UCxxx`.
     * The RSS feed doesn't expose them, but the channel page does via
     * OG meta + embedded `ytInitialData` JSON. JSON cache lives 7 days.
     *
     * @return array{
     *   name: string,
     *   avatar: ?string,
     *   banner: ?string,
     *   description: ?string,
     * }|null
     */
    public static function fetchChannelMeta(Config $config, string $channelId): ?array
    {
        if (!preg_match('/^UC[\w-]{20,}$/', $channelId)) return null;
        $cacheDir = $config->path('data/cache');
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
        $cacheFile = $cacheDir . "/yt_channelmeta_{$channelId}.json";

        if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 86400 * 7) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['name'])) return $data;
            }
        }

        $html = self::httpGet("https://www.youtube.com/channel/{$channelId}");
        if ($html === null || strlen($html) < 200) {
            // Stale cache fallback
            if (is_file($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['name'])) return $data;
                }
            }
            return null;
        }

        $meta = self::parseChannelHtml($html);
        if ($meta === null) return null;

        @file_put_contents($cacheFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $meta;
    }

    /**
     * Parse a YouTube channel's HTML page and extract name, avatar,
     * banner, description. Uses OG meta (stable) + a regex on the
     * `ytInitialData` JSON (more fragile but needed for the avatar).
     *
     * @return array{name: string, avatar: ?string, banner: ?string, description: ?string}|null
     */
    private static function parseChannelHtml(string $html): ?array
    {
        $name = null;
        $banner = null;
        $description = null;
        $avatar = null;

        if (preg_match('#<meta\s+property="og:title"\s+content="([^"]+)"#i', $html, $m)) {
            $name = self::decodeHtmlEntities($m[1]);
        }
        if (preg_match('#<meta\s+property="og:image"\s+content="([^"]+)"#i', $html, $m)) {
            $banner = self::decodeHtmlEntities($m[1]);
        }
        if (preg_match('#<meta\s+property="og:description"\s+content="([^"]+)"#i', $html, $m)) {
            $description = self::decodeHtmlEntities($m[1]);
        }

        // Avatar: search in ytInitialData. Multiple patterns as fallback —
        // YouTube changes the key names often.
        $avatarRegexes = [
            '#"avatar":\{"thumbnails":\[\{"url":"([^"]+)"#',
            '#"channelAvatarRenderer"[^}]{0,400}"thumbnails":\[\{"url":"([^"]+)"#',
            '#"avatarViewModel":\{"image":\{"sources":\[\{"url":"([^"]+)"#',
        ];
        foreach ($avatarRegexes as $re) {
            if (preg_match($re, $html, $m)) {
                $avatar = self::decodeJsonString($m[1]);
                break;
            }
        }

        if ($name === null || $name === '') return null;

        return [
            'name' => $name,
            'avatar' => $avatar,
            'banner' => $banner,
            'description' => $description,
        ];
    }

    /**
     * Decode a string extracted from YouTube JSON (handles \u00XX unicode
     * and \/ slash escapes that json_decode understands natively).
     */
    private static function decodeJsonString(string $s): string
    {
        $decoded = json_decode('"' . $s . '"', true);
        return is_string($decoded) ? $decoded : $s;
    }

    private static function decodeHtmlEntities(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function fetchXml(Config $config, string $type, string $id): ?string
    {
        $cacheDir = $config->path('data/cache');
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        $cacheFile = $cacheDir . "/yt_{$type}_{$safeId}.xml";

        if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < self::CACHE_TTL) {
            $body = @file_get_contents($cacheFile);
            return $body === false ? null : $body;
        }

        $url = $type === 'channel'
            ? "https://www.youtube.com/feeds/videos.xml?channel_id={$id}"
            : "https://www.youtube.com/feeds/videos.xml?playlist_id={$id}";

        $body = self::httpGet($url);
        if ($body !== null && strlen($body) > 0 && strlen($body) <= self::MAX_RESPONSE) {
            @file_put_contents($cacheFile, $body);
            return $body;
        }

        // Network failure → reuse the stale cache if any exists.
        if (is_file($cacheFile)) {
            $body = @file_get_contents($cacheFile);
            return $body === false ? null : $body;
        }
        return null;
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT,
                CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
                CURLOPT_USERAGENT => self::userAgent(),
                CURLOPT_FAILONERROR => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                error_log('[tylio yt] curl: ' . $err);
                return null;
            }
            return is_string($body) ? $body : null;
        }
        // Fallback without curl
        $ctx = stream_context_create(['http' => [
            'timeout' => self::FETCH_TIMEOUT,
            'header' => 'User-Agent: ' . self::userAgent() . "\r\n",
            'follow_location' => 1,
            'max_redirects' => 3,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /**
     * User-Agent used when fetching the YouTube RSS feed. Honors APP_NAME
     * + APP_URL so a fork rebranding the project also rebrands the UA.
     * Falls back to a generic identifier when env vars are unset.
     */
    private static function userAgent(): string
    {
        $name = getenv('APP_NAME');
        $url = getenv('APP_URL');
        $name = is_string($name) && $name !== '' ? $name : 'tylio';
        $ua = $name . '/1.0';
        if (is_string($url) && $url !== '') {
            $ua .= ' (+' . $url . ')';
        }
        return $ua;
    }
}
