<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('renders a published page at its path as an Inertia page', function (): void {
    cmsPage('About us', ['slug' => 'about']);

    $response = inertiaGet('/about');

    $response->assertOk()
        ->assertJsonPath('component', 'Alfakit/CmsPage')
        ->assertJsonPath('props.cms.page.title', 'About us')
        ->assertJsonPath('props.cms.page.path', 'about')
        ->assertJsonPath('props.cms.page.blocks.0.type', 'rich-text')
        ->assertJsonPath('props.cms.page.blocks.0.data.html', '<p>About us</p>')
        ->assertJsonPath('props.cms.page.seo.title', 'About us | Acme')
        ->assertJsonPath('props.cms.site.name', 'Acme')
        ->assertJsonPath('props.cms.preview', false);
});

it('adds the opt-in "Powered by" credit below the footer only when it is on', function (): void {
    cmsPage('About us', ['slug' => 'about']);

    inertiaGet('/about')->assertOk()->assertJsonPath('props.cms.poweredBy', null);

    cmsPlugin()->poweredBy();

    $html = inertiaGet('/about')->assertOk()->json('props.cms.poweredBy');

    expect($html)->toContain('data-slot="powered-by"')->toContain('https://alfasixbh.com');

    cmsPlugin()->poweredBy(null);
});

it('serves the home page at the root and nested pages under their parents', function (): void {
    cmsPage('Welcome', ['is_home' => true]);
    $company = cmsPage('Company');
    cmsPage('Team', ['parent_id' => $company->id]);

    inertiaGet('/')->assertOk()->assertJsonPath('props.cms.page.title', 'Welcome');
    inertiaGet('/company/team')->assertOk()
        ->assertJsonPath('props.cms.page.title', 'Team')
        ->assertJsonPath('props.cms.page.breadcrumbs.0.label', 'Company')
        ->assertJsonPath('props.cms.page.breadcrumbs.1.label', 'Team');
});

it('answers 404 for drafts, scheduled pages and unknown paths', function (): void {
    cmsPage('Draft', ['status' => PageStatus::Draft]);
    cmsPage('Later', ['publish_at' => now()->addDay()]);

    get('/draft')->assertNotFound();
    get('/later')->assertNotFound();
    get('/nothing-here')->assertNotFound();
});

it('publishes scheduled pages once their time has come', function (): void {
    cmsPage('Launch', ['publish_at' => now()->addHour()]);

    get('/launch')->assertNotFound();

    $this->travel(2)->hours();

    inertiaGet('/launch')->assertOk()->assertJsonPath('props.cms.page.title', 'Launch');
});

it('drops unsafe content from blocks', function (): void {
    cmsPage('Unsafe', ['content' => [
        ['type' => 'rich-text', 'data' => ['content' => '<p onclick="alert(1)">Hi<script>alert(1)</script></p>']],
        ['type' => 'hero', 'data' => ['heading' => 'Hello', 'primary_label' => 'Go', 'primary_url' => 'javascript:alert(1)']],
        ['type' => 'embed', 'data' => ['url' => 'https://evil.example.com/frame', 'title' => 'Evil']],
        ['type' => 'embed', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ?t=42', 'title' => 'Video']],
        ['type' => 'unknown', 'data' => ['x' => 1]],
    ]]);

    $blocks = inertiaGet('/unsafe')->assertOk()->json('props.cms.page.blocks');

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]['data']['html'])->toBe('<p>Hi</p>')
        ->and($blocks[1]['data']['primaryAction'])->toBeNull()
        ->and($blocks[2]['data']['embed'])->toMatchArray(['provider' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=42']);
});

it('renders custom blocks and blocks nested in columns', function (): void {
    cmsPage('Plans', ['content' => [
        ['type' => 'pricing', 'data' => ['plan' => 'Pro']],
        ['type' => 'columns', 'data' => ['columns' => [
            ['blocks' => [['type' => 'pricing', 'data' => ['plan' => 'Left']]]],
            ['blocks' => [['type' => 'rich-text', 'data' => ['content' => '<p>Right</p>']]]],
        ]]],
    ]]);

    $blocks = inertiaGet('/plans')->assertOk()->json('props.cms.page.blocks');

    expect($blocks[0])->toMatchArray(['type' => 'pricing', 'data' => ['plan' => 'Pro', 'depth' => 0]])
        ->and($blocks[1]['data']['columns'][0]['blocks'][0]['data'])->toBe(['plan' => 'Left', 'depth' => 1])
        ->and($blocks[1]['data']['columns'][1]['blocks'][0]['data']['html'])->toBe('<p>Right</p>');
});

it('builds SEO metadata with JSON-LD that cannot close its script tag', function (): void {
    cmsPage('FAQ', [
        'seo' => ['description' => 'Answers </script><script>alert(1)</script>', 'noindex' => true, 'schema_type' => 'FAQPage'],
        'content' => [['type' => 'faq', 'data' => ['items' => [['question' => 'Why?', 'answer' => 'Because.']]]]],
    ]);

    $seo = inertiaGet('/faq')->assertOk()->json('props.cms.page.seo');

    expect($seo['robots'])->toBe('noindex, nofollow')
        ->and($seo['canonical'])->toBe('http://localhost/faq')
        ->and($seo['openGraph']['title'])->toBe('FAQ | Acme')
        ->and($seo['jsonLd'])->not->toContain('<')
        ->and(json_decode($seo['jsonLd'], true)['@graph'][1]['@type'])->toBe('FAQPage');
});

it('prints the head tags in the root view for crawlers without JavaScript', function (): void {
    Illuminate\Support\Facades\View::addLocation(__DIR__.'/Fixtures/views');
    cmsPlugin()->rootView('cms-root');
    cmsPage('Seo page', ['seo' => ['description' => 'A "quoted" <b>description</b>']]);

    get('/seo-page')->assertOk()
        ->assertSee('<title>Seo page | Acme</title>', escape: false)
        ->assertSee('<meta name="description" content="A &quot;quoted&quot; &lt;b&gt;description&lt;/b&gt;">', escape: false)
        ->assertSee('<link rel="canonical" href="http://localhost/seo-page">', escape: false)
        ->assertSee('<script type="application/ld+json">', escape: false);
});

it('lets your own routes win over the catch-all route', function (): void {
    Illuminate\Support\Facades\Route::get('/about', fn () => 'app route');
    cmsPage('About', ['slug' => 'about']);

    get('/about')->assertOk()->assertSee('app route');
});

it('keeps unpublished pages out of the sitemap and lists live ones', function (): void {
    cmsPage('Visible');
    cmsPage('Hidden', ['seo' => ['noindex' => true]]);
    cmsPage('Draft', ['status' => PageStatus::Draft]);

    $xml = get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

    expect($xml)->toContain('<loc>http://localhost/visible</loc>')
        ->not->toContain('hidden')
        ->not->toContain('draft');
});

it('serves robots.txt with the sitemap', function (): void {
    get('/robots.txt')->assertOk()
        ->assertSee('User-agent: *')
        ->assertSee('Sitemap: http://localhost/sitemap.xml');

    cmsPlugin()->robots(using: fn (): array => ['User-agent: *', 'Disallow: /']);

    get('/robots.txt')->assertOk()->assertSee('Disallow: /');
});

it('stores the derived path and keeps descendants in sync', function (): void {
    $company = cmsPage('Company');
    $team = cmsPage('Team', ['parent_id' => $company->id]);

    $company->update(['slug' => 'about']);

    expect($team->fresh()?->path)->toBe('about/team')
        ->and(Page::query()->where('path', 'company/team')->exists())->toBeFalse();
});

it('resolves block images on the CMS disk and rejects unsafe paths', function (): void {
    cmsPage('Media', ['content' => [
        ['type' => 'image', 'data' => ['image' => 'cms/photo.jpg', 'image_alt' => 'A photo', 'width' => 'wide']],
        ['type' => 'image', 'data' => ['image' => '../../.env']],
        ['type' => 'gallery', 'data' => ['columns' => 9, 'images' => [['image' => 'https://cdn.example.com/a.png', 'caption' => 'A']]]],
    ]]);

    $blocks = inertiaGet('/media')->assertOk()->json('props.cms.page.blocks');

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0]['data'])->toMatchArray(['image' => ['url' => 'http://localhost/storage/cms/photo.jpg', 'alt' => 'A photo'], 'width' => 'wide'])
        ->and($blocks[1]['data']['columns'])->toBe(3)
        ->and($blocks[1]['data']['images'][0]['url'])->toBe('https://cdn.example.com/a.png');
});

it('hands form blocks to the application through formUsing()', function (): void {
    cmsPage('Contact', ['content' => [
        ['type' => 'form', 'data' => ['heading' => 'Write to us', 'form' => 'contact']],
        ['type' => 'form', 'data' => ['form' => 'unknown']],
    ]]);

    $blocks = inertiaGet('/contact')->assertOk()->json('props.cms.page.blocks');

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data'])->toMatchArray(['form' => 'contact', 'payload' => ['key' => 'contact', 'action' => '/forms/contact']]);
});

it('limits the page builder to the configured blocks', function (): void {
    $registry = AlfaSix\AlfakitCms\Support\BlockRegistry::make([], ['testimonials'], null);

    expect($registry->has('testimonials'))->toBeFalse()
        ->and(array_keys(AlfaSix\AlfakitCms\Support\BlockRegistry::make([], [], ['faq', 'hero'])->all()))->toBe(['faq', 'hero'])
        ->and(cmsPlugin()->getBlockRegistry()->has('pricing'))->toBeTrue();

    expect(fn () => AlfaSix\AlfakitCms\Support\BlockRegistry::make([stdClass::class]))->toThrow(InvalidArgumentException::class);
});

it('serves the home page at the bare prefix of a prefixed site', function (): void {
    // `publicRoutes(prefix: 'site')`: `/site` is the home page, `/site/about` a page.
    $route = clone Illuminate\Support\Facades\Route::getRoutes()->getByName('alfakit-cms.page');
    $route->prefix('site');

    expect($route->matches(Illuminate\Http\Request::create('/site')))->toBeTrue()
        ->and($route->matches(Illuminate\Http\Request::create('/site/about/team')))->toBeTrue()
        ->and($route->matches(Illuminate\Http\Request::create('/other')))->toBeFalse();
});
