<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Support\Url;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The head metadata of a public page: title, description, canonical URL, robots, Open
 * Graph and Twitter tags, `hreflang` alternates and a JSON-LD graph (`WebPage`,
 * `BreadcrumbList` and what blocks add). Renderers print these values as attributes and
 * the JSON-LD as a pre-encoded string (`<` escaped), never as markup.
 */
final class Seo
{
    public const SCHEMA_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'ItemPage'];

    /**
     * @param  array<int, array{type: string, id: string, data: array<string, mixed>}>  $blocks
     * @param  array<int, array{locale: string, url: string}>  $alternates
     * @param  array<int, array{label: string, url: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    public static function for(Page $page, Site $site, array $blocks, array $alternates, array $breadcrumbs, bool $preview = false): array
    {
        $plugin = $site->plugin;
        $meta = is_array($page->seo) ? $page->seo : [];
        $siteName = $plugin->getSiteName();
        $url = $site->pageUrl($page);

        $template = $siteName === '' ? ':title' : $plugin->getTitleTemplate();
        $title = self::string($meta['title'] ?? null, 200) ?? trim(str_replace([':title', ':site'], [$page->title, $siteName], $template));

        $description = self::string($meta['description'] ?? null, 320);
        $canonical = Url::sanitize(self::string($meta['canonical'] ?? null, 2048), ['http', 'https']) ?? $url;
        $image = Media::url($meta['image'] ?? null, $plugin->getDisk()) ?? $plugin->getDefaultOgImage();
        $noindex = $preview || (bool) ($meta['noindex'] ?? false);
        $localeSchema = $site->getLocaleSchema();

        $context = new BlockContext($plugin, $page, $site, preview: $preview);
        $schemaType = in_array($meta['schema_type'] ?? null, self::SCHEMA_TYPES, true) ? $meta['schema_type'] : 'WebPage';

        $graph = [
            array_filter([
                '@type' => $schemaType,
                '@id' => $url.'#webpage',
                'url' => $url,
                'name' => $page->title,
                'description' => $description,
                'inLanguage' => $localeSchema['tag'],
                'isPartOf' => $siteName === '' ? null : ['@type' => 'WebSite', 'name' => $siteName, 'url' => $site->urlFor('')],
                'primaryImageOfPage' => $image === null ? null : ['@type' => 'ImageObject', 'url' => $image],
                'datePublished' => $page->published_at?->toAtomString(),
                'dateModified' => $page->updated_at?->toAtomString(),
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($breadcrumbs !== []) {
            $graph[] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_map(static fn (array $crumb, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['label'],
                    'item' => $crumb['url'],
                ], $breadcrumbs, array_keys($breadcrumbs)),
            ];
        }

        foreach (BlockRenderer::jsonLd($blocks, $context) as $node) {
            $graph[] = $node;
        }

        $seo = [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $noindex ? 'noindex, nofollow' : 'index, follow',
            'openGraph' => array_filter([
                'title' => self::string($meta['og_title'] ?? null, 200) ?? $title,
                'description' => $description,
                'url' => $canonical,
                'type' => 'website',
                'siteName' => $siteName === '' ? null : $siteName,
                'locale' => str_replace('-', '_', $localeSchema['tag']),
                'image' => $image,
            ], static fn (mixed $value): bool => $value !== null),
            'twitter' => array_filter([
                'card' => $image === null ? 'summary' : 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => $image,
            ], static fn (mixed $value): bool => $value !== null),
            'alternates' => array_map(static fn (array $alternate): array => ['hreflang' => $alternate['locale'], 'href' => $alternate['url']], $alternates),
            'jsonLd' => ['@context' => 'https://schema.org', '@graph' => $graph],
        ];

        $seo = $plugin->modifySeo($seo, $page);
        $seo['jsonLd'] = self::encodeJsonLd($seo['jsonLd'] ?? null);

        return $seo;
    }

    /**
     * JSON safe to place inside `<script type="application/ld+json">` (`<`, `>` and `&`
     * escaped, so the content can't close the script element).
     */
    public static function encodeJsonLd(mixed $data): ?string
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($data) || $data === []) {
            return null;
        }

        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /**
     * The head tags as HTML, for root views without server-side rendering:
     * `{{ $alfakitCmsHead ?? '' }}` in your Blade layout.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function toHtml(array $seo): HtmlString
    {
        $tags = [];
        $text = static fn (mixed $value): string => e(is_scalar($value) ? (string) $value : '');

        $tags[] = '<title>'.$text($seo['title'] ?? '').'</title>';

        foreach (['description' => $seo['description'] ?? null, 'robots' => $seo['robots'] ?? null] as $name => $content) {
            if ($content !== null) {
                $tags[] = '<meta name="'.$name.'" content="'.$text($content).'">';
            }
        }

        if (isset($seo['canonical'])) {
            $tags[] = '<link rel="canonical" href="'.$text($seo['canonical']).'">';
        }

        foreach (is_array($seo['openGraph'] ?? null) ? $seo['openGraph'] : [] as $property => $content) {
            $tags[] = '<meta property="og:'.e(Str::snake((string) $property)).'" content="'.$text($content).'">';
        }

        foreach (is_array($seo['twitter'] ?? null) ? $seo['twitter'] : [] as $name => $content) {
            $tags[] = '<meta name="twitter:'.e((string) $name).'" content="'.$text($content).'">';
        }

        foreach (is_array($seo['alternates'] ?? null) ? $seo['alternates'] : [] as $alternate) {
            if (is_array($alternate)) {
                $tags[] = '<link rel="alternate" hreflang="'.$text($alternate['hreflang'] ?? '').'" href="'.$text($alternate['href'] ?? '').'">';
            }
        }

        if (is_string($seo['jsonLd'] ?? null)) {
            // Encoded by encodeJsonLd(): no `<` can appear in it.
            $tags[] = '<script type="application/ld+json">'.$seo['jsonLd'].'</script>';
        }

        return new HtmlString(implode("\n", $tags));
    }

    private static function string(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
