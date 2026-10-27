<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\AlfakitCms\Blocks\CmsBlock;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Resources\MenuResource;
use AlfaSix\AlfakitCms\Resources\PageResource;
use AlfaSix\AlfakitCms\Resources\RedirectResource;
use AlfaSix\AlfakitCms\Support\BlockRegistry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * A block-based CMS on a panel: pages (drafts, scheduling, revisions, signed previews,
 * SEO), redirects and menus in the panel, and an opt-in public site (an Inertia
 * catch-all route, `sitemap.xml`, `robots.txt`) plus a read-only JSON API.
 *
 * ```php
 * $panel->plugin(
 *     CmsPlugin::make()
 *         ->blocks([PricingBlock::class])
 *         ->locales(['en', 'ar'])
 *         ->siteName('Acme')
 *         ->publicRoutes()
 *         ->api(),
 * );
 * ```
 *
 * The public routes, sitemap and API are served by the first panel that has the plugin
 * with `publicRoutes()` / `api()` turned on.
 */
class CmsPlugin implements Plugin
{
    use EvaluatesClosures;
    use HasPoweredByCredit;

    public const ID = 'cms';

    /** @var class-string<PageResource> */
    protected string $pageResource = PageResource::class;

    /** @var class-string<RedirectResource> */
    protected string $redirectResource = RedirectResource::class;

    /** @var class-string<MenuResource> */
    protected string $menuResource = MenuResource::class;

    protected bool $hasRedirects = true;

    protected bool $hasMenus = true;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    /** @var array<int, class-string<CmsBlock>|CmsBlock> */
    protected array $blocks = [];

    /** @var array<int, string> */
    protected array $withoutBlocks = [];

    /** @var array<int, string>|null */
    protected ?array $onlyBlocks = null;

    protected ?BlockRegistry $blockRegistry = null;

    protected string|Closure|null $disk = null;

    protected string $directory = 'cms';

    /** @var array<int, string> */
    protected array $embedHosts = [];

    protected ?Closure $formOptionsUsing = null;

    protected ?Closure $formUsing = null;

    protected bool $hasPublicRoutes = false;

    protected string $routePrefix = '';

    /** @var array<int, string> */
    protected array $middleware = ['web'];

    protected ?string $domain = null;

    protected string $inertiaComponent = 'Alfakit/CmsPage';

    protected ?string $rootView = null;

    protected string|Closure|null $siteName = null;

    protected string|Closure $titleTemplate = ':title | :site';

    /** @var array<int, string> */
    protected array $sharedMenus = ['main', 'footer'];

    protected ?Closure $resolveTenantUsing = null;

    /** @var array<string, string> */
    protected array $locales = [];

    protected ?string $defaultLocale = null;

    protected bool $prefixesDefaultLocale = false;

    protected bool $hasSitemap = true;

    protected bool $hasRobots = true;

    protected ?Closure $robotsUsing = null;

    protected bool $hasApi = false;

    protected string $apiPrefix = 'api/cms';

    /** @var array<int, string> */
    protected array $apiMiddleware = ['api'];

    protected int $previewLinkLifetime = 1440;

    protected bool $createsRedirectsOnPathChange = true;

    protected bool $registersRedirectMiddleware = true;

    protected int $revisionsLimit = 50;

    protected bool $keepsChangesAsDraft = true;

    protected bool $hasLivePreview = true;

    protected int $autosaveDebounce = 1500;

    protected bool $hasAdminBar = false;

    protected bool $hasReview = true;

    protected ?Closure $reviewersUsing = null;

    /** @var array<string, array{label: string, description?: string|null, content: array<int, array<string, mixed>>}> */
    protected array $templates = [];

    protected bool $hasSections = true;

    protected bool $hasTemplates = true;

    protected bool $hasBlockPreviews = false;

    protected bool $usesMediaLibrary = true;

    protected ?string $panelId = null;

    protected ?Closure $authorizeUsing = null;

    protected bool $hasSiteDesign = true;

    protected bool|Closure $customCode = false;

    protected ?Closure $modifySeoUsing = null;

    protected string|Closure|null $defaultOgImage = null;

    /** @var array<string, string> */
    protected array $layouts = [];

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of the current panel, else of the first panel that has it, else a plugin
     * with the default options.
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentPanel();

        if ($panel !== null && $panel->hasPlugin(self::ID) && ($plugin = $panel->getPlugin(self::ID)) instanceof static) {
            return $plugin;
        }

        $site = static::panelFor(static fn (self $plugin): bool => true);

        if ($site !== null && ($plugin = $site->getPlugin(self::ID)) instanceof static) {
            return $plugin;
        }

        return static::make();
    }

    /**
     * The first panel whose CMS plugin passes the check.
     *
     * @param  Closure(self): bool  $check
     */
    public static function panelFor(Closure $check): ?Panel
    {
        foreach (alfakit()->getPanels() as $panel) {
            if (! $panel->hasPlugin(self::ID)) {
                continue;
            }

            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof self && $check($plugin)) {
                return $panel;
            }
        }

        return null;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $this->panelId = $panel->getId();

        $panel->resources(array_values(array_filter([
            $this->pageResource,
            $this->hasSections ? Resources\SectionResource::class : null,
            $this->hasTemplates ? Resources\TemplateResource::class : null,
            $this->hasRedirects ? $this->redirectResource : null,
            $this->hasMenus ? $this->menuResource : null,
        ])));

        if ($this->hasSiteDesign) {
            $panel->pages([Pages\ManageSiteDesign::class]);
        }

        $panel->frontendTranslations(['cms' => 'alfakit-cms::cms.ui']);
    }

    public function boot(Panel $panel): void
    {
        $this->panelId = $panel->getId();
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Resources

    /**
     * @param  class-string<PageResource>  $resource
     */
    public function pageResource(string $resource): static
    {
        $this->pageResource = static::assertSubclass($resource, PageResource::class);

        return $this;
    }

    /**
     * @return class-string<PageResource>
     */
    public function getPageResource(): string
    {
        return $this->pageResource;
    }

    /**
     * @param  class-string<RedirectResource>  $resource
     */
    public function redirectResource(string $resource): static
    {
        $this->redirectResource = static::assertSubclass($resource, RedirectResource::class);

        return $this;
    }

    /**
     * @return class-string<RedirectResource>
     */
    public function getRedirectResource(): string
    {
        return $this->redirectResource;
    }

    /**
     * @param  class-string<MenuResource>  $resource
     */
    public function menuResource(string $resource): static
    {
        $this->menuResource = static::assertSubclass($resource, MenuResource::class);

        return $this;
    }

    /**
     * @return class-string<MenuResource>
     */
    public function getMenuResource(): string
    {
        return $this->menuResource;
    }

    /**
     * The redirects manager (a resource and the redirect middleware). On by default.
     */
    public function redirects(bool $condition = true): static
    {
        $this->hasRedirects = $condition;

        return $this;
    }

    public function hasRedirects(): bool
    {
        return $this->hasRedirects;
    }

    /**
     * The menu builder. On by default.
     */
    public function menus(bool $condition = true): static
    {
        $this->hasMenus = $condition;

        return $this;
    }

    public function hasMenus(): bool
    {
        return $this->hasMenus;
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        if ($this->navigationGroup === null) {
            return __('alfakit-cms::cms.navigation.group');
        }

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    // ---------------------------------------------------------------- Blocks

    /**
     * Add blocks (subclasses of `CmsBlock`) to the page builder. A block with the name of a
     * default block replaces it.
     *
     * @param  array<int, class-string<CmsBlock>|CmsBlock>  $blocks
     */
    public function blocks(array $blocks): static
    {
        $this->blocks = [...$this->blocks, ...array_values($blocks)];
        $this->blockRegistry = null;

        return $this;
    }

    /**
     * Remove default or added blocks by name (`['testimonials', 'form']`).
     *
     * @param  array<int, string>  $names
     */
    public function withoutBlocks(array $names): static
    {
        $this->withoutBlocks = array_values($names);
        $this->blockRegistry = null;

        return $this;
    }

    /**
     * Keep only these blocks, by name, in this order.
     *
     * @param  array<int, string>|null  $names
     */
    public function onlyBlocks(?array $names): static
    {
        $this->onlyBlocks = $names === null ? null : array_values($names);
        $this->blockRegistry = null;

        return $this;
    }

    public function getBlockRegistry(): BlockRegistry
    {
        return $this->blockRegistry ??= BlockRegistry::make($this->blocks, [...$this->withoutBlocks, ...($this->hasSections ? [] : ['section'])], $this->onlyBlocks);
    }

    /**
     * Page layouts editors can choose (`['default' => 'Default', 'landing' => 'Landing']`);
     * the page's `layout` reaches the frontend so your page component can switch on it.
     *
     * @param  array<string, string>  $layouts
     */
    public function layouts(array $layouts): static
    {
        $this->layouts = $layouts;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getLayouts(): array
    {
        return $this->layouts;
    }

    // ---------------------------------------------------------------- Media and embeds

    /**
     * The filesystem disk block images are uploaded to (`public` by default).
     */
    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function getDisk(): string
    {
        $disk = $this->evaluate($this->disk);

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    public function directory(string $directory): static
    {
        $this->directory = trim($directory, '/');

        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Extra `https` hosts the embed block may show in an iframe, on top of YouTube and
     * Vimeo (`['www.google.com', 'player.example.com']`).
     *
     * @param  array<int, string>  $hosts
     */
    public function embedHosts(array $hosts): static
    {
        $this->embedHosts = array_values(array_map(static fn (string $host): string => strtolower(trim($host)), $hosts));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getEmbedHosts(): array
    {
        return $this->embedHosts;
    }

    // ---------------------------------------------------------------- Form embeds

    /**
     * The forms editors can pick in the form block: `fn (): array => ['contact' => 'Contact us']`.
     */
    public function formOptions(?Closure $callback): static
    {
        $this->formOptionsUsing = $callback;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getFormOptions(): array
    {
        $options = $this->formOptionsUsing === null ? [] : $this->evaluate($this->formOptionsUsing);

        if (! is_array($options)) {
            return [];
        }

        $clean = [];

        foreach ($options as $key => $label) {
            if (is_scalar($label)) {
                $clean[(string) $key] = (string) $label;
            }
        }

        return $clean;
    }

    /**
     * What the form block sends to the renderer for a form: `fn (string $form, Page $page): ?array`.
     * The array reaches the frontend `cms:form` component as its `form` prop; return a
     * standalone component payload, a URL or anything your component understands (JSON only).
     */
    public function formUsing(?Closure $callback): static
    {
        $this->formUsing = $callback;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveForm(string $form, ?Page $page): ?array
    {
        if ($this->formUsing === null) {
            return null;
        }

        $resolved = $this->evaluate($this->formUsing, ['form' => $form, 'page' => $page, 'record' => $page], $page === null ? [] : [Page::class => $page]);

        return is_array($resolved) ? $resolved : null;
    }

    // ---------------------------------------------------------------- Public site

    /**
     * Serve published pages at their paths through a catch-all fallback route (opt-in),
     * plus `/sitemap.xml`, `/robots.txt` and the signed preview route.
     *
     * @param  array<int, string>|null  $middleware  Defaults to `['web']`.
     */
    public function publicRoutes(bool $condition = true, ?string $prefix = null, ?array $middleware = null, ?string $domain = null): static
    {
        $this->hasPublicRoutes = $condition;

        if ($prefix !== null) {
            $this->routePrefix = trim($prefix, '/');
        }

        if ($middleware !== null) {
            $this->middleware = array_values($middleware);
        }

        if ($domain !== null) {
            $this->domain = $domain;
        }

        return $this;
    }

    public function hasPublicRoutes(): bool
    {
        return $this->hasPublicRoutes;
    }

    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    /**
     * @return array<int, string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * The Inertia page component public pages render (`Alfakit/CmsPage`, shipped by every
     * renderer).
     */
    public function inertiaComponent(string $component): static
    {
        $this->inertiaComponent = $component;

        return $this;
    }

    public function getInertiaComponent(): string
    {
        return $this->inertiaComponent;
    }

    /**
     * The Blade root view of public pages, when it isn't your Inertia middleware's.
     */
    public function rootView(?string $view): static
    {
        $this->rootView = $view;

        return $this;
    }

    public function getRootView(): ?string
    {
        return $this->rootView;
    }

    public function siteName(string|Closure|null $name): static
    {
        $this->siteName = $name;

        return $this;
    }

    public function getSiteName(): string
    {
        $name = $this->evaluate($this->siteName);

        return is_string($name) && $name !== '' ? $name : (string) config('app.name', '');
    }

    /**
     * The `<title>` pattern (`:title` and `:site`), used when a page has no meta title.
     */
    public function titleTemplate(string|Closure $template): static
    {
        $this->titleTemplate = $template;

        return $this;
    }

    public function getTitleTemplate(): string
    {
        $template = $this->evaluate($this->titleTemplate);

        return is_string($template) && str_contains($template, ':title') ? $template : ':title';
    }

    /**
     * The menus (by handle) shared with every public page as `cms.menus`.
     *
     * @param  array<int, string>  $handles
     */
    public function sharedMenus(array $handles): static
    {
        $this->sharedMenus = array_values($handles);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getSharedMenus(): array
    {
        return $this->hasMenus ? $this->sharedMenus : [];
    }

    /**
     * The tenant of a public request on tenancy panels:
     * `fn (Request $request): ?Model => Team::firstWhere('domain', $request->getHost())`.
     * Without it, tenancy panels serve no public pages.
     */
    public function resolveTenantUsing(?Closure $callback): static
    {
        $this->resolveTenantUsing = $callback;

        return $this;
    }

    public function resolveTenant(Request $request): ?Model
    {
        if ($this->resolveTenantUsing === null) {
            return null;
        }

        $tenant = $this->evaluate($this->resolveTenantUsing, ['request' => $request], [Request::class => $request]);

        return $tenant instanceof Model ? $tenant : null;
    }

    /**
     * The content languages (`['en', 'ar']` or `['en' => 'English', 'ar' => 'العربية']`).
     * Pages get a locale and a "translation of" link; other locales than the default are
     * served under `/{locale}/…`.
     *
     * @param  array<int|string, string>  $locales
     */
    public function locales(array $locales, ?string $default = null, bool $prefixDefault = false): static
    {
        $normalized = [];

        foreach ($locales as $code => $label) {
            $key = is_string($code) ? $code : $label;
            $normalized[$key] = $label;
        }

        $this->locales = $normalized;
        $this->defaultLocale = $default ?? (array_key_first($normalized) ?: null);
        $this->prefixesDefaultLocale = $prefixDefault;

        return $this;
    }

    /**
     * @return array<string, string> code => label
     */
    public function getLocales(): array
    {
        return $this->locales;
    }

    public function isMultilingual(): bool
    {
        return $this->locales !== [];
    }

    public function getDefaultLocale(): ?string
    {
        return $this->isMultilingual() ? $this->defaultLocale : null;
    }

    public function prefixesDefaultLocale(): bool
    {
        return $this->prefixesDefaultLocale;
    }

    public function sitemap(bool $condition = true): static
    {
        $this->hasSitemap = $condition;

        return $this;
    }

    public function hasSitemap(): bool
    {
        return $this->hasSitemap;
    }

    /**
     * `robots.txt`; `$using` returns its lines (or the whole text) instead of the default
     * (allow everything, link the sitemap).
     */
    public function robots(bool $condition = true, ?Closure $using = null): static
    {
        $this->hasRobots = $condition;
        $this->robotsUsing = $using;

        return $this;
    }

    public function hasRobots(): bool
    {
        return $this->hasRobots;
    }

    public function getRobotsUsing(): ?Closure
    {
        return $this->robotsUsing;
    }

    /**
     * The read-only JSON API of published pages and menus (opt-in).
     *
     * @param  array<int, string>|null  $middleware  Defaults to `['api']`.
     */
    public function api(bool $condition = true, ?string $prefix = null, ?array $middleware = null): static
    {
        $this->hasApi = $condition;

        if ($prefix !== null) {
            $this->apiPrefix = trim($prefix, '/');
        }

        if ($middleware !== null) {
            $this->apiMiddleware = array_values($middleware);
        }

        return $this;
    }

    public function hasApi(): bool
    {
        return $this->hasApi;
    }

    public function getApiPrefix(): string
    {
        return $this->apiPrefix;
    }

    /**
     * @return array<int, string>
     */
    public function getApiMiddleware(): array
    {
        return $this->apiMiddleware;
    }

    // ---------------------------------------------------------------- Publishing

    /**
     * How long a preview link works, in minutes (a day by default).
     */
    public function previewLinkLifetime(int $minutes): static
    {
        $this->previewLinkLifetime = max(1, $minutes);

        return $this;
    }

    public function getPreviewLinkLifetime(): int
    {
        return $this->previewLinkLifetime;
    }

    /**
     * Add a 301 redirect from the old path when a published page's path changes (default).
     */
    public function redirectOnPathChange(bool $condition = true): static
    {
        $this->createsRedirectsOnPathChange = $condition;

        return $this;
    }

    public function createsRedirectsOnPathChange(): bool
    {
        return $this->hasRedirects && $this->createsRedirectsOnPathChange;
    }

    /**
     * Push the redirect middleware onto the `web` group (default). Turn it off to add
     * `AlfaSix\AlfakitCms\Http\Middleware\HandleRedirects` yourself; the public catch-all
     * route applies redirects either way.
     */
    public function redirectMiddleware(bool $condition = true): static
    {
        $this->registersRedirectMiddleware = $condition;

        return $this;
    }

    public function registersRedirectMiddleware(): bool
    {
        return $this->hasRedirects && $this->registersRedirectMiddleware;
    }

    /**
     * Revisions kept per page (older ones are pruned); 0 keeps every revision.
     */
    public function revisionsLimit(int $limit): static
    {
        $this->revisionsLimit = max(0, $limit);

        return $this;
    }

    public function getRevisionsLimit(): int
    {
        return $this->revisionsLimit;
    }

    /**
     * Keep saved changes of published pages as a draft until they are published (default).
     * Turn it off to make every save of a published page live at once.
     */
    public function draftChanges(bool $condition = true): static
    {
        $this->keepsChangesAsDraft = $condition;

        return $this;
    }

    public function keepsChangesAsDraft(): bool
    {
        return $this->keepsChangesAsDraft;
    }

    /**
     * The live preview of the page editor (default), and how long it waits after the last
     * change before autosaving (milliseconds).
     */
    public function livePreview(bool $condition = true, ?int $debounce = null): static
    {
        $this->hasLivePreview = $condition;

        if ($debounce !== null) {
            $this->autosaveDebounce = max(300, $debounce);
        }

        return $this;
    }

    public function hasLivePreview(): bool
    {
        return $this->hasLivePreview;
    }

    public function getAutosaveDebounce(): int
    {
        return $this->autosaveDebounce;
    }

    /**
     * The floating admin bar on public pages for signed-in users who can edit them (opt-in).
     */
    public function adminBar(bool $condition = true): static
    {
        $this->hasAdminBar = $condition;

        return $this;
    }

    public function hasAdminBar(): bool
    {
        return $this->hasAdminBar;
    }

    // ---------------------------------------------------------------- Site design

    /**
     * The **Site design** page (default): brand colours, fonts, corner radius, logo and
     * favicon of the public site, and a **Style** section on every block.
     */
    public function siteDesign(bool $condition = true): static
    {
        $this->hasSiteDesign = $condition;

        return $this;
    }

    public function hasSiteDesign(): bool
    {
        return $this->hasSiteDesign;
    }

    /**
     * Custom CSS and head/body code snippets (analytics, chat widgets) on **Site design**.
     * Off by default: code added here runs on every public page, so only allow it for
     * people you trust with the site. `true` lets everyone who may edit the site design
     * change it; a closure decides per user:
     *
     * ```php
     * CmsPlugin::make()->customCode(fn (User $user): bool => $user->is_admin)
     * ```
     */
    public function customCode(bool|Closure $condition = true): static
    {
        $this->customCode = $condition;

        return $this;
    }

    /** Whether saved custom CSS and code reach public pages. */
    public function hasCustomCode(): bool
    {
        return $this->customCode !== false;
    }

    /** Whether the user may see and change the custom CSS and code. */
    public function canEditCustomCode(?Model $user): bool
    {
        if ($user === null || $this->customCode === false) {
            return false;
        }

        if ($this->customCode === true) {
            return true;
        }

        return (bool) $this->evaluate($this->customCode, ['user' => $user], [$user::class => $user, Model::class => $user]);
    }

    /**
     * The review workflow (default): authors submit pages for review, people who can
     * publish approve them or request changes. `$reviewers` returns who is notified of a
     * submission: `fn (Page $page): iterable => User::role('editor')->get()`.
     */
    public function review(bool $condition = true, ?Closure $reviewers = null): static
    {
        $this->hasReview = $condition;

        if ($reviewers !== null) {
            $this->reviewersUsing = $reviewers;
        }

        return $this;
    }

    public function hasReview(): bool
    {
        return $this->hasReview;
    }

    /**
     * @return array<int, Model>
     */
    public function getReviewers(Page $page): array
    {
        if ($this->reviewersUsing === null) {
            return [];
        }

        $reviewers = $this->evaluate($this->reviewersUsing, ['page' => $page, 'record' => $page], [Page::class => $page]);

        return is_iterable($reviewers) ? array_values(array_filter([...$reviewers], static fn (mixed $user): bool => $user instanceof Model)) : [];
    }

    /**
     * Page templates defined in code, next to the ones editors save in the panel:
     * `['landing' => ['label' => 'Landing page', 'description' => '…', 'content' => [...]]]`
     * (content is Builder state: `[['type' => 'hero', 'data' => [...]], …]`).
     *
     * @param  array<string, array{label: string, description?: string|null, content: array<int, array<string, mixed>>}>  $templates
     */
    public function templates(array $templates): static
    {
        $this->templates = $templates;

        return $this;
    }

    /**
     * @return array<string, array{label: string, description?: string|null, content: array<int, array<string, mixed>>}>
     */
    public function getTemplates(): array
    {
        return $this->templates;
    }

    /**
     * Templates editors save in the panel (a "Page templates" resource). On by default.
     */
    public function templateLibrary(bool $condition = true): static
    {
        $this->hasTemplates = $condition;

        return $this;
    }

    public function hasTemplateLibrary(): bool
    {
        return $this->hasTemplates;
    }

    /**
     * Reusable sections: blocks edited once and shown on many pages. On by default.
     */
    public function sections(bool $condition = true): static
    {
        $this->hasSections = $condition;
        $this->blockRegistry = null;

        return $this;
    }

    public function hasSections(): bool
    {
        return $this->hasSections;
    }

    /**
     * Show a small preview of each block in the editor instead of its fields until it is
     * opened (off by default).
     */
    public function blockPreviews(bool $condition = true): static
    {
        $this->hasBlockPreviews = $condition;

        return $this;
    }

    public function hasBlockPreviews(): bool
    {
        return $this->hasBlockPreviews;
    }

    /**
     * Offer images of alfasix/alfakit-media-library in image fields (on when that plugin is
     * registered on the panel, on panels without tenancy).
     */
    public function mediaLibrary(bool $condition = true): static
    {
        $this->usesMediaLibrary = $condition;

        return $this;
    }

    public function usesMediaLibrary(): bool
    {
        if (! $this->usesMediaLibrary || ! class_exists(\Spatie\MediaLibrary\MediaCollections\Models\Media::class) || ! class_exists('AlfaSix\\AlfakitMediaLibrary\\MediaLibraryPlugin')) {
            return false;
        }

        $panel = $this->panelId !== null && alfakit()->hasPanel($this->panelId) ? alfakit()->getPanel($this->panelId) : alfakit()->getCurrentPanel();

        // Only with the media library plugin on the panel; media items aren't tenant-scoped,
        // so tenancy panels never use them.
        return $panel !== null && $panel->hasPlugin('media-library') && ! $panel->hasTenancy();
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Decide every CMS ability yourself:
     * `fn (string $ability, string $model, ?Model $record, ?Model $user): bool`, where
     * `$model` is `Page::class`, `Redirect::class` or `Menu::class`. Abilities: `viewAny`,
     * `view`, `create`, `update`, `delete`, `deleteAny` and, for pages, `publish` and
     * `restoreRevision`. Without it, model policies decide (`publish` and
     * `restoreRevision` fall back to `update`).
     */
    public function authorize(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function getAuthorizeUsing(): ?Closure
    {
        return $this->authorizeUsing;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function checkAuthorization(string $ability, string $model, ?Model $record, ?Model $user): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeUsing, ['ability' => $ability, 'model' => $model, 'record' => $record, 'user' => $user]);
    }

    // ---------------------------------------------------------------- SEO

    /**
     * Change the SEO payload of a page: `fn (array $seo, Page $page): array`.
     */
    public function modifySeoUsing(?Closure $callback): static
    {
        $this->modifySeoUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    public function modifySeo(array $seo, Page $page): array
    {
        if ($this->modifySeoUsing === null) {
            return $seo;
        }

        $modified = $this->evaluate($this->modifySeoUsing, ['seo' => $seo, 'page' => $page, 'record' => $page], [Page::class => $page]);

        return is_array($modified) ? $modified : $seo;
    }

    /**
     * The Open Graph image of pages without their own (an absolute URL).
     */
    public function defaultOgImage(string|Closure|null $url): static
    {
        $this->defaultOgImage = $url;

        return $this;
    }

    public function getDefaultOgImage(): ?string
    {
        $url = $this->evaluate($this->defaultOgImage);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    protected static function assertSubclass(string $class, string $base): string
    {
        if (! is_a($class, $base, true)) {
            throw new InvalidArgumentException("The class [{$class}] must extend [{$base}].");
        }

        return $class;
    }
}
