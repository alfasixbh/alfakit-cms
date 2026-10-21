<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use AlfaSix\AlfakitCms\Models\Redirect;
use AlfaSix\AlfakitCms\Resources\PageResource;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\CreatePage;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\EditPage;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\ListPages;
use AlfaSix\AlfakitCms\Support\PageRevisions;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Tests\Fixtures\Policies\PagePolicy;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\cmsUser;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('registers the CMS resources and frontend strings on the panel', function (): void {
    $panel = alfakit()->getPanel('cms');

    expect($panel->getResources())->toContain(PageResource::class)
        ->and($panel->getFrontendTranslations()['cms']['skip_to_content'])->toBe('Skip to content');
});

it('creates a page from blocks and records its first revision', function (): void {
    actingAs(cmsUser());

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->assertOk()
        ->assertFormFieldExists('content')
        ->fillForm([
            'title' => 'Pricing plans',
            'content' => [
                ['type' => 'hero', 'data' => ['heading' => 'Simple pricing', 'alignment' => 'center']],
                ['type' => 'pricing', 'data' => ['plan' => 'Pro']],
            ],
        ])
        // `afterStateUpdated` derived the slug from the title.
        ->assertFormSet(['slug' => 'pricing-plans'])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::query()->where('path', 'pricing-plans')->firstOrFail();

    expect($page->status)->toBe(PageStatus::Draft)
        ->and($page->content)->toHaveCount(2)
        ->and($page->revisions()->count())->toBe(1);
});

it('validates block fields: embeds, links and required headings', function (): void {
    actingAs(cmsUser());

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->fillForm([
            'title' => 'Bad blocks',
            'content' => [
                ['type' => 'embed', 'data' => ['url' => 'https://evil.example.com/frame', 'title' => 'Frame', 'aspect_ratio' => '16/9']],
                ['type' => 'hero', 'data' => ['heading' => 'Hi', 'primary_label' => 'Go', 'primary_url' => 'javascript:alert(1)']],
                ['type' => 'call-to-action', 'data' => ['heading' => '']],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['content.0.data.url', 'content.1.data.primary_url', 'content.2.data.heading']);

    expect(Page::query()->where('title', 'Bad blocks')->exists())->toBeFalse();
});

it('rejects a path another page already uses and a parent loop', function (): void {
    actingAs(cmsUser());
    $about = cmsPage('About');
    $team = cmsPage('Team', ['parent_id' => $about->id]);

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->fillForm(['title' => 'About again', 'slug' => 'about'])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    alfakit()->testPage(EditPage::class, ['record' => $about], panel: 'cms')
        ->fillForm(['parent_id' => $team->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($about->fresh()?->parent_id)->toBeNull();
});

it('publishes now, schedules and unpublishes from the editor', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Launch', ['status' => PageStatus::Draft]);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionVisible('publish')
        ->assertActionHidden('unpublish')
        ->callAction('publish', ['publish_at' => now()->addDay()->format('Y-m-d H:i')])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($page->refresh()->getState())->toBe(PageState::Scheduled);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->callAction('publish', ['publish_at' => null])
        ->assertHasNoActionErrors();

    expect($page->refresh()->getState())->toBe(PageState::Published)
        ->and($page->published_at)->not->toBeNull();

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->callAction('unpublish')
        ->assertHasNoActionErrors();

    expect($page->refresh()->getState())->toBe(PageState::Draft);
});

it('lists pages by state and publishes in bulk', function (): void {
    actingAs(cmsUser());
    $draft = cmsPage('Draft', ['status' => PageStatus::Draft]);
    $live = cmsPage('Live');
    $later = cmsPage('Later', ['publish_at' => now()->addWeek()]);

    alfakit()->testPage(ListPages::class, panel: 'cms')
        ->assertCanSeeTableRecords([$draft, $live, $later])
        ->filterTable('state', 'scheduled')
        ->assertCanSeeTableRecords([$later])
        ->assertCanNotSeeTableRecords([$draft, $live])
        ->resetTableFilters()
        ->callTableBulkAction('publish', [$draft, $later])
        ->assertHasNoActionErrors();

    expect($draft->refresh()->isLive())->toBeTrue()
        ->and($later->refresh()->isLive())->toBeTrue();
});

it('lets only users with the publish ability publish', function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    actingAs(cmsUser('Writer'));
    $page = cmsPage('Draft', ['status' => PageStatus::Draft]);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionHidden('publish')
        // Publishing happens with the editor bar's actions, not a field.
        ->assertFormFieldDoesNotExist('status');

    // A crafted save can't publish either.
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->fillForm(['title' => 'Renamed', 'status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh())->title->toBe('Renamed')->status->toBe(PageStatus::Draft);

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->fillForm(['title' => 'Sneaky', 'status' => 'published'])
        ->call('create');

    expect(Page::query()->where('title', 'Sneaky')->first()?->status)->toBe(PageStatus::Draft);

    actingAs(cmsUser('Publisher'));

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionVisible('publish');
});

it('uses the plugin authorize closure for every ability', function (): void {
    cmsPlugin()->authorize(fn (string $ability, string $model): bool => $model === Page::class && in_array($ability, ['viewAny', 'view'], true));
    actingAs(cmsUser());
    $page = cmsPage('Readonly');

    inertiaGet('/cms/cms/pages')->assertOk();
    inertiaGet('/cms/cms/pages/create')->assertForbidden();
    inertiaGet('/cms/cms/pages/'.$page->id.'/edit')->assertForbidden();
    inertiaGet('/cms/cms/redirects')->assertForbidden();

    cmsPlugin()->authorize(null);
});

it('keeps guests out of the CMS panel', function (): void {
    get('/cms/cms/pages')->assertRedirect();
});

it('restores a revision without touching the publishing state', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Original');
    $page->update(['title' => 'Second', 'content' => [['type' => 'rich-text', 'data' => ['content' => '<p>Second</p>']]]]);

    expect($page->revisions()->count())->toBe(2);

    $first = $page->revisions()->where('number', 1)->firstOrFail();

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->relationManager(PageResource\RelationManagers\RevisionsRelationManager::class)
        ->callTableAction('restoreRevision', $first)
        ->assertHasNoActionErrors();

    $page->refresh();

    expect($page->title)->toBe('Original')
        ->and($page->content[0]['data']['content'])->toBe('<p>Original</p>')
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->revisions()->count())->toBe(3)
        ->and($page->revisions()->first()?->note)->toBe('Restored from revision 1');
});

it('keeps the current slug when the restored path is taken', function (): void {
    $page = cmsPage('First');
    $page->update(['slug' => 'renamed']);
    cmsPage('Taker', ['slug' => 'first']);

    PageRevisions::restore($page->revisions()->where('number', 1)->firstOrFail());

    expect($page->refresh()->path)->toBe('renamed')->and($page->title)->toBe('First');
});

it('prunes revisions beyond the limit', function (): void {
    cmsPlugin()->revisionsLimit(2);
    $page = cmsPage('V1');
    $page->update(['title' => 'V2']);
    $page->update(['title' => 'V3']);

    expect(PageRevision::query()->where('page_id', $page->id)->orderBy('number')->pluck('number')->all())->toBe([2, 3]);

    cmsPlugin()->revisionsLimit(50);
});

it('previews drafts and revisions through signed links only', function (): void {
    $page = cmsPage('Secret draft', ['status' => PageStatus::Draft]);
    $page->update(['title' => 'Secret draft v2']);

    $url = PreviewLinks::url($page);

    get('/secret-draft')->assertNotFound();
    inertiaGet($url)->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertJsonPath('props.cms.preview', true)
        ->assertJsonPath('props.cms.page.title', 'Secret draft v2')
        ->assertJsonPath('props.cms.page.seo.robots', 'noindex, nofollow');

    $revision = $page->revisions()->where('number', 1)->firstOrFail();
    inertiaGet(PreviewLinks::url($page, $revision))->assertOk()->assertJsonPath('props.cms.page.title', 'Secret draft');

    // Tampered or expired links are refused.
    get(str_replace('preview/'.$page->id, 'preview/'.($page->id + 1), $url))->assertForbidden();
    get('/_cms/preview/'.$page->id)->assertForbidden();

    $this->travel(2)->days();

    get($url)->assertForbidden();
});

it('shows preview and live links in the editor', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Live page');

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionVisible('preview')
        ->assertActionShouldOpenUrlInNewTab('preview')
        ->assertActionHasUrl('viewLive', 'http://localhost/live-page');
});

it('adds a redirect when a published page moves', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Old name');

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->fillForm(['slug' => 'new-name'])
        ->call('save')
        ->assertHasNoFormErrors();

    // On a published page the change waits as a draft until it is published.
    expect($page->refresh()->path)->toBe('old-name')
        ->and($page->hasPendingChanges())->toBeTrue()
        ->and(Redirect::query()->count())->toBe(0);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionHasLabel('publish', 'Publish changes')
        ->callAction('publish', ['publish_at' => null])
        ->assertHasNoActionErrors();

    expect($page->refresh()->path)->toBe('new-name')
        ->and(Redirect::query()->where('from_path', '/old-name')->first()?->to_url)->toBe('/new-name');

    // The panel requests above shared their props with Inertia; a real public request never has them.
    Inertia\Inertia::flushShared();

    get('/old-name')->assertStatus(301)->assertRedirect('http://localhost/new-name');

    // Moving it back removes the redirect that would hide it.
    $page->refresh()->update(['slug' => 'old-name']);

    expect(Redirect::query()->where('from_path', '/old-name')->exists())->toBeFalse();
    inertiaGet('/old-name')->assertOk();
    get('/new-name')->assertRedirect('http://localhost/old-name');
});

it('makes a single home page per site', function (): void {
    $first = cmsPage('Home one', ['is_home' => true]);
    $second = cmsPage('Home two', ['is_home' => true]);

    expect($first->refresh()->is_home)->toBeFalse()
        ->and($second->refresh()->is_home)->toBeTrue()
        ->and($second->path)->toBe('');
});

it('puts the status, "Save draft" and "Publish" in the editor bar', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Launch', ['status' => PageStatus::Draft]);
    $content = (fn (): array => $this->getPage()['content'])->call(alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms'));
    $bar = collect(\AlfaSix\AlfakitCms\Tests\flattenSchema($content))->firstWhere('type', 'CmsLivePreview');

    expect($bar['status'])->toMatchArray(['label' => 'Draft', 'description' => 'Not visible to visitors yet.'])
        ->and(collect($bar['actions'])->pluck('name')->all())->toBe(['save', 'publish'])
        ->and($bar['actions'][0])->toMatchArray(['label' => 'Save draft', 'formId' => 'form']);

    $page->update(['status' => PageStatus::Published]);
    $content = (fn (): array => $this->getPage()['content'])->call(alfakit()->testPage(EditPage::class, ['record' => $page->refresh()], panel: 'cms'));
    $bar = collect(\AlfaSix\AlfakitCms\Tests\flattenSchema($content))->firstWhere('type', 'CmsLivePreview');

    expect($bar['status']['description'])->toBe('Visitors see this version.')
        ->and($bar['changedStatus']['description'])->toBe('Visitors still see the published version until you publish your changes.');
});

it('records the signed-in user as the author of a revision', function (): void {
    $user = cmsUser('Rev Author');
    actingAs($user);
    $page = cmsPage('Authored');

    expect($page->revisions()->first()?->getAuthorName())->toBe('Rev Author');
});

it('labels the bar\'s save button "Save changes" for published pages when draftChanges() is off', function (): void {
    actingAs(cmsUser());
    $bar = function (Page $page): array {
        $content = (fn (): array => $this->getPage()['content'])->call(alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms'));

        return collect(\AlfaSix\AlfakitCms\Tests\flattenSchema($content))->firstWhere('type', 'CmsLivePreview');
    };
    $live = cmsPage('Live now');
    $draft = cmsPage('Not yet', ['status' => PageStatus::Draft]);

    expect($bar($live)['actions'][0]['label'])->toBe('Save draft');

    cmsPlugin()->draftChanges(false);

    expect($bar($live->refresh())['actions'][0]['label'])->toBe('Save changes')
        ->and($bar($draft->refresh())['actions'][0]['label'])->toBe('Save draft');

    cmsPlugin()->draftChanges(true);
});
