<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms;

use AlfaSix\AlfakitCms\Http\Controllers\Api\MenuApiController;
use AlfaSix\AlfakitCms\Http\Controllers\Api\PageApiController;
use AlfaSix\AlfakitCms\Http\Controllers\PageController;
use AlfaSix\AlfakitCms\Http\Controllers\PreviewController;
use AlfaSix\AlfakitCms\Http\Controllers\RobotsController;
use AlfaSix\AlfakitCms\Http\Controllers\SitemapController;
use AlfaSix\AlfakitCms\Http\Middleware\HandleRedirects;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Loads the `alfakit-cms::` translations and, once panels are built, registers the
 * public routes of the first panel whose `CmsPlugin` turns them on: the signed preview
 * route (always, when a panel has the plugin), the JSON API, `sitemap.xml`, `robots.txt`
 * and the catch-all page route (a fallback route, so your own routes always win).
 */
class AlfakitCmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'alfakit-cms');
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'alfakit-cms');

        $this->app->make(Router::class)->aliasMiddleware('alfakit-cms.redirects', HandleRedirects::class);

        $this->app->booted(function (): void {
            $this->registerRedirectMiddleware();

            if (! $this->app->routesAreCached()) {
                static::registerRoutes($this->app->make(Router::class));
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'alfakit-cms-migrations');

            $this->publishes([
                dirname(__DIR__).'/resources/lang' => $this->app->langPath('vendor/alfakit-cms'),
            ], 'alfakit-cms-translations');
        }
    }

    public static function registerRoutes(Router $router): void
    {
        $panel = CmsPlugin::panelFor(static fn (CmsPlugin $plugin): bool => $plugin->hasPublicRoutes())
            ?? CmsPlugin::panelFor(static fn (): bool => true);

        if ($panel === null) {
            return;
        }

        $plugin = $panel->getPlugin(CmsPlugin::ID);

        if (! $plugin instanceof CmsPlugin) {
            return;
        }

        $group = $router->middleware($plugin->getMiddleware())->prefix($plugin->getRoutePrefix());

        if ($plugin->getDomain() !== null) {
            $group->domain($plugin->getDomain());
        }

        $group->group(static function (Router $router) use ($plugin): void {
            if (! $router->has(PreviewLinks::ROUTE)) {
                $router->get('_cms/preview/{page}', PreviewController::class)
                    ->whereNumber('page')
                    ->middleware(ValidateSignature::class)
                    ->name(PreviewLinks::ROUTE);
            }

            if (! $plugin->hasPublicRoutes()) {
                return;
            }

            $router->get('sitemap.xml', SitemapController::class)->name('alfakit-cms.sitemap');
            $router->get('robots.txt', RobotsController::class)->name('alfakit-cms.robots');
            // Optional, so the bare prefix (`/site` with `publicRoutes(prefix: 'site')`) serves the home page.
            $router->get('{path?}', PageController::class)->where('path', '.*')->fallback()->name('alfakit-cms.page');
        });

        if ($plugin->hasApi()) {
            $router->middleware($plugin->getApiMiddleware())->prefix($plugin->getApiPrefix())->group(static function (Router $router): void {
                $router->get('pages', [PageApiController::class, 'index'])->name('alfakit-cms.api.pages');
                $router->get('page', [PageApiController::class, 'show'])->name('alfakit-cms.api.page');
                $router->get('menus/{handle}', MenuApiController::class)->where('handle', '[A-Za-z0-9_-]+')->name('alfakit-cms.api.menu');
            });
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    protected function registerRedirectMiddleware(): void
    {
        $panel = CmsPlugin::panelFor(static fn (CmsPlugin $plugin): bool => $plugin->registersRedirectMiddleware() && $plugin->hasPublicRoutes());

        if ($panel === null) {
            return;
        }

        // Paths no route matches never reach route middleware: the router's 404 is
        // turned into the redirect instead. Costs nothing on requests that match a route.
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->renderable(HandleRedirects::forUnmatchedRoute(...));
            }
        });

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'appendMiddlewareToGroup') && method_exists($kernel, 'getMiddlewareGroups') && isset($kernel->getMiddlewareGroups()['web'])) {
            $kernel->appendMiddlewareToGroup('web', HandleRedirects::class);

            return;
        }

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', HandleRedirects::class);
    }
}
