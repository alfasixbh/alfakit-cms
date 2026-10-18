<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The editor's SEO helper: a search-result style preview of a page and plain-language
 * tips (missing description, title too long, images without alternative text). Works on
 * the form's current state, so it follows what the editor types.
 */
final class SeoAdvisor
{
    public const TITLE_LIMIT = 60;

    public const DESCRIPTION_MIN = 50;

    public const DESCRIPTION_LIMIT = 160;

    /**
     * @param  array<string, mixed>  $seo
     */
    public static function title(?string $pageTitle, array $seo): string
    {
        $meta = is_string($seo['title'] ?? null) ? trim($seo['title']) : '';

        if ($meta !== '') {
            return $meta;
        }

        $plugin = CmsPlugin::get();
        $site = $plugin->getSiteName();
        $template = $site === '' ? ':title' : $plugin->getTitleTemplate();

        return trim(str_replace([':title', ':site'], [(string) $pageTitle, $site], $template));
    }

    /**
     * A search result: title, address and description, escaped.
     *
     * @param  array<string, mixed>  $seo
     */
    public static function snippet(?string $pageTitle, array $seo, string $url): HtmlString
    {
        $title = self::title($pageTitle, $seo);
        $description = is_string($seo['description'] ?? null) ? trim($seo['description']) : '';

        return new HtmlString(
            '<div class="rounded-lg border bg-background p-4" data-slot="cms-seo-snippet">'
            .'<p class="text-muted-foreground text-xs">'.__('alfakit-cms::cms.seo.snippet_label').'</p>'
            .'<p class="mt-2 truncate text-sm text-muted-foreground" dir="ltr">'.e($url).'</p>'
            .'<p class="truncate text-lg font-medium text-primary">'.e(Str::limit($title, self::TITLE_LIMIT + 10)).'</p>'
            .'<p class="text-sm text-muted-foreground">'.e($description === '' ? __('alfakit-cms::cms.seo.no_description_preview') : Str::limit($description, self::DESCRIPTION_LIMIT)).'</p>'
            .'</div>',
        );
    }

    /**
     * Plain-language tips for the page.
     *
     * @param  array<string, mixed>  $seo
     * @return array<int, string>
     */
    public static function warnings(?string $pageTitle, array $seo, mixed $content): array
    {
        $warnings = [];
        $title = self::title($pageTitle, $seo);
        $description = is_string($seo['description'] ?? null) ? trim($seo['description']) : '';

        if ($description === '') {
            $warnings[] = __('alfakit-cms::cms.seo.missing_description');
        } elseif (mb_strlen($description) < self::DESCRIPTION_MIN) {
            $warnings[] = __('alfakit-cms::cms.seo.short_description');
        } elseif (mb_strlen($description) > self::DESCRIPTION_LIMIT) {
            $warnings[] = __('alfakit-cms::cms.seo.long_description', ['count' => self::DESCRIPTION_LIMIT]);
        }

        if (mb_strlen($title) > self::TITLE_LIMIT) {
            $warnings[] = __('alfakit-cms::cms.seo.long_title', ['count' => self::TITLE_LIMIT]);
        }

        if (($missing = self::imagesWithoutAlt($content)) > 0) {
            $warnings[] = trans_choice('alfakit-cms::cms.seo.missing_alt', $missing, ['count' => $missing]);
        }

        if ((bool) ($seo['noindex'] ?? false)) {
            $warnings[] = __('alfakit-cms::cms.seo.hidden');
        }

        return $warnings;
    }

    /**
     * Images in block content (any `image` value with an empty `image_alt` next to it).
     */
    public static function imagesWithoutAlt(mixed $content): int
    {
        if (! is_array($content)) {
            return 0;
        }

        $count = 0;

        if (array_key_exists('image_alt', $content) && self::hasImage($content['image'] ?? null, $content['image_media'] ?? null)) {
            $alt = $content['image_alt'];
            $count += is_string($alt) && trim($alt) !== '' ? 0 : 1;
        }

        foreach ($content as $value) {
            if (is_array($value)) {
                $count += self::imagesWithoutAlt($value);
            }
        }

        return $count;
    }

    private static function hasImage(mixed $image, mixed $media): bool
    {
        if (is_array($image)) {
            $image = array_values(array_filter($image))[0] ?? null;
        }

        return (is_string($image) && $image !== '') || is_numeric($media);
    }

    /**
     * The public URL a page will have, for the snippet.
     */
    public static function url(?Page $page, mixed $slug, mixed $parentId, mixed $locale, bool $isHome): string
    {
        $draft = new Page([
            'slug' => is_string($slug) ? $slug : null,
            'parent_id' => is_numeric($parentId) ? (int) $parentId : null,
            'locale' => is_string($locale) ? $locale : null,
            'is_home' => $isHome,
        ]);
        $draft->slug = Page::normalizeSlug($draft->slug);

        if ($page?->tenant_id !== null) {
            $draft->tenant_id = $page->tenant_id;
        }

        $site = Site::forPage($draft);

        return $site->urlFor($draft->buildPath(), $draft->locale);
    }
}
