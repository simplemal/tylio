<?php
declare(strict_types=1);

namespace Tylio\Util;

/**
 * Recognizes podcast URLs across the major platforms and extracts the
 * information needed to render them: official iframe embed (Spotify, Apple)
 * or "link card" (everything else).
 */
final class PodcastEmbed
{
    /**
     * Parse the URL and return:
     *   - 'platform' : 'spotify' | 'apple' | 'amazon' | 'google' | 'youtube' |
     *                  'overcast' | 'pocketcasts' | 'castbox' | 'rss' | 'other'
     *   - 'kind'     : 'show' | 'episode' | 'unknown'  (auto-derived from URL)
     *   - 'embed_url': string|null   iframe player URL (Spotify/Apple only)
     *   - 'open_url' : string        canonical URL for "Open on platform"
     *   - 'icon'     : string        iconify name (simple-icons:* or lucide:*)
     *   - 'platform_label' : string  human-readable platform name
     *
     * `kind` is ALWAYS derived from the URL pasted by the user:
     *   - Spotify /show/X      → show
     *   - Spotify /episode/Y   → episode
     *   - Apple   idNNN        → show
     *   - Apple   idNNN?i=MMM  → episode
     * No "mode" toggle in the registry — it would be illusory (the
     * Spotify/Apple embed must use the URL that matches the resource type).
     *
     * @return array{
     *   platform: string,
     *   kind: string,
     *   embed_url: ?string,
     *   open_url: string,
     *   icon: string,
     *   platform_label: string,
     * }
     */
    public static function parse(string $url): array
    {
        $u = trim($url);
        $defaults = [
            'platform' => 'other',
            'kind' => 'unknown',
            'embed_url' => null,
            'open_url' => $u,
            'icon' => 'lucide:podcast',
            'platform_label' => 'Podcast',
        ];
        if ($u === '') return $defaults;

        // === Spotify ===
        // open.spotify.com/show/ID  →  embed.spotify.com/show/ID
        // open.spotify.com/episode/ID  →  embed.spotify.com/episode/ID
        if (preg_match('#(?:open\.)?spotify\.com/(show|episode)/([A-Za-z0-9]+)#i', $u, $m)) {
            $kind = $m[1] === 'episode' ? 'episode' : 'show';
            return [
                'platform' => 'spotify',
                'kind' => $kind,
                'embed_url' => "https://open.spotify.com/embed/{$kind}/{$m[2]}",
                'open_url' => $u,
                'icon' => 'simple-icons:spotify',
                'platform_label' => 'Spotify',
            ];
        }

        // === Apple Podcasts ===
        // Recognize both historical domains:
        //   - podcasts.apple.com/<locale>/podcast/<slug>/idNNN
        //   - itunes.apple.com/<locale>/podcast/<slug>/idNNN  (old format,
        //     still widely in the wild: Apple redirects to podcasts.*)
        // "?i=NNN" is the optional episode ID: if present, embed = episode.
        if (preg_match('#(?:podcasts|itunes)\.apple\.com/.*?id(\d+)#i', $u, $m)) {
            $podcastId = 'id' . $m[1];
            $episodeId = preg_match('#[?&]i=(\d+)#i', $u, $em) ? $em[1] : null;
            $embed = "https://embed.podcasts.apple.com/podcast/x/{$podcastId}";
            $kind = 'show';
            if ($episodeId !== null) {
                $embed .= "?i={$episodeId}";
                $kind = 'episode';
            }
            return [
                'platform' => 'apple',
                'kind' => $kind,
                'embed_url' => $embed,
                'open_url' => $u,
                'icon' => 'simple-icons:applepodcasts',
                'platform_label' => 'Apple Podcasts',
            ];
        }

        // === Other platforms: link card only, no embed ===
        $patterns = [
            'amazon'     => ['#music\.amazon\.[a-z.]+/podcasts/#i', 'simple-icons:amazonmusic', 'Amazon Music'],
            'google'     => ['#podcasts\.google\.com#i', 'simple-icons:googlepodcasts', 'Google Podcasts'],
            'youtube'    => ['#(?:music\.)?youtube\.com/(?:playlist|channel|@|watch)#i', 'simple-icons:youtube', 'YouTube Music'],
            'overcast'   => ['#overcast\.fm#i', 'simple-icons:overcast', 'Overcast'],
            'pocketcasts'=> ['#pca\.st|pocketcasts\.com#i', 'simple-icons:pocketcasts', 'Pocket Casts'],
            'castbox'    => ['#castbox\.fm#i', 'simple-icons:castbox', 'Castbox'],
        ];
        foreach ($patterns as $platform => [$re, $icon, $label]) {
            if (preg_match($re, $u)) {
                return [
                    'platform' => $platform,
                    'kind' => 'unknown',
                    'embed_url' => null,
                    'open_url' => $u,
                    'icon' => $icon,
                    'platform_label' => $label,
                ];
            }
        }

        // RSS feed: no player but it's the "real" feed → dedicated icon
        if (preg_match('#\.(xml|rss)(\?|$)|/rss/?#i', $u)) {
            return [
                'platform' => 'rss',
                'kind' => 'unknown',
                'embed_url' => null,
                'open_url' => $u,
                'icon' => 'lucide:rss',
                'platform_label' => 'Feed RSS',
            ];
        }

        return $defaults;
    }
}
