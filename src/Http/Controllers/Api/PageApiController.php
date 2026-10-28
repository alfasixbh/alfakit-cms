<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers\Api;

use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\PagePresenter;
use AlfaSix\AlfakitCms\Support\Site;
use AlfaSix\AlfakitCms\Support\SiteDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only JSON API of live pages, for headless frontends:
 *
 * - `GET /api/cms/pages?locale=en&per_page=50` lists them (no content);
 * - `GET /api/cms/page?path=about/team&locale=en` returns one with render-ready blocks,
 *   SEO, breadcrumbs and translations (an empty path is the home page), plus the site design
 *   under `site.design`.
 *
 * Drafts, scheduled pages and other tenants' pages are never returned.
 */
class PageApiController
{
    public function index(Request $request): JsonResponse
    {
        $site = static::site($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', '50')));

        $pages = $site->pages()->published()->orderBy('path')->paginate($perPage);

        return response()->json([
            'data' => $pages->getCollection()->map(static fn (Page $page): array => [
                'id' => $page->getKey(),
                'title' => $page->title,
                'path' => $page->path,
                'url' => $site->pageUrl($page),
                'locale' => $page->locale,
                'isHome' => $page->is_home,
                'updatedAt' => $page->updated_at?->toAtomString(),
            ])->values(),
            'meta' => [
                'currentPage' => $pages->currentPage(),
                'lastPage' => $pages->lastPage(),
                'perPage' => $pages->perPage(),
                'total' => $pages->total(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $site = static::site($request);
        $path = $request->query('path', '');

        $page = $site->findPublishedPage(is_string($path) ? $path : '');

        if ($page === null) {
            return response()->json(['message' => __('alfakit-cms::cms.api.not_found')], 404);
        }

        return response()->json([
            'data' => PagePresenter::present($page, $site),
            'site' => static::siteData($site),
        ]);
    }

    /**
     * The site around the page: its **Site design** (colors and custom CSS, fonts, logo,
     * favicon), as `cms.site.design` on the Inertia route. Never the `customCode()` head and
     * body snippets: those are only printed by the root view of the public site.
     *
     * @return array{design: array{css: string|null, fonts: string|null, logo: array{url: string, alt: string}|null, favicon: string|null}|null}
     */
    public static function siteData(Site $site): array
    {
        return ['design' => $site->plugin->hasSiteDesign() ? SiteDesign::forSite($site) : null];
    }

    /**
     * The API's site in the requested locale (`?locale=`, one of the plugin's locales).
     */
    public static function site(Request $request): Site
    {
        $site = Site::forRequest($request);

        if ($site === null || ! $site->plugin->hasApi()) {
            abort(404);
        }

        $locale = $request->query('locale');

        if (is_string($locale) && array_key_exists($locale, $site->plugin->getLocales())) {
            $site = $site->withLocale($locale);
            app()->setLocale($locale);
        }

        return $site;
    }
}
