<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Models\SiteSettings;
use AlfaSix\AlfakitCms\Pages\ManageSiteDesign;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Support\SiteDesign;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\cmsTeam;
use function AlfaSix\AlfakitCms\Tests\cmsUser;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    // Requests in a test share one Inertia instance; panel props shared by the editor
    // requests must not leak into the public page requests.
    Inertia\Inertia::flushShared();
});

afterEach(function (): void {
    cmsPlugin()->customCode(false)->siteDesign();
});

it('saves the site design and sends it with public pages', function (): void {
    actingAs(cmsUser());
    cmsPage('About', ['slug' => 'about']);
    Illuminate\Support\Facades\Storage::fake('public')->put('cms/site/logo.png', 'png');
    SiteSettings::query()->create(['design' => ['logo' => 'cms/site/logo.png']]);

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->fillForm(['primary' => '#FF6600', 'background' => '#0b1220', 'text' => '#f8fafc', 'heading_font' => 'playfair-display', 'body_font' => 'inter', 'radius' => 'large'])
        ->call('save')
        ->assertHasNoFormErrors();

    Inertia\Inertia::flushShared();

    expect(SiteSettings::query()->first()?->design)->toMatchArray(['primary' => '#ff6600', 'radius' => 'large', 'logo' => 'cms/site/logo.png']);

    $design = inertiaGet('/about')->assertOk()->json('props.cms.site.design');

    expect($design['css'])
        ->toContain('html:not(#alfakit-cms-design){')
        ->toContain('--primary:#ff6600;')
        ->toContain('--primary-foreground:#0a0a0a;')
        ->toContain('--background:#0b1220;')
        ->toContain('--radius:1rem;')
        ->toContain("--cms-font-heading:'Playfair Display'")
        ->not->toContain('<')
        ->and($design['fonts'])->toBe('https://fonts.bunny.net/css?family=playfair-display:400,500,600,700|inter:400,500,600,700&display=swap')
        ->and($design['logo'])->toBe(['url' => '/storage/cms/site/logo.png', 'alt' => 'Acme']);
});

it('sends no design until one is saved and drops values it does not know', function (): void {
    cmsPage('About', ['slug' => 'about']);

    inertiaGet('/about')->assertJsonPath('props.cms.site.design', null);

    SiteSettings::query()->create(['design' => ['primary' => 'red; } body { display:none', 'body_font' => 'comic-sans', 'radius' => 'huge']]);

    inertiaGet('/about')->assertJsonPath('props.cms.site.design', null);
});

it('shows an empty color field as the default, not as a hex value', function (): void {
    actingAs(cmsUser());
    $content = (fn (): array => $this->getPage()['content'])->call(alfakit()->testPage(ManageSiteDesign::class, panel: 'cms'));
    $colors = collect(\AlfaSix\AlfakitCms\Tests\flattenSchema($content))->where('type', 'ColorPicker');

    expect($colors)->not->toBeEmpty()
        ->and($colors->pluck('placeholder')->unique()->values()->all())->toBe(['Default']);
});

it('refuses colors that are not hex colors', function (): void {
    actingAs(cmsUser());

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->fillForm(['primary' => 'red; } body {'])
        ->call('save')
        ->assertHasFormErrors(['primary']);
});

it('picks readable text for the main color', function (): void {
    expect(SiteDesign::readableOn('#1e3a8a'))->toBe('#ffffff')
        ->and(SiteDesign::readableOn('#fde047'))->toBe('#0a0a0a')
        ->and(SiteDesign::hex('#AbC'))->toBe('#aabbcc');
});

it('hides custom CSS and code unless customCode() allows the user', function (): void {
    actingAs(cmsUser());

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->assertFormFieldDoesNotExist('css')
        ->assertFormFieldDoesNotExist('head')
        ->fillForm(['primary' => '#2563eb', 'css' => 'body{color:red}', 'head' => '<script>alert(1)</script>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SiteSettings::query()->first()?->code)->toBeNull();

    cmsPlugin()->customCode(fn (AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser $user): bool => $user->name === 'Root');

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')->assertFormFieldDoesNotExist('head');

    actingAs(cmsUser('Root'));

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->assertFormFieldExists('css')
        ->assertFormFieldExists('head')
        ->assertFormFieldExists('body');
});

it('prints the code snippets in the root view on public pages, never on previews', function (): void {
    cmsPlugin()->customCode();
    actingAs(cmsUser());
    $page = cmsPage('About', ['slug' => 'about']);

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->fillForm(['head' => '<script src="/stats.js" defer></script>', 'body' => '<div id="chat"></div>', 'css' => '.hero { letter-spacing: -0.02em }'])
        ->call('save')
        ->assertHasNoFormErrors();

    Inertia\Inertia::flushShared();
    $response = get('/about')->assertOk();

    expect((string) $response->viewData('alfakitCmsCode'))->toBe('<script src="/stats.js" defer></script>')
        ->and((string) $response->viewData('alfakitCmsBodyCode'))->toBe('<div id="chat"></div>');

    inertiaGet('/about')->assertOk();
    expect(inertiaGet('/about')->json('props.cms.site.design.css'))->toContain('.hero { letter-spacing: -0.02em }')
        ->and(json_encode(inertiaGet('/about')->json()))->not->toContain('stats.js');

    $preview = get(PreviewLinks::url($page))->assertOk();

    expect((string) $preview->viewData('alfakitCmsCode'))->toBe('')
        ->and((string) $preview->viewData('alfakitCmsBodyCode'))->toBe('');

    // Turned off again: saved code stops reaching pages.
    cmsPlugin()->customCode(false);

    expect((string) get('/about')->viewData('alfakitCmsCode'))->toBe('')
        ->and(inertiaGet('/about')->json('props.cms.site.design'))->toBeNull();
});

it('refuses custom CSS that imports, runs scripts or loads files from other sites', function (string $css, string $problem): void {
    expect(SiteDesign::cssProblem($css))->toBe($problem)
        ->and(SiteDesign::sanitizeCss($css))->toBeNull();
})->with([
    ['@import url("/x.css");', 'import'],
    ['@\69mport "x.css";', 'import'],
    ['div { width: expression(alert(1)) }', 'script'],
    ['a { background: url("javascript:alert(1)") }', 'script'],
    ['input[value^="a"] { background: url(https://evil.example/a) }', 'external'],
    ['div { background: url(//evil.example/a.png) }', 'external'],
    ['div { background: url(\'\\68 ttps://evil.example/a.png\') }', 'external'],
    ['div { background: url(file:///etc/passwd) }', 'external'],
]);

it('keeps safe custom CSS and can never close its style element', function (): void {
    config(['app.url' => 'https://acme.test']);

    expect(SiteDesign::cssProblem('.hero{background:url(/img/hero.webp)} .logo{background:url(https://acme.test/logo.png)}'))->toBeNull()
        ->and(SiteDesign::cssProblem('.dot{background:url(data:image/png;base64,iVBORw0KGgo=)}'))->toBeNull()
        ->and(SiteDesign::sanitizeCss('a::after { content: "</style><script>alert(1)</script>" }'))
        ->toBe('a::after { content: "\3c /style>\3c script>alert(1)\3c /script>" }');
});

it('reports a validation error for unsafe CSS on save', function (): void {
    cmsPlugin()->customCode();
    actingAs(cmsUser());

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->fillForm(['css' => 'body { background: url(https://tracker.example/p.gif) }'])
        ->call('save')
        ->assertHasFormErrors(['css']);
});

it('keeps earlier code in the history and restores it', function (): void {
    cmsPlugin()->customCode();
    actingAs(cmsUser());

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')->fillForm(['head' => '<!-- v1 -->'])->call('save')->assertHasNoFormErrors();
    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')->fillForm(['head' => '<!-- v2 -->'])->call('save')->assertHasNoFormErrors();

    $settings = SiteSettings::query()->firstOrFail();

    expect($settings->code['head'])->toBe('<!-- v2 -->')
        ->and($settings->code['by'])->toBe('Ada Admin')
        ->and($settings->code_history)->toHaveCount(1)
        ->and($settings->code_history[0]['head'])->toBe('<!-- v1 -->')
        ->and($settings->code_history[0]['by'])->toBe('Ada Admin');

    alfakit()->testPage(ManageSiteDesign::class, panel: 'cms')
        ->callAction('codeHistory', ['version' => 0])
        ->assertHasNoActionErrors();

    $settings->refresh();

    expect($settings->code['head'])->toBe('<!-- v1 -->')
        ->and($settings->code_history[0]['head'])->toBe('<!-- v2 -->');
});

it('sends the style of each block and leaves out defaults and unknown values', function (): void {
    cmsPage('Pricing', ['slug' => 'pricing', 'content' => [
        ['type' => 'rich-text', 'data' => ['content' => '<p>Plans</p>', 'cms_style' => ['background' => 'primary', 'spacing' => 'large', 'visibility' => 'all', 'anchor' => 'plans']]],
        ['type' => 'rich-text', 'data' => ['content' => '<p>Fine print</p>', 'cms_style' => ['background' => 'url(x)', 'spacing' => 'default', 'anchor' => 'Not An Anchor!']]],
    ]]);

    $blocks = inertiaGet('/pricing')->assertOk()->json('props.cms.page.blocks');

    expect($blocks[0]['style'])->toBe(['background' => 'primary', 'spacing' => 'large', 'anchor' => 'plans'])
        ->and($blocks[1])->not->toHaveKey('style');

    cmsPlugin()->siteDesign(false);

    expect(inertiaGet('/pricing')->json('props.cms.page.blocks.0'))->not->toHaveKey('style');
});

it('keeps each tenant\'s design apart', function (): void {
    $acme = cmsTeam('Acme');
    SiteSettings::query()->create(['tenant_id' => $acme->id, 'design' => ['primary' => '#ff0000']]);

    expect(SiteSettings::forTenant(null)->exists)->toBeFalse()
        ->and(SiteSettings::forTenant($acme)->design)->toBe(['primary' => '#ff0000']);
});

it('adds the Site design page to the panel only with siteDesign()', function (): void {
    actingAs(cmsUser());

    expect(ManageSiteDesign::canAccess())->toBeTrue();

    cmsPlugin()->siteDesign(false);

    expect(ManageSiteDesign::canAccess())->toBeFalse();
});

it('saves a block\'s Style section from the page editor', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Styled', ['slug' => 'styled', 'status' => AlfaSix\AlfakitCms\Enums\PageStatus::Draft]);
    $editor = alfakit()->testPage(AlfaSix\AlfakitCms\Resources\PageResource\Pages\EditPage::class, ['record' => $page], panel: 'cms');

    $content = $editor->getFormState()['content'];
    $key = array_key_first($content);

    expect($content[$key]['data']['cms_style'])->toBe(['background' => 'default', 'spacing' => 'default', 'visibility' => 'all', 'anchor' => null]);

    $content[$key]['data']['cms_style'] = ['background' => 'dark', 'spacing' => 'small', 'visibility' => 'all', 'anchor' => 'intro'];
    $editor->fillForm(['content' => $content])->call('save')->assertHasNoFormErrors();

    expect($page->refresh()->content[0]['data']['cms_style'])->toBe(['background' => 'dark', 'spacing' => 'small', 'visibility' => 'all', 'anchor' => 'intro'])
        ->and($page->content[0]['data'])->not->toHaveKey('background');

    $page->forceFill(['status' => AlfaSix\AlfakitCms\Enums\PageStatus::Published])->save();
    Inertia\Inertia::flushShared();

    expect(inertiaGet('/styled')->json('props.cms.page.blocks.0.style'))->toBe(['background' => 'dark', 'spacing' => 'small', 'anchor' => 'intro']);
});

it('leaves Site design out until its table is migrated', function (): void {
    actingAs(cmsUser());
    $memo = new ReflectionProperty(ManageSiteDesign::class, 'migratedConnections');
    $memo->setValue(null, []);
    Illuminate\Support\Facades\Schema::drop('alfakit_cms_site_settings');

    expect(ManageSiteDesign::canAccess())->toBeFalse();

    (require dirname(__DIR__).'/database/migrations/2026_10_27_000000_create_alfakit_cms_site_settings_table.php')->up();

    expect(ManageSiteDesign::canAccess())->toBeTrue();
});
