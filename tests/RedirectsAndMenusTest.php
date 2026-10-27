<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Cms;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Menu;
use AlfaSix\AlfakitCms\Models\Redirect;
use AlfaSix\AlfakitCms\Resources\MenuResource\Pages\CreateMenu;
use AlfaSix\AlfakitCms\Resources\RedirectResource\Pages\ManageRedirects;
use AlfaSix\AlfakitCms\Support\Redirects;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\cmsUser;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('redirects exact and wildcard paths with the right status and counts hits', function (): void {
    Redirect::query()->create(['from_path' => '/Old-Page/', 'to_url' => '/new-page', 'status_code' => 302]);
    $wildcard = Redirect::query()->create(['from_path' => '/blog/*', 'to_url' => '/news/*']);
    Redirect::query()->create(['from_path' => '/gone', 'to_url' => 'https://example.com/elsewhere', 'is_active' => false]);

    get('/old-page?ref=mail')->assertStatus(302)->assertRedirect('http://localhost/new-page?ref=mail');
    get('/blog/2024/hello')->assertStatus(301)->assertRedirect('http://localhost/news/2024/hello');
    get('/gone')->assertNotFound();

    // Only GET and HEAD are redirected.
    post('/old-page')->assertStatus(405);

    expect($wildcard->refresh()->hits)->toBe(1)->and($wildcard->last_hit_at)->not->toBeNull();
});

it('redirects paths outside the prefix of a prefixed site, which no route matches', function (): void {
    // `publicRoutes(prefix: 'site')`: the catch-all only covers `/site/*`.
    cmsPlugin()->publicRoutes(prefix: 'site');
    $router = app(Illuminate\Routing\Router::class);
    $router->setRoutes(new Illuminate\Routing\RouteCollection);
    AlfaSix\AlfakitCms\AlfakitCmsServiceProvider::registerRoutes($router);
    $router->middleware('web')->get('/old-catalog/kept', fn () => 'app route');

    $redirect = Redirect::query()->create(['from_path' => '/old-catalog/*', 'to_url' => '/site/products/*', 'status_code' => 301]);

    get('/old-catalog/lamps?campaign=autumn')->assertStatus(301)->assertRedirect('http://localhost/site/products/lamps?campaign=autumn');
    inertiaGet('/old-catalog/desks')->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/site/products/desks');
    // Your own routes run the redirect middleware of the web group as before.
    get('/old-catalog/kept')->assertStatus(301)->assertRedirect('http://localhost/site/products/kept');
    // Unknown paths without a redirect, and other methods, stay 404s.
    get('/nowhere')->assertNotFound();
    post('/old-catalog/lamps')->assertNotFound();

    expect($redirect->refresh()->hits)->toBe(3);
});

it('sends Inertia visits to the target with a full page visit', function (): void {
    Redirect::query()->create(['from_path' => '/old', 'to_url' => 'https://example.com']);

    inertiaGet('/old')->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://example.com');
});

it('refreshes the cached redirects when one changes', function (): void {
    $redirect = Redirect::query()->create(['from_path' => '/a', 'to_url' => '/b']);
    get('/a')->assertRedirect('http://localhost/b');

    $redirect->update(['to_url' => '/c']);
    get('/a')->assertRedirect('http://localhost/c');

    $redirect->delete();
    get('/a')->assertNotFound();
});

it('normalises redirect paths', function (): void {
    expect(Redirects::normalizePath('https://old.example.com/About/'))->toBe('/About')
        ->and(Redirects::normalizePath('blog//*'))->toBe('/blog/*')
        ->and(Redirects::normalizePath('/'))->toBe('/');
});

it('manages redirects in modals with validation', function (): void {
    actingAs(cmsUser());
    Redirect::query()->create(['from_path' => '/taken', 'to_url' => '/x']);

    alfakit()->testPage(ManageRedirects::class, panel: 'cms')
        ->callAction('create', ['from_path' => '/taken', 'to_url' => '/y', 'status_code' => 301, 'is_active' => true])
        ->assertHasActionErrors(['from_path'])
        ->callAction('create', ['from_path' => '/loop', 'to_url' => '/loop', 'status_code' => 301, 'is_active' => true])
        ->assertHasActionErrors(['to_url'])
        ->callAction('create', ['from_path' => '/js', 'to_url' => 'javascript:alert(1)', 'status_code' => 301, 'is_active' => true])
        ->assertHasActionErrors(['to_url'])
        ->callAction('create', ['from_path' => '/promo', 'to_url' => '/sale', 'status_code' => 302, 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(Redirect::query()->where('from_path', '/promo')->first()?->status_code)->toBe(302);
});

it('builds nested menus with live page links and marks the active trail', function (): void {
    $company = cmsPage('Company');
    $team = cmsPage('Team', ['parent_id' => $company->id]);
    $draft = cmsPage('Draft', ['status' => PageStatus::Draft]);

    Menu::query()->create(['name' => 'Main', 'handle' => 'main', 'items' => [
        ['label' => '', 'type' => 'page', 'page_id' => $company->id, 'children' => [
            ['label' => 'Our team', 'type' => 'page', 'page_id' => $team->id],
        ]],
        ['label' => 'Hidden', 'type' => 'page', 'page_id' => $draft->id],
        ['label' => 'Docs', 'type' => 'url', 'url' => 'https://docs.example.com', 'new_tab' => true],
        ['label' => 'Bad', 'type' => 'url', 'url' => 'javascript:alert(1)'],
    ]]);

    $menus = inertiaGet('/company/team')->assertOk()->json('props.cms.menus');
    $items = $menus['main']['items'];

    expect($items)->toHaveCount(3)
        ->and($items[0])->toMatchArray(['label' => 'Company', 'url' => 'http://localhost/company', 'active' => true, 'current' => false])
        ->and($items[0]['children'][0])->toMatchArray(['label' => 'Our team', 'current' => true])
        ->and($items[1])->toMatchArray(['label' => 'Docs', 'newTab' => true, 'active' => false])
        ->and($items[2])->toMatchArray(['label' => 'Bad', 'url' => null]);
});

it('creates menus with nested items in the panel', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Pricing');

    alfakit()->testPage(CreateMenu::class, panel: 'cms')
        ->fillForm([
            'name' => 'Footer',
            'handle' => 'footer',
            'items' => [
                ['label' => 'Pricing', 'type' => 'page', 'page_id' => $page->id, 'new_tab' => false, 'children' => [
                    ['label' => 'Docs', 'type' => 'url', 'url' => 'https://docs.example.com', 'new_tab' => true, 'children' => []],
                ]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Menu::query()->where('handle', 'footer')->first()?->items[0]['children'][0]['url'])->toBe('https://docs.example.com');

    alfakit()->testPage(CreateMenu::class, panel: 'cms')
        ->fillForm(['name' => 'Again', 'handle' => 'footer'])
        ->call('create')
        ->assertHasFormErrors(['handle']);
});

it('shares menus with your own Inertia pages', function (): void {
    Menu::query()->create(['name' => 'Main', 'handle' => 'main', 'items' => [['label' => 'Docs', 'type' => 'url', 'url' => '/docs']]]);
    Cms::shareMenus(['main']);
    Illuminate\Support\Facades\Route::middleware('web')->get('/app-page', fn () => Inertia\Inertia::render('App/Page'));

    inertiaGet('/app-page')->assertOk()->assertJsonPath('props.cmsMenus.main.items.0.label', 'Docs');

    expect(Cms::menu('main')['items'][0]['url'])->toBe('/docs')
        ->and(Cms::menu('missing'))->toBeNull();
});

it('serves published pages and menus through the JSON API', function (): void {
    cmsPage('Visible', ['content' => [['type' => 'hero', 'data' => ['heading' => 'Hi']]]]);
    cmsPage('Hidden', ['status' => PageStatus::Draft]);
    Menu::query()->create(['name' => 'Main', 'handle' => 'main', 'items' => [['label' => 'Docs', 'type' => 'url', 'url' => '/docs']]]);

    getJson('/api/cms/pages')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Visible')
        ->assertJsonPath('meta.total', 1);

    getJson('/api/cms/page?path=visible')->assertOk()
        ->assertJsonPath('data.blocks.0.data.heading', 'Hi')
        ->assertJsonPath('data.seo.title', 'Visible | Acme');

    getJson('/api/cms/page?path=hidden')->assertNotFound();
    getJson('/api/cms/menus/main')->assertOk()->assertJsonPath('data.items.0.label', 'Docs');
    getJson('/api/cms/menus/nope')->assertNotFound();
});
