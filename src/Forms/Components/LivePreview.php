<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Forms\Components;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasEmbeddedActions;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Resources\PageResource;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use Illuminate\Support\Facades\Route;

/**
 * The page editor's bar (`CmsLivePreview` in the renderers), sticky at the top of the
 * form: the page's status in plain words, the autosave indicator, the live preview
 * toggle and the editor's main actions (`actions()`, set by `EditPage`: "Save draft",
 * "Publish"…). The form's changes are autosaved as the page's draft a moment after the
 * editor stops typing (the editor page's `cmsAutosave` method), and the preview pane
 * reloads the signed preview of that draft. The pane opens beside the form (on its own
 * on wide screens), full screen, or at desktop, tablet and phone widths.
 */
class LivePreview extends Component
{
    use HasEmbeddedActions;

    public const AUTOSAVE_METHOD = 'cmsAutosave';

    final public function __construct() {}

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function getType(): string
    {
        return 'CmsLivePreview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key('cms-live-preview');
        $this->hiddenLabel();
        $this->columnSpanFull();
        $this->visible(static fn (mixed $record): bool => $record instanceof Page && $record->exists);
    }

    protected function getSchemaProps(): array
    {
        $record = $this->getRecord();
        $plugin = CmsPlugin::get();
        $page = $record instanceof Page ? $record : null;

        return [
            'previewUrl' => $page !== null && $plugin->hasLivePreview() && Route::has(PreviewLinks::ROUTE) ? PreviewLinks::url($page) : null,
            'autosaveMethod' => self::AUTOSAVE_METHOD,
            'debounce' => $plugin->getAutosaveDebounce(),
            'savedAt' => $page?->draft_saved_at?->toAtomString(),
            'hasChanges' => $page?->hasPendingChanges() ?? false,
            'status' => PageResource::describeEditorStatus($page),
            'changedStatus' => $page !== null && $page->getState() !== PageState::Draft
                ? PageResource::describeEditorStatus($page, changed: true)
                : null,
            'actions' => $this->serializeEmbeddedActions() ?: null,
        ];
    }
}
