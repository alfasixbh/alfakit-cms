<?php

declare(strict_types=1);

use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\Section;
use AlfaSix\AlfakitCms\Models\Template;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\CreatePage;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages\EditPage;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Support\SeoAdvisor;
use AlfaSix\AlfakitCms\Tests\Fixtures\Policies\PagePolicy;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitCms\Tests\cmsPage;
use function AlfaSix\AlfakitCms\Tests\cmsPlugin;
use function AlfaSix\AlfakitCms\Tests\cmsUser;
use function AlfaSix\AlfakitCms\Tests\inertiaGet;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitCms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * Call an exposed method of a page as the renderer does.
 *
 * @param  array<string, mixed>  $arguments
 */
function cmsCall(string $token, string $method, array $arguments): Illuminate\Testing\TestResponse
{
    return postJson('/cms/_alfakit/pages/call', ['token' => $token, 'method' => $method, 'arguments' => $arguments]);
}

afterEach(function (): void {
    cmsPlugin()->adminBar(false)->review(true)->templates([]);
});

it('keeps saved changes of a published page as a draft until they are published', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Live');

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->fillForm(['title' => 'Live v2'])
        ->call('save')
        ->assertHasNoFormErrors();

    $page->refresh();

    expect($page->title)->toBe('Live')
        ->and($page->getDisplayState())->toBe(PageState::ChangesPending)
        ->and($page->draft['title'])->toBe('Live v2');

    // Visitors see the live version, previews and the editor the draft.
    Inertia\Inertia::flushShared();
    inertiaGet('/live')->assertJsonPath('props.cms.page.title', 'Live');
    inertiaGet(PreviewLinks::url($page))->assertJsonPath('props.cms.page.title', 'Live v2');
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')->assertFormSet(['title' => 'Live v2']);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->callAction('discardChanges')
        ->assertHasNoActionErrors();

    expect($page->refresh()->hasPendingChanges())->toBeFalse()->and($page->title)->toBe('Live');
});

it('autosaves the editor state as a draft for the live preview', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Autosaved', ['status' => PageStatus::Draft]);
    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms');

    $preview = collect(AlfaSix\AlfakitCms\Tests\flattenSchema($editor->getPage()['content']))->firstWhere('type', 'CmsLivePreview');
    expect($preview)->not->toBeNull()
        ->and($preview['autosaveMethod'])->toBe('cmsAutosave')
        ->and($preview['previewUrl'])->toContain('/_cms/preview/'.$page->id);

    $state = $editor->getFormState();
    $state['title'] = 'Typed but not saved';

    cmsCall($editor->getToken(), 'cmsAutosave', ['state' => $state])
        ->assertOk()
        ->assertJsonPath('result.hasChanges', true);

    expect($page->refresh()->title)->toBe('Autosaved')
        ->and($page->draft['title'])->toBe('Typed but not saved');

    Inertia\Inertia::flushShared();
    inertiaGet(PreviewLinks::url($page))->assertJsonPath('props.cms.page.title', 'Typed but not saved');
});

it('refuses autosaves from users who cannot edit the page', function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    $page = cmsPage('Locked');
    actingAs(cmsUser('Viewer'));

    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms');

    expect($editor->getResponse()->status())->toBe(403);
});

it('runs the review flow: submit, request changes, approve', function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    $publisher = cmsUser('Publisher');
    $author = cmsUser('Author');
    cmsPlugin()->review(reviewers: fn (): array => [$publisher]);
    $page = cmsPage('For review', ['status' => PageStatus::Draft]);

    actingAs($author);
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionHidden('publish')
        ->callAction('submitForReview', ['note' => 'Ready, please check'])
        ->assertHasNoActionErrors();

    expect($page->refresh()->getReviewStatus())->toBe('pending')
        ->and($publisher->notifications()->count())->toBe(1);

    actingAs($publisher);
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertActionHidden('publish')
        ->callAction('requestChanges', ['note' => 'Shorter title'])
        ->assertHasNoActionErrors();

    expect($page->refresh()->getReviewStatus())->toBe('changes_requested')
        ->and($author->notifications()->count())->toBe(1);

    actingAs($author);
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')->callAction('submitForReview', ['note' => null]);

    actingAs($publisher);
    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->callAction('approve', ['publish_at' => null])
        ->assertHasNoActionErrors();

    expect($page->refresh()->isLive())->toBeTrue()
        ->and($page->getReviewStatus())->toBeNull()
        ->and($author->notifications()->count())->toBe(2);
});

it('starts pages from templates and saves pages as templates', function (): void {
    actingAs(cmsUser());
    cmsPlugin()->templates(['landing' => ['label' => 'Landing page', 'content' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]]]]);

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->fillForm(['template' => 'code:landing'])
        ->fillForm(['title' => 'Campaign'])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::query()->where('title', 'Campaign')->firstOrFail();
    expect($page->content[0]['data']['heading'])->toBe('Welcome');

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->callAction('saveAsTemplate', ['name' => 'Campaign layout'])
        ->assertHasNoActionErrors();

    $template = Template::query()->firstOrFail();
    expect($template->name)->toBe('Campaign layout')->and($template->content[0]['type'])->toBe('hero');

    alfakit()->testPage(CreatePage::class, panel: 'cms')
        ->fillForm(['template' => 'db:'.$template->id])
        ->assertFormSet(fn (array $state): array => ['content' => $state['content']]);
});

it('renders reusable sections, skips hidden blocks and tenants never share sections', function (): void {
    $section = Section::query()->create(['name' => 'Newsletter', 'content' => [['type' => 'call-to-action', 'data' => ['heading' => 'Join us']]]]);
    $foreign = Section::query()->create(['name' => 'Other', 'tenant_id' => AlfaSix\AlfakitCms\Tests\cmsTeam('Beta')->id, 'content' => [['type' => 'call-to-action', 'data' => ['heading' => 'Theirs']]]]);

    cmsPage('With section', ['content' => [
        ['type' => 'section', 'data' => ['section' => $section->id]],
        ['type' => 'section', 'data' => ['section' => $foreign->id]],
        ['type' => 'hero', 'data' => ['heading' => 'Secret', 'cms_hidden' => true]],
    ]]);

    $blocks = inertiaGet('/with-section')->assertOk()->json('props.cms.page.blocks');

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('section')
        ->and($blocks[0]['data']['blocks'][0]['data']['heading'])->toBe('Join us');
});

it('describes blocks in plain language in the block picker', function (): void {
    actingAs(cmsUser());

    $builder = collect(AlfaSix\AlfakitCms\Tests\flattenSchema(alfakit()->testPage(CreatePage::class, panel: 'cms')->getPage()['content']))->firstWhere('type', 'Builder');
    $hero = collect($builder['blocks'])->firstWhere('name', 'hero');

    expect($hero['description'])->toBe('A big heading at the top of the page, with an intro and buttons.')
        ->and($hero['icon'])->not->toBeNull()
        ->and(collect($builder['blocks'])->pluck('name'))->toContain('section');
});

it('gives SEO tips and a search preview from the form state', function (): void {
    $warnings = SeoAdvisor::warnings(str_repeat('Long title ', 8), ['description' => ''], [
        'a' => ['type' => 'image', 'data' => ['image' => 'cms/x.jpg', 'image_alt' => '']],
    ]);

    expect($warnings)->toHaveCount(3)
        ->and($warnings[0])->toContain('meta description')
        ->and((string) SeoAdvisor::snippet('<b>Hi</b>', ['description' => 'Desc'], 'https://example.com/hi'))->toContain('&lt;b&gt;Hi&lt;/b&gt;');
});

it('shows the admin bar on public pages to people who can edit them', function (): void {
    cmsPlugin()->adminBar();
    $page = cmsPage('Public');

    inertiaGet('/public')->assertJsonPath('props.cms.admin', null);

    actingAs(cmsUser());
    $admin = inertiaGet('/public')->json('props.cms.admin');

    expect($admin['editUrl'])->toBe('http://localhost/cms/cms/pages/'.$page->id.'/edit')
        ->and($admin['hasChanges'])->toBeFalse();

    Gate::policy(Page::class, PagePolicy::class);
    actingAs(cmsUser('Viewer'));
    inertiaGet('/public')->assertJsonPath('props.cms.admin', null);
});

it('explains in plain language what restoring a revision changes', function (): void {
    $changes = AlfaSix\AlfakitCms\Support\RevisionDiff::describe(
        ['title' => 'Welcome', 'slug' => 'home', 'content' => [['type' => 'hero', 'data' => ['heading' => 'A']], ['type' => 'faq', 'data' => []]]],
        ['title' => 'Hello', 'slug' => 'home', 'content' => [['type' => 'hero', 'data' => ['heading' => 'B']]]],
    );

    expect($changes)->toContain('The title changes from “Welcome” to “Hello”.')
        ->toContain('A “FAQ” block is removed.')
        ->toContain('One block has different content.');

    actingAs(cmsUser());
    $page = cmsPage('First');
    $page->update(['title' => 'Second']);

    alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->relationManager(AlfaSix\AlfakitCms\Resources\PageResource\RelationManagers\RevisionsRelationManager::class)
        ->mountTableAction('compareRevision', $page->revisions()->where('number', 1)->firstOrFail())
        ->assertMountedActionModalSee('The title changes from “Second” to “First”.');
});

it('shows compact block previews when turned on', function (): void {
    cmsPlugin()->blockPreviews();
    $block = cmsPlugin()->getBlockRegistry()->get('hero')?->toBuilderBlock();

    expect($block?->renderPreview(['heading' => '<b>Big</b> news', 'cms_hidden' => true]))
        ->toContain('&lt;b&gt;Big&lt;/b&gt; news')
        ->toContain('hidden');

    cmsPlugin()->blockPreviews(false);
});
