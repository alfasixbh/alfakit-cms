<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The public site a request (or a page) belongs to: the panel and plugin serving the
 * CMS, the tenant and the content locale. Every public query goes through `pages()`, so
 * one tenant's site never shows another tenant's pages.
 */
final class Site
{
    public const REQUEST_ATTRIBUTE = 'alfakit-cms.site';

    public function __construct(
        public readonly Panel $panel,
        public readonly CmsPlugin $plugin,
        public readonly ?Model $tenant,
        public readonly ?string $locale,
    ) {}

    /**
     * The site of a public request: the first panel serving public CMS routes, the
     * tenant from `resolveTenantUsing()`, the default locale.
     */
    public static function forRequest(Request $request, ?string $locale = null): ?self
    {
        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($existing instanceof self && $locale === null) {
            return $existing;
        }

        $panel = CmsPlugin::panelFor(static fn (CmsPlugin $plugin): bool => $plugin->hasPublicRoutes() || $plugin->hasApi())
            ?? CmsPlugin::panelFor(static fn (): bool => true);

        if ($panel === null) {
            return null;
        }

        $plugin = $panel->getPlugin(CmsPlugin::ID);

        if (! $plugin instanceof CmsPlugin) {
            return null;
        }

        $tenant = $existing instanceof self ? $existing->tenant : ($panel->hasTenancy() ? $plugin->resolveTenant($request) : null);

        $site = new self($panel, $plugin, $tenant, $locale ?? $plugin->getDefaultLocale());

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $site);

        return $site;
    }

    /**
     * The site a page is published on (its tenant and locale), for links built in the panel.
     */
    public static function forPage(Page $page): self
    {
        $panel = CmsPlugin::panelFor(static fn (CmsPlugin $plugin): bool => $plugin->hasPublicRoutes())
            ?? alfakit()->getCurrentPanel()
            ?? CmsPlugin::panelFor(static fn (): bool => true)
            ?? alfakit()->getCurrentOrDefaultPanel();

        $plugin = $panel->hasPlugin(CmsPlugin::ID) ? $panel->getPlugin(CmsPlugin::ID) : null;
        $plugin = $plugin instanceof CmsPlugin ? $plugin : CmsPlugin::get();

        return new self($panel, $plugin, $page->tenant_id === null ? null : $page->tenant, $page->locale);
    }

    public function withLocale(?string $locale): self
    {
        return new self($this->panel, $this->plugin, $this->tenant, $locale);
    }

    /**
     * Pages of this site's tenant (and locale, when the site is multilingual).
     *
     * @return Builder<Page>
     */
    public function pages(bool $allLocales = false): Builder
    {
        $query = Page::query()->forTenant($this->tenant);

        if (! $allLocales && $this->plugin->isMultilingual()) {
            $query->forLocale($this->locale ?? $this->plugin->getDefaultLocale());
        }

        return $query;
    }

    /**
     * The live page at a path (no slashes at the ends; '' is the home page).
     */
    public function findPublishedPage(string $path): ?Page
    {
        $path = trim($path, '/');
        $query = $this->pages()->published();

        $page = $path === ''
            ? $query->where(static fn (Builder $query) => $query->where('is_home', true)->orWhere('path', ''))->orderByDesc('is_home')->first()
            : $query->where('path', $path)->first();

        return $page instanceof Page ? $page : null;
    }

    /**
     * The URL path of a page path in a locale: the route prefix, the locale prefix and the
     * page path (`/de/about/team`).
     */
    public function pathFor(string $path, ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $segments = [];

        if ($this->plugin->getRoutePrefix() !== '') {
            $segments[] = $this->plugin->getRoutePrefix();
        }

        if ($locale !== null && $this->plugin->isMultilingual() && ($locale !== $this->plugin->getDefaultLocale() || $this->plugin->prefixesDefaultLocale())) {
            $segments[] = $locale;
        }

        $path = trim($path, '/');

        if ($path !== '') {
            $segments[] = $path;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * A path under the route prefix, without a locale (`/sitemap.xml`, `/_cms/…`).
     */
    public function rootPath(string $path = ''): string
    {
        $segments = array_values(array_filter([$this->plugin->getRoutePrefix(), trim($path, '/')], static fn (string $segment): bool => $segment !== ''));

        return '/'.implode('/', $segments).(str_ends_with($path, '/') && $segments !== [] ? '/' : '');
    }

    public function urlFor(string $path, ?string $locale = null): string
    {
        $path = $this->pathFor($path, $locale);
        $domain = $this->plugin->getDomain();

        if ($domain !== null && $domain !== '' && ! str_contains($domain, '{')) {
            return request()->getScheme().'://'.$domain.($path === '/' ? '' : $path);
        }

        return url($path);
    }

    public function pageUrl(Page $page): string
    {
        return $this->urlFor($page->path, $page->locale);
    }

    /**
     * The text direction and BCP 47 tag of the site's locale.
     *
     * @return array{code: string, tag: string, direction: string}
     */
    public function getLocaleSchema(): array
    {
        $code = $this->locale ?? app()->getLocale();
        $locale = $this->panel->getLocale($code) ?? Locale::make($code);

        return ['code' => $code, 'tag' => $locale->getTag(), 'direction' => $locale->getDirection()->value];
    }
}
