<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Middleware;

use AlfaSix\AlfakitCms\Support\Redirects;
use AlfaSix\AlfakitCms\Support\Site;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Applies the CMS redirects to `GET` and `HEAD` requests of the routes it runs on (the
 * `web` group by default). Paths that match no route at all are redirected by
 * `forUnmatchedRoute()`, which the service provider registers on the exception handler.
 * The query string is kept unless the target has its own; Inertia visits get a full
 * page visit to the target.
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = Site::forRequest($request);

        if ($site !== null && $site->plugin->hasRedirects() && ($redirect = static::redirectFor($request, $site)) !== null) {
            return $redirect;
        }

        return $next($request);
    }

    /**
     * The redirect for a request that matched no route (a 404 thrown by the router before
     * any route middleware ran), such as an old path outside a prefixed public site.
     * 404s of matched routes are left alone: their middleware already checked.
     */
    public static function forUnmatchedRoute(NotFoundHttpException $exception, Request $request): ?Response
    {
        if ($request->route() !== null) {
            return null;
        }

        $site = Site::forRequest($request);

        return $site !== null && $site->plugin->hasRedirects() ? static::redirectFor($request, $site) : null;
    }

    public static function redirectFor(Request $request, Site $site): ?Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = $request->getPathInfo();

        // Internal panel endpoints are never redirected.
        if (str_contains($path, '/_alfakit/')) {
            return null;
        }

        $match = Redirects::match($path, $site->tenant);

        if ($match === null) {
            return null;
        }

        $target = $match['to'];

        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            if (mb_strtolower(Redirects::normalizePath($target)) === mb_strtolower(Redirects::normalizePath($path))) {
                return null;
            }

            $target = url($target);
        }

        $query = $request->getQueryString();

        if ($query !== null && $query !== '' && ! str_contains($target, '?')) {
            $target .= '?'.$query;
        }

        Redirects::recordHit($match['id']);

        if ($request->header('X-Inertia') !== null) {
            return Inertia::location($target);
        }

        return redirect()->away($target, $match['status']);
    }
}
