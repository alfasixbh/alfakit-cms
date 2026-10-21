<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Realtime\RecordVersion;
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

it('offers "Discard changes" in the menu of a published page before an autosave makes a draft', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Live menu');
    // The editor loaded with no draft: the menu (header actions) is not sent again after an autosave.
    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms');

    expect(json_encode($editor->getPage()['headerActions']))->toContain('"name":"discardChanges"');

    // Nothing to discard yet: a notice, the live page stays as it is.
    $editor->callAction('discardChanges')
        ->assertHasNoActionErrors()
        ->assertNotified(__('alfakit-cms::cms.notifications.nothing_to_discard'))
        ->assertNotNotified(__('alfakit-cms::cms.notifications.discarded'));
    expect($page->refresh()->title)->toBe('Live menu');

    // An autosave then makes a draft; the same menu item discards it.
    $page->forceFill(['draft' => ['title' => 'Live menu v2']])->save();
    $editor->callAction('discardChanges')->assertHasNoActionErrors();

    expect($page->refresh()->hasPendingChanges())->toBeFalse()
        ->and(json_encode(alfakit()->testPage(EditPage::class, ['record' => cmsPage('Draft only', ['status' => PageStatus::Draft])], panel: 'cms')->getPage()['headerActions']))->not->toContain('"name":"discardChanges"');
});

it('resets the editor form to the live page after "Discard changes", so the next autosave does not bring the draft back', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Live title');
    $page->forceFill(['draft' => ['title' => 'Draft title'], 'draft_saved_at' => now()])->save();
    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms');

    $editor->assertFormSet(['title' => 'Draft title']);

    test()->post('/cms/_alfakit/actions', ['token' => $editor->getToken(), 'component' => 'page', 'action' => 'discardChanges'], AlfaSix\Alfakit\Testing\PageTester::inertiaHeaders())
        ->assertRedirect();

    // The form in the browser holds the edits (it is dirty): the action tells it to take
    // the live values instead of keeping them.
    $refresh = session(Inertia\Support\SessionKey::FLASH_DATA)['alfakitFormRefresh'] ?? null;

    expect($refresh)->toBeArray()
        ->and($refresh['component'])->toBe('form')
        ->and($refresh['state']['title'] ?? null)->toBe('Live title')
        ->and($refresh['state'])->toHaveKey('content');

    // The autosave that follows sends the live values: no draft.
    $state = $editor->getFormState();
    $state = [...$state, ...$refresh['state']];

    cmsCall($editor->getToken(), 'cmsAutosave', ['state' => $state])
        ->assertOk()
        ->assertJsonPath('result.hasChanges', false);

    expect($page->refresh()->hasPendingChanges())->toBeFalse();

    // A real edit still makes a draft.
    cmsCall($editor->getToken(), 'cmsAutosave', ['state' => [...$state, 'title' => 'Edited again']])
        ->assertJsonPath('result.hasChanges', true);
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

it('offers "Publish changes" in the editor bar as soon as an autosave makes a published page differ', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Live');
    $bar = static fn (): array => collect(AlfaSix\AlfakitCms\Tests\flattenSchema(alfakit()->testPage(EditPage::class, ['record' => $page->refresh()], panel: 'cms')->getPage()['content']))->firstWhere('type', 'CmsLivePreview');
    $names = static fn (?array $actions): array => array_column(AlfaSix\AlfakitCms\Tests\flattenSchema($actions ?? []), 'name');

    // Published, no draft: no publish button now, "Publish changes" once the draft differs.
    $preview = $bar();

    expect($names($preview['actions']))->not->toContain('publish')
        ->and($names($preview['changedActions']))->toContain('publish')
        ->and(collect($preview['changedActions'])->firstWhere('name', 'publish')['label'] ?? null)->toBe('Publish changes')
        // Evaluating them changed nothing.
        ->and($page->refresh()->hasPendingChanges())->toBeFalse();

    // With a draft the actions already have it; there is nothing to switch to.
    $page->forceFill(['draft' => ['title' => 'Live v2']])->save();

    expect($names($bar()['actions']))->toContain('publish')
        ->and($bar()['changedActions'] ?? null)->toBeNull();
});

it('returns the new record version after an autosave so the next save is not a conflict', function (): void {
    actingAs(cmsUser());
    $page = cmsPage('Versioned', ['status' => PageStatus::Draft]);
    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms');
    $loaded = RecordVersion::of($page->refresh());

    $state = $editor->getFormState();
    $state['title'] = 'Typed';

    $version = cmsCall($editor->getToken(), 'cmsAutosave', ['state' => $state, 'recordVersion' => $loaded])
        ->assertOk()
        ->json('result.recordVersion');

    expect($version)->toBe(RecordVersion::of($page->refresh()))->not->toBe($loaded);

    // Someone else's change in between: the editor keeps its old version and the save is refused.
    $state['title'] = 'Typed again';
    cmsCall($editor->getToken(), 'cmsAutosave', ['state' => $state, 'recordVersion' => $loaded])
        ->assertOk()
        ->assertJsonPath('result.recordVersion', null);
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

    // The browser only opens a modal for actions that have one: "Compare" used to do nothing.
    $compare = (new AlfaSix\AlfakitCms\Resources\PageResource\RelationManagers\RevisionsRelationManager($page, EditPage::class))
        ->table(AlfaSix\Alfakit\Tables\Table::make())
        ->getRecordActions();
    $action = collect($compare)->first(fn (mixed $action): bool => $action instanceof AlfaSix\Alfakit\Actions\Action && $action->getName() === 'compareRevision');

    expect($action?->hasModal())->toBeTrue();
});

it('shows compact block previews when turned on', function (): void {
    cmsPlugin()->blockPreviews();
    $block = cmsPlugin()->getBlockRegistry()->get('hero')?->toBuilderBlock();

    expect($block?->renderPreview(['heading' => '<b>Big</b> news', 'cms_hidden' => true]))
        ->toContain('&lt;b&gt;Big&lt;/b&gt; news')
        ->toContain('hidden');

    cmsPlugin()->blockPreviews(false);
});

it('reveals the translation link, layout and order with "Show advanced settings" and saves them', function (): void {
    actingAs(cmsUser());
    cmsPlugin()->layouts(['default' => 'Default', 'landing' => 'Landing'])->locales(['en' => 'English', 'ar' => 'العربية'])->draftChanges(false);
    $english = cmsPage('About', ['locale' => 'en']);
    $page = cmsPage('Arabic about', ['locale' => 'ar', 'slug' => 'arabic-about']);

    $editor = alfakit()->testPage(EditPage::class, ['record' => $page], panel: 'cms')
        ->assertFormFieldHidden('layout')
        ->assertFormFieldHidden('sort')
        ->assertFormFieldHidden('translation_of_id')
        ->fillForm(['advanced' => true])
        ->assertFormFieldVisible('layout')
        ->assertFormFieldVisible('sort')
        ->assertFormFieldVisible('translation_of_id');

    $editor->fillForm(['layout' => 'landing', 'sort' => 3, 'translation_of_id' => $english->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $page->refresh();
    expect($page->layout)->toBe('landing')
        ->and($page->sort)->toBe(3)
        ->and($page->translation_of_id)->toBe($english->id);

    // Without layouts() there is no layout field to pick.
    cmsPlugin()->layouts([])->locales([])->draftChanges(true);
    alfakit()->testPage(EditPage::class, ['record' => $english], panel: 'cms')
        ->fillForm(['advanced' => true])
        ->assertFormFieldHidden('layout')
        ->assertFormFieldHidden('translation_of_id')
        ->assertFormFieldVisible('sort');
});
