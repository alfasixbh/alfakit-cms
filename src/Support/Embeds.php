<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

/**
 * The iframe or video source of an embed URL. Only YouTube (privacy-enhanced mode),
 * Vimeo, `https` video files and the plugin's `embedHosts()` are embedded; anything else
 * is dropped, so editors can't place arbitrary iframes on the site.
 */
final class Embeds
{
    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

    private const VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    /**
     * @param  array<int, string>  $extraHosts
     * @return array{provider: 'youtube'|'vimeo'|'video'|'iframe', src: string}|null
     */
    public static function resolve(?string $url, array $extraHosts = []): ?array
    {
        $url = trim((string) $url);
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            $id = match (true) {
                $host === 'youtu.be' => trim($path, '/'),
                preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $matches) === 1 => $matches[2],
                default => is_string($query['v'] ?? null) ? $query['v'] : '',
            };

            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
                return null;
            }

            $start = self::seconds($query['t'] ?? $query['start'] ?? null);

            return ['provider' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/'.$id.($start > 0 ? '?start='.$start : '')];
        }

        if (in_array($host, self::VIMEO_HOSTS, true)) {
            if (preg_match('#^/(?:video/)?(\d{1,12})(?:/([a-f0-9]{6,20}))?/?$#', $path, $matches) !== 1) {
                return null;
            }

            $hash = $matches[2] ?? null;

            return ['provider' => 'vimeo', 'src' => 'https://player.vimeo.com/video/'.$matches[1].($hash ? '?h='.$hash : '')];
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        if (preg_match('/\.(mp4|webm|ogg|ogv|m4v)$/i', $path) === 1) {
            return ['provider' => 'video', 'src' => $url];
        }

        if (in_array($host, $extraHosts, true)) {
            return ['provider' => 'iframe', 'src' => $url];
        }

        return null;
    }

    private static function seconds(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            return 0;
        }

        $value = (string) $value;

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $matches) === 1) {
            return ((int) ($matches[1] ?? 0)) * 3600 + ((int) ($matches[2] ?? 0)) * 60 + (int) ($matches[3] ?? 0);
        }

        return 0;
    }
}
