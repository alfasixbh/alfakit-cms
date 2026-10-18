<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Menu;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\Redirect;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\CreatePage;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\EditPage;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\ListPages;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\cmsTeam;
use function AlfaSix\AlfakitCms\Tests\cmsUser;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * Serve the public site from the tenancy panel (the tenant comes from the `X-Team` header).
 */
function cmsTenantSite(): void
{
    cmsPlugin('cms')->publicRoutes(false)->api(false);
    cmsPlugin('cms-team')->publicRoutes()->api(middleware: []);
}

afterEach(function (): void {
    cmsPlugin('cms')->publicRoutes()->api(middleware: [])->locales([]);
    cmsPlugin('cms-team')->publicRoutes(false)->api(false);
});

it('scopes the page list and editor to the current tenant', function (): void {
    $alpha = cmsTeam('Alpha');
    $beta = cmsTeam('Beta');
    $user = cmsUser();
    $user->teams()->attach([$alpha->id, $beta->id]);
    actingAs($user);

    $mine = cmsPage('Alpha page', ['tenant_id' => $alpha->id]);
    $theirs = cmsPage('Beta page', ['tenant_id' => $beta->id]);

    alfakit()->testPage(ListPages::class, ['tenant' => $alpha], panel: 'cms-team')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    inertiaGet('/cms-team/'.$alpha->slug.'/cms/pages/'.$theirs->id.'/edit')->assertNotFound();
});

it('creates pages in the current tenant and allows the same path in another tenant', function (): void {
    $alpha = cmsTeam('Alpha');
    $beta = cmsTeam('Beta');
    $user = cmsUser();
    $user->teams()->attach([$alpha->id, $beta->id]);
    actingAs($user);

    cmsPage('About', ['tenant_id' => $beta->id]);

    alfakit()->testPage(CreatePage::class, ['tenant' => $alpha], panel: 'cms-team')
        ->fillForm(['title' => 'About'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Page::query()->where('tenant_id', $alpha->id)->where('path', 'about')->exists())->toBeTrue();

    alfakit()->testPage(CreatePage::class, ['tenant' => $alpha], panel: 'cms-team')
        ->fillForm(['title' => 'About'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('only offers parent pages of the current tenant', function (): void {
    $alpha = cmsTeam('Alpha');
    $beta = cmsTeam('Beta');
    $user = cmsUser();
    $user->teams()->attach([$alpha->id]);
    actingAs($user);

    $page = cmsPage('Child', ['tenant_id' => $alpha->id]);
    $foreign = cmsPage('Foreign', ['tenant_id' => $beta->id]);

    alfakit()->testPage(EditPage::class, ['tenant' => $alpha, 'record' => $page], panel: 'cms-team')
        ->fillForm(['parent_id' => $foreign->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);
});

it('serves each tenant its own public pages, menus, redirects and sitemap', function (): void {
    cmsTenantSite();
    $alpha = cmsTeam('Alpha');
    $beta = cmsTeam('Beta');

    cmsPage('Alpha about', ['tenant_id' => $alpha->id, 'slug' => 'about']);
    cmsPage('Beta about', ['tenant_id' => $beta->id, 'slug' => 'about']);
    cmsPage('Beta only', ['tenant_id' => $beta->id]);
    Menu::query()->create(['tenant_id' => $alpha->id, 'name' => 'Main', 'handle' => 'main', 'items' => [['label' => 'Alpha link', 'type' => 'url', 'url' => '/x']]]);
    Redirect::query()->create(['tenant_id' => $beta->id, 'from_path' => '/promo', 'to_url' => '/beta-only']);

    inertiaGet('/about', ['X-Team' => 'alpha'])->assertOk()
        ->assertJsonPath('props.cms.page.title', 'Alpha about')
        ->assertJsonPath('props.cms.site.name', 'Teams')
        ->assertJsonPath('props.cms.menus.main.items.0.label', 'Alpha link');
    inertiaGet('/about', ['X-Team' => 'beta'])->assertOk()
        ->assertJsonPath('props.cms.page.title', 'Beta about')
        ->assertJsonMissingPath('props.cms.menus.main');

    get('/beta-only', ['X-Team' => 'alpha'])->assertNotFound();
    get('/promo', ['X-Team' => 'alpha'])->assertNotFound();
    get('/promo', ['X-Team' => 'beta'])->assertRedirect('http://localhost/beta-only');

    // Without a tenant, a tenancy site shows nothing.
    get('/about')->assertNotFound();

    expect(get('/sitemap.xml', ['X-Team' => 'alpha'])->getContent())->toContain('/about')->not->toContain('beta-only');

    getJson('/api/cms/pages', ['X-Team' => 'beta'])->assertOk()->assertJsonCount(2, 'data');
    getJson('/api/cms/page?path=beta-only', ['X-Team' => 'alpha'])->assertNotFound();
});

it('serves translations under locale prefixes with hreflang alternates', function (): void {
    cmsPlugin()->locales(['en' => 'English', 'ar' => 'العربية']);

    $about = cmsPage('About', ['locale' => 'en']);
    cmsPage('من نحن', ['locale' => 'ar', 'slug' => 'about', 'translation_of_id' => $about->id]);
    cmsPage('Only Arabic', ['locale' => 'ar', 'slug' => 'only']);

    inertiaGet('/about')->assertOk()
        ->assertJsonPath('props.cms.page.title', 'About')
        ->assertJsonPath('props.cms.site.direction', 'ltr')
        ->assertJsonPath('props.cms.page.seo.alternates.1.hreflang', 'ar')
        ->assertJsonPath('props.cms.page.seo.alternates.1.href', 'http://localhost/ar/about');

    inertiaGet('/ar/about')->assertOk()
        ->assertJsonPath('props.cms.page.title', 'من نحن')
        ->assertJsonPath('props.cms.site.direction', 'rtl')
        ->assertJsonPath('props.cms.site.locales.0.url', 'http://localhost/about')
        ->assertJsonPath('props.cms.translations.cms.skip_to_content', 'تخطَّ إلى المحتوى');

    get('/only')->assertNotFound();
    get('/en/about')->assertNotFound();

    $sitemap = get('/sitemap.xml')->getContent();

    expect($sitemap)->toContain('hreflang="ar" href="http://localhost/ar/about"')
        ->toContain('<loc>http://localhost/ar/only</loc>');

    getJson('/api/cms/page?path=about&locale=ar')->assertOk()->assertJsonPath('data.title', 'من نحن');
});

it('prefixes every locale when asked to', function (): void {
    cmsPlugin()->locales(['en', 'de'], default: 'en', prefixDefault: true);
    cmsPage('Home', ['locale' => 'en', 'is_home' => true]);
    cmsPage('Start', ['locale' => 'de', 'is_home' => true]);

    get('/')->assertRedirect('http://localhost/en');
    inertiaGet('/en')->assertOk()->assertJsonPath('props.cms.page.title', 'Home');
    inertiaGet('/de')->assertOk()->assertJsonPath('props.cms.page.title', 'Start');
});

it('keeps drafts of other tenants out of previews only by signature', function (): void {
    $alpha = cmsTeam('Alpha');
    $page = cmsPage('Alpha draft', ['tenant_id' => $alpha->id, 'status' => PageStatus::Draft]);

    inertiaGet(AlfaSix\AlfakitCms\Support\PreviewLinks::url($page))->assertOk()->assertJsonPath('props.cms.page.title', 'Alpha draft');
});
