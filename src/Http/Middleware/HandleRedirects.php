<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Middleware;

use AlfaSix\AlfakitCms\Support\Redirects;
use AlfaSix\AlfakitCms\Support\Site;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the CMS redirects to `GET` and `HEAD` requests before routing on to the app.
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
