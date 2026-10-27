<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Responses;

use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Menus;
use AlfaSix\AlfakitCms\Support\PagePresenter;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Support\Seo;
use AlfaSix\AlfakitCms\Support\Site;
use AlfaSix\AlfakitCms\Support\SiteDesign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The Inertia response of a public page (or a preview): the `cms` prop with the page,
 * the shared menus, the site and the renderers' strings, plus `$alfakitCmsHead` view data
 * (the head tags as HTML) for root views without server-side rendering.
 */
final class CmsPageResponse
{
    public static function make(Page $page, Site $site, Request $request, bool $preview = false): Response
    {
        $plugin = $site->plugin;
        $payload = self::payload($page, $site, $request, $preview);

        if ($plugin->getRootView() !== null) {
            Inertia::setRootView($plugin->getRootView());
        }

        $seo = is_array($payload['page']['seo'] ?? null) ? $payload['page']['seo'] : [];

        $design = is_array($payload['site']['design'] ?? null) ? $payload['site']['design'] : null;
        // `customCode()` snippets, printed by the root view; never on previews.
        $code = $preview ? ['head' => new HtmlString(''), 'body' => new HtmlString('')] : SiteDesign::code($site);

        $response = Inertia::render($plugin->getInertiaComponent(), ['cms' => $payload])
            ->withViewData([
                'alfakitCmsHead' => new HtmlString(Seo::toHtml($seo)->toHtml().SiteDesign::toHtml($design)),
                'alfakitCmsCode' => $code['head'],
                'alfakitCmsBodyCode' => $code['body'],
            ])
            ->toResponse($request);

        if ($preview) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Page $page, Site $site, Request $request, bool $preview = false): array
    {
        $plugin = $site->plugin;
        $locale = $site->getLocaleSchema();
        $presented = PagePresenter::present($page, $site, $preview);

        $locales = [];

        foreach ($plugin->getLocales() as $code => $label) {
            $alternate = collect($presented['alternates'])->firstWhere('locale', $code);

            $locales[] = [
                'code' => $code,
                'label' => $label,
                'url' => is_array($alternate) ? $alternate['url'] : $site->urlFor('', $code),
                'current' => $code === $page->locale,
            ];
        }

        return [
            'page' => $presented,
            'menus' => (object) Menus::resolveMany($plugin->getSharedMenus(), $site, $preview ? $site->pathFor($page->path, $page->locale) : '/'.ltrim($request->path(), '/')),
            'site' => [
                'name' => $plugin->getSiteName(),
                'url' => $site->urlFor(''),
                'locale' => $locale['code'],
                'tag' => $locale['tag'],
                'direction' => $locale['direction'],
                'locales' => $locales,
                // Colours, fonts, logo and favicon from **Site design** (`siteDesign()`).
                'design' => $plugin->hasSiteDesign() ? SiteDesign::forSite($site) : null,
            ],
            'preview' => $preview,
            'admin' => $preview ? null : self::adminBar($page, $site),
            // "Powered by AlfaKit · Alfa Six" in the footer (`CmsPlugin::poweredBy()`), off by default.
            'poweredBy' => $plugin->getPoweredByHtml(),
            'translations' => ['cms' => self::translations()],
        ];
    }

    /**
     * Links of the floating admin bar (`CmsPlugin::adminBar()`), for a signed-in panel
     * user who may edit the page; null for everyone else.
     *
     * @return array{editUrl: string, draftUrl: string|null, dashboardUrl: string, hasChanges: bool}|null
     */
    public static function adminBar(Page $page, Site $site): ?array
    {
        if (! $site->plugin->hasAdminBar()) {
            return null;
        }

        try {
            $user = $site->panel->auth()->user();
        } catch (Throwable) {
            return null;
        }

        if (! $user instanceof Model || ! CmsAuthorization::allows('update', $page, $user)) {
            return null;
        }

        $parameters = ['record' => $page];

        if ($site->panel->hasTenancy() && $site->tenant !== null) {
            $parameters['tenant'] = $site->tenant;
        }

        // URLs of the CMS panel, even though no panel serves this request.
        $current = alfakit()->getCurrentPanel();
        alfakit()->setCurrentPanel($site->panel);

        try {
            $editUrl = $site->plugin->getPageResource()::getUrl('edit', $parameters, panel: $site->panel);
            $dashboardUrl = $site->panel->getUrl($site->panel->hasTenancy() ? $site->tenant : null);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            alfakit()->setCurrentPanel($current);
        }

        return [
            'editUrl' => $editUrl,
            'draftUrl' => $page->hasPendingChanges() && Route::has(PreviewLinks::ROUTE) ? PreviewLinks::url($page) : null,
            'dashboardUrl' => $dashboardUrl,
            'hasChanges' => $page->hasPendingChanges(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function translations(): array
    {
        $lines = trans('alfakit-cms::cms.ui');

        return is_array($lines) ? $lines : [];
    }
}
