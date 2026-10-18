<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\Models\Page;

/**
 * `sitemap.xml` of a site: every live page that isn't `noindex`, in every locale, with
 * `hreflang` alternates between translations.
 */
final class Sitemap
{
    public static function toXml(Site $site): string
    {
        $pages = $site->pages(allLocales: true)
            ->published()
            ->when($site->plugin->isMultilingual(), static fn ($query) => $query->whereIn('locale', array_keys($site->plugin->getLocales())))
            ->orderBy('locale')
            ->orderBy('path')
            ->get()
            ->reject(static fn (Page $page): bool => (bool) ($page->seo['noindex'] ?? false));

        $groups = $pages->groupBy(static fn (Page $page): int => (int) ($page->translation_of_id ?? $page->getKey()));
        $multilingual = $site->plugin->isMultilingual();

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'.($multilingual ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '').'>';

        foreach ($pages as $page) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.self::escape($site->pageUrl($page)).'</loc>';

            if ($page->updated_at !== null) {
                $xml[] = '    <lastmod>'.$page->updated_at->toAtomString().'</lastmod>';
            }

            if ($multilingual) {
                $translations = $groups->get((int) ($page->translation_of_id ?? $page->getKey())) ?? collect();

                foreach ($translations->unique('locale') as $translation) {
                    if ($translations->count() > 1 && $translation->locale !== null) {
                        $xml[] = '    <xhtml:link rel="alternate" hreflang="'.self::escape($translation->locale).'" href="'.self::escape($site->pageUrl($translation)).'"/>';
                    }
                }
            }

            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml)."\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
