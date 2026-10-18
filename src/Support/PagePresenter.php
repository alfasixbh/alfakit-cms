<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\Models\Page;

/**
 * The JSON of a public page, shared by the Inertia route, previews and the JSON API:
 * render-ready blocks, SEO, breadcrumbs and the page's translations.
 */
final class PagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Page $page, Site $site, bool $preview = false): array
    {
        $context = new BlockContext($site->plugin, $page, $site, preview: $preview);
        $blocks = BlockRenderer::render($page->content, $context);
        $breadcrumbs = self::breadcrumbs($page, $site);
        $alternates = self::alternates($page, $site);

        return [
            'id' => $page->getKey(),
            'title' => $page->title,
            'path' => $page->path,
            'url' => $site->pageUrl($page),
            'locale' => $page->locale,
            'isHome' => $page->is_home,
            'layout' => $page->layout,
            'publishedAt' => $page->published_at?->toAtomString(),
            'updatedAt' => $page->updated_at?->toAtomString(),
            'blocks' => $blocks,
            'breadcrumbs' => $breadcrumbs,
            'alternates' => $alternates,
            'seo' => Seo::for($page, $site, $blocks, $alternates, $breadcrumbs, $preview),
        ];
    }

    /**
     * The live ancestors and the page itself (empty for the home page and root pages
     * without live ancestors).
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function breadcrumbs(Page $page, Site $site): array
    {
        $ancestors = array_values(array_filter($page->getAncestors(), static fn (Page $ancestor): bool => $ancestor->isLive()));

        if ($ancestors === [] || $page->is_home) {
            return [];
        }

        $crumbs = array_map(static fn (Page $ancestor): array => ['label' => $ancestor->title, 'url' => $site->pageUrl($ancestor)], $ancestors);
        $crumbs[] = ['label' => $page->title, 'url' => $site->pageUrl($page)];

        return $crumbs;
    }

    /**
     * The live translations of a page (itself included), for `hreflang` and language
     * switchers. Empty on single-language sites.
     *
     * @return array<int, array{locale: string, url: string, current: bool}>
     */
    public static function alternates(Page $page, Site $site): array
    {
        if (! $site->plugin->isMultilingual()) {
            return [];
        }

        $source = $page->getTranslationSource();

        $group = Page::query()
            ->forTenant($site->tenant)
            ->published()
            ->where(static fn ($query) => $query->whereKey($source->getKey())->orWhere('translation_of_id', $source->getKey()))
            ->whereNotNull('locale')
            ->get();

        if (! $group->contains(static fn (Page $member): bool => $member->is($page))) {
            $group->push($page);
        }

        $locales = array_keys($site->plugin->getLocales());

        return $group
            ->filter(static fn (Page $member): bool => $member->locale !== null && in_array($member->locale, $locales, true))
            ->unique('locale')
            ->sortBy(static fn (Page $member): int => (int) array_search($member->locale, $locales, true))
            ->map(static fn (Page $member): array => ['locale' => (string) $member->locale, 'url' => $site->pageUrl($member), 'current' => $member->is($page)])
            ->values()
            ->all();
    }
}
