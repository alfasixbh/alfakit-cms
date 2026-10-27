<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\Models\SiteSettings;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The site design editors set on **Site design**: brand colours mapped onto the theme
 * variables every block already uses (`--primary`, `--background`…), fonts, corner
 * radius, logo and favicon, plus the custom CSS and code snippets of `customCode()`.
 * Everything that reaches the page is built here from validated values; custom CSS is
 * checked by `cssProblem()` and can never close its `<style>` element.
 */
final class SiteDesign
{
    public const COLORS = ['primary', 'secondary', 'background', 'text'];

    /**
     * Fonts editors can pick: the CSS stack and the fonts.bunny.net family (null for the
     * system font).
     *
     * @var array<string, array{label: string, stack: string, family: string|null}>
     */
    public const FONTS = [
        'system' => ['label' => '', 'stack' => 'ui-sans-serif, system-ui, sans-serif', 'family' => null],
        'inter' => ['label' => 'Inter', 'stack' => "'Inter', ui-sans-serif, system-ui, sans-serif", 'family' => 'inter:400,500,600,700'],
        'montserrat' => ['label' => 'Montserrat', 'stack' => "'Montserrat', ui-sans-serif, system-ui, sans-serif", 'family' => 'montserrat:400,500,600,700'],
        'poppins' => ['label' => 'Poppins', 'stack' => "'Poppins', ui-sans-serif, system-ui, sans-serif", 'family' => 'poppins:400,500,600,700'],
        'dm-sans' => ['label' => 'DM Sans', 'stack' => "'DM Sans', ui-sans-serif, system-ui, sans-serif", 'family' => 'dm-sans:400,500,600,700'],
        'lora' => ['label' => 'Lora', 'stack' => "'Lora', ui-serif, Georgia, serif", 'family' => 'lora:400,500,600,700'],
        'merriweather' => ['label' => 'Merriweather', 'stack' => "'Merriweather', ui-serif, Georgia, serif", 'family' => 'merriweather:400,700'],
        'playfair-display' => ['label' => 'Playfair Display', 'stack' => "'Playfair Display', ui-serif, Georgia, serif", 'family' => 'playfair-display:400,500,600,700'],
        'cairo' => ['label' => 'Cairo', 'stack' => "'Cairo', ui-sans-serif, system-ui, sans-serif", 'family' => 'cairo:400,500,600,700'],
        'tajawal' => ['label' => 'Tajawal', 'stack' => "'Tajawal', ui-sans-serif, system-ui, sans-serif", 'family' => 'tajawal:400,500,700'],
        'ibm-plex-sans-arabic' => ['label' => 'IBM Plex Sans Arabic', 'stack' => "'IBM Plex Sans Arabic', ui-sans-serif, system-ui, sans-serif", 'family' => 'ibm-plex-sans-arabic:400,500,600,700'],
    ];

    /** @var array<string, string> */
    public const RADII = ['none' => '0rem', 'small' => '0.375rem', 'medium' => '0.625rem', 'large' => '1rem'];

    /** The longest custom CSS and code snippet kept, in characters. */
    public const MAX_CODE = 50_000;

    /**
     * The design of a site's frontend payload (`cms.site.design`), null when nothing is set.
     *
     * @return array{css: string|null, fonts: string|null, logo: array{url: string, alt: string}|null, favicon: string|null}|null
     */
    public static function forSite(Site $site): ?array
    {
        $settings = self::settings($site);

        if ($settings === null) {
            return null;
        }

        $design = self::normalize($settings->design ?? []);
        $css = self::variables($design);

        if ($site->plugin->hasCustomCode()) {
            $custom = self::sanitizeCss(is_string($settings->code['css'] ?? null) ? $settings->code['css'] : null);
            $css = trim($css."\n".($custom ?? ''));
        }

        $disk = $site->plugin->getDisk();
        $logo = Media::url($design['logo'] ?? null, $disk);

        $payload = [
            'css' => $css === '' ? null : $css,
            'fonts' => self::fontsUrl($design),
            'logo' => $logo === null ? null : ['url' => $logo, 'alt' => $site->plugin->getSiteName()],
            'favicon' => Media::url($design['favicon'] ?? null, $disk),
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null) === [] ? null : $payload;
    }

    /**
     * The head and body snippets of `customCode()`, printed by the root view as
     * `$alfakitCmsCode` and `$alfakitCmsBodyCode`. Empty when custom code is off.
     *
     * @return array{head: HtmlString, body: HtmlString}
     */
    public static function code(Site $site): array
    {
        $settings = $site->plugin->hasCustomCode() ? self::settings($site) : null;
        $code = $settings === null ? [] : ($settings->code ?? []);

        return [
            'head' => new HtmlString(is_string($code['head'] ?? null) ? $code['head'] : ''),
            'body' => new HtmlString(is_string($code['body'] ?? null) ? $code['body'] : ''),
        ];
    }

    /**
     * The `<link>` tags of the design for root views without SSR (`$alfakitCmsHead`).
     *
     * @param  array{fonts?: string|null, favicon?: string|null}|null  $design
     */
    public static function toHtml(?array $design): string
    {
        $html = '';

        if (($design['favicon'] ?? null) !== null) {
            $html .= '<link rel="icon" href="'.e($design['favicon']).'">'."\n";
        }

        if (($design['fonts'] ?? null) !== null) {
            $html .= '<link rel="stylesheet" href="'.e($design['fonts']).'">'."\n";
        }

        return $html;
    }

    /**
     * Only known keys and valid values: `#rrggbb` colours, listed fonts and radii, stored paths.
     *
     * @param  array<mixed>  $design
     * @return array<string, string>
     */
    public static function normalize(array $design): array
    {
        $normalized = [];

        foreach (self::COLORS as $color) {
            $value = self::hex($design[$color] ?? null);

            if ($value !== null) {
                $normalized[$color] = $value;
            }
        }

        foreach (['heading_font', 'body_font'] as $key) {
            if (is_string($design[$key] ?? null) && isset(self::FONTS[$design[$key]])) {
                $normalized[$key] = $design[$key];
            }
        }

        if (is_string($design['radius'] ?? null) && isset(self::RADII[$design['radius']])) {
            $normalized['radius'] = $design['radius'];
        }

        foreach (['logo', 'favicon'] as $key) {
            $value = $design[$key] ?? null;
            $value = is_array($value) ? (array_values($value)[0] ?? null) : $value;

            if (is_string($value) && trim($value) !== '') {
                $normalized[$key] = trim($value);
            }
        }

        return $normalized;
    }

    /**
     * The theme variables of a design, on the document root so menus and dialogs rendered
     * outside the page follow them too. `:not(#…)` outranks the panel themes' selectors.
     *
     * @param  array<string, string>  $design
     */
    public static function variables(array $design): string
    {
        $vars = [];

        foreach (['primary', 'secondary'] as $color) {
            if (isset($design[$color])) {
                $vars["--{$color}"] = $design[$color];
                $vars["--{$color}-foreground"] = self::readableOn($design[$color]);
            }
        }

        if (isset($design['primary'])) {
            $vars['--ring'] = $design['primary'];
        }

        if (isset($design['background'])) {
            $vars['--background'] = $vars['--card'] = $vars['--popover'] = $design['background'];
        }

        if (isset($design['text'])) {
            $vars['--foreground'] = $vars['--card-foreground'] = $vars['--popover-foreground'] = $vars['--accent-foreground'] = $design['text'];
        }

        if (isset($design['background']) || isset($design['text'])) {
            // Soft surfaces, quiet text and borders follow the chosen pair, light or dark.
            $vars['--muted'] = $vars['--accent'] = 'color-mix(in oklab, var(--foreground) 6%, var(--background))';
            $vars['--muted-foreground'] = 'color-mix(in oklab, var(--foreground) 68%, var(--background))';
            $vars['--border'] = $vars['--input'] = 'color-mix(in oklab, var(--foreground) 14%, var(--background))';
        }

        if (isset($design['radius'])) {
            $vars['--radius'] = self::RADII[$design['radius']];
        }

        foreach (['body_font' => '--cms-font-body', 'heading_font' => '--cms-font-heading'] as $key => $var) {
            if (isset($design[$key])) {
                $vars[$var] = self::FONTS[$design[$key]]['stack'];
            }
        }

        if ($vars === []) {
            return '';
        }

        $declarations = implode('', array_map(static fn (string $name, string $value): string => "{$name}:{$value};", array_keys($vars), $vars));

        return "html:not(#alfakit-cms-design){{$declarations}}";
    }

    /**
     * The fonts.bunny.net stylesheet of the chosen fonts (GDPR-friendly: no visitor data
     * goes to Google), null for system fonts.
     *
     * @param  array<string, string>  $design
     */
    public static function fontsUrl(array $design): ?string
    {
        $families = [];

        foreach (['heading_font', 'body_font'] as $key) {
            $family = isset($design[$key]) ? self::FONTS[$design[$key]]['family'] : null;

            if ($family !== null) {
                $families[$family] = $family;
            }
        }

        return $families === [] ? null : 'https://fonts.bunny.net/css?family='.implode('|', $families).'&display=swap';
    }

    /**
     * Custom CSS that is safe to print in a `<style>` element, or null when `cssProblem()`
     * finds something it won't allow. `<` is written as its CSS escape, so the CSS can't
     * close the element.
     */
    public static function sanitizeCss(?string $css): ?string
    {
        if ($css === null || trim($css) === '' || self::cssProblem($css) !== null) {
            return null;
        }

        return str_replace('<', '\3c ', str_replace("\0", '', trim($css)));
    }

    /**
     * Why custom CSS is refused (a `alfakit-cms::cms.design.css_problems.*` key), null when
     * it is fine. Checked on the CSS with its escapes and comments removed, so `@\69mport`
     * is caught too: no `@import`, no scripts (`expression()`, `javascript:`, behaviours) and
     * no files from other sites, which could track visitors.
     */
    public static function cssProblem(string $css): ?string
    {
        if (mb_strlen($css) > self::MAX_CODE) {
            return 'too_long';
        }

        $plain = self::unescape($css);
        $plain = strtolower((string) preg_replace('#/\*.*?(\*/|$)#s', '', $plain));

        if (str_contains($plain, '@import')) {
            return 'import';
        }

        foreach (['expression(', 'javascript:', 'vbscript:', 'behavior', '-moz-binding'] as $needle) {
            if (str_contains(str_replace([' ', "\t", "\n", "\r"], '', $plain), $needle)) {
                return 'script';
            }
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (preg_match_all('#(?:[a-z][a-z0-9+.\-]*:)?//([^/\s\'")\\\\]+)#', $plain, $matches) > 0) {
            foreach ($matches[1] as $found) {
                if ($host === '' || strtolower((string) preg_replace('/:\d+$/', '', $found)) !== $host) {
                    return 'external';
                }
            }
        }

        // `url(data:…)` only for images; other schemes (`url(file:…)`) are refused.
        if (preg_match_all('#url\(\s*[\'"]?\s*([a-z][a-z0-9+.\-]*):#', $plain, $schemes) > 0) {
            foreach ($schemes[1] as $index => $scheme) {
                $rest = substr($plain, (int) strpos($plain, $schemes[0][$index]) + strlen($schemes[0][$index]), 12);

                if (! in_array($scheme, ['http', 'https'], true) && ! ($scheme === 'data' && str_starts_with($rest, 'image/'))) {
                    return 'external';
                }
            }
        }

        return null;
    }

    /**
     * A `#rrggbb` colour, or null.
     */
    public static function hex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $short) === 1) {
            $value = '#'.implode('', array_map(static fn (string $digit): string => $digit.$digit, str_split($short[1])));
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * Black or white text, whichever reads better on the colour (WCAG contrast).
     */
    public static function readableOn(string $hex): string
    {
        $luminance = self::luminance($hex);

        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#ffffff' : '#0a0a0a';
    }

    /**
     * The WCAG contrast ratio of two `#rrggbb` colours.
     */
    public static function contrast(string $a, string $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * The stored settings of the site's tenant, memoised per request; null when the table
     * isn't migrated yet (upgrades), so public pages keep working.
     */
    public static function settings(Site $site): ?SiteSettings
    {
        $request = request();
        $key = 'alfakit-cms.site-settings.'.($site->tenant?->getKey() ?? '');

        if ($request->attributes->has($key)) {
            $cached = $request->attributes->get($key);

            return $cached instanceof SiteSettings ? $cached : null;
        }

        try {
            $settings = SiteSettings::query()->forTenant($site->tenant)->first();
        } catch (Throwable) {
            $settings = null;
        }

        $request->attributes->set($key, $settings);

        return $settings instanceof SiteSettings ? $settings : null;
    }

    /**
     * Turns CSS escapes (`\69`, `\i`) back into the characters they stand for.
     */
    private static function unescape(string $css): string
    {
        $css = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function (array $match): string {
            $code = (int) hexdec($match[1]);

            return $code > 0 && $code <= 0x10FFFF ? (string) mb_chr($code, 'UTF-8') : '';
        }, $css);

        return (string) preg_replace('/\\\\(.)/s', '$1', $css);
    }
}
