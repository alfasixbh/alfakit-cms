<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers;

use AlfaSix\AlfakitCms\Http\Middleware\HandleRedirects;
use AlfaSix\AlfakitCms\Http\Responses\CmsPageResponse;
use AlfaSix\AlfakitCms\Support\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public catch-all route: finds the live page at the request path (in the locale of
 * the first path segment on multilingual sites) and renders it, else applies a redirect
 * or answers 404.
 */
class PageController
{
    public function __invoke(Request $request, ?string $path = null): Response
    {
        $site = Site::forRequest($request);

        if ($site === null || ! $site->plugin->hasPublicRoutes()) {
            abort(404);
        }

        [$locale, $pagePath] = self::splitLocale($site, (string) $path);

        if ($locale === false) {
            // `prefixDefaultLocale`: the bare root goes to the default locale's home page.
            if ($pagePath === '' && ($default = $site->plugin->getDefaultLocale()) !== null) {
                return redirect($site->urlFor('', $default), 302);
            }

            return $this->notFound($request, $site);
        }

        $site = $site->withLocale($locale);
        $request->attributes->set(Site::REQUEST_ATTRIBUTE, $site);

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        $page = $site->findPublishedPage($pagePath);

        if ($page === null) {
            return $this->notFound($request, $site);
        }

        return CmsPageResponse::make($page, $site, $request);
    }

    /**
     * The locale and the page path of a request path. The locale is `false` when the site
     * prefixes every locale and the path has none.
     *
     * @return array{0: string|null|false, 1: string}
     */
    public static function splitLocale(Site $site, string $path): array
    {
        $path = trim($path, '/');
        $plugin = $site->plugin;

        if (! $plugin->isMultilingual()) {
            return [null, $path];
        }

        $segments = $path === '' ? [] : explode('/', $path);
        $first = $segments[0] ?? null;

        if ($first !== null && array_key_exists($first, $plugin->getLocales()) && ($first !== $plugin->getDefaultLocale() || $plugin->prefixesDefaultLocale())) {
            return [$first, implode('/', array_slice($segments, 1))];
        }

        if ($plugin->prefixesDefaultLocale()) {
            return [false, $path];
        }

        return [$plugin->getDefaultLocale(), $path];
    }

    protected function notFound(Request $request, Site $site): Response
    {
        // The middleware handles redirects when it is on the `web` group; this covers the
        // public routes when it isn't.
        if ($site->plugin->hasRedirects() && ($redirect = HandleRedirects::redirectFor($request, $site)) !== null) {
            return $redirect;
        }

        abort(404);
    }
}
