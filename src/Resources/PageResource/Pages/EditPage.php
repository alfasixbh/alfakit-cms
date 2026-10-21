<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\PageResource\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Pages\Attributes\Expose;
use AlfaSix\Alfakit\Realtime\RecordVersion;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Actions\ApproveAction;
use AlfaSix\AlfakitCms\Actions\DiscardChangesAction;
use AlfaSix\AlfakitCms\Actions\PreviewAction;
use AlfaSix\AlfakitCms\Actions\PublishAction;
use AlfaSix\AlfakitCms\Actions\RequestChangesAction;
use AlfaSix\AlfakitCms\Actions\SaveAsTemplateAction;
use AlfaSix\AlfakitCms\Actions\SubmitForReviewAction;
use AlfaSix\AlfakitCms\Actions\UnpublishAction;
use AlfaSix\AlfakitCms\Actions\ViewLiveAction;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Forms\Components\LivePreview;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Resources\PageResource;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PageDrafts;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The page editor. On a published page, saving keeps the changes as a draft (visitors
 * still see the live version) until they are published; the editor and previews show
 * the page with them. The form is autosaved as a draft too (`cmsAutosave`).
 */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    public static function getResource(): string
    {
        return CmsPlugin::get()->getPageResource();
    }

    /**
     * Everything that isn't a main action, in one menu. The main actions sit in the
     * editor bar at the top of the form (`getEditorActions()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                PreviewAction::make()->label(__('alfakit-cms::cms.actions.preview_new_tab'))->icon('external-link'),
                ViewLiveAction::make(),
                DiscardChangesAction::make(),
                SaveAsTemplateAction::make(),
                UnpublishAction::make(),
                DeleteAction::make(),
            ]),
        ];
    }

    /**
     * The editor bar's buttons: the review steps when they apply, "Save draft" (checks
     * and saves the form; visitors don't see it until it is published) and "Publish" /
     * "Publish changes".
     *
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        $actions = [
            ApproveAction::make(),
            RequestChangesAction::make(),
            SubmitForReviewAction::make(),
            $this->getSaveFormAction()->formId('form'),
            PublishAction::make(),
        ];

        // Bound like header actions: the page and its record.
        foreach ($actions as $action) {
            $action->context($this);

            if (($record = $this->getRecord()) !== null) {
                $action->record($record);
            }
        }

        return $actions;
    }

    /**
     * The bar at the top of the form holds the buttons; none below the form.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * The editor bar (`LivePreview`) gets the main actions.
     */
    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof LivePreview) {
                $component->actions(fn (): array => $this->getEditorActions());
            }
        }

        return $schema;
    }

    /**
     * "Save draft": the page stays as it is for visitors until it is published. With
     * `draftChanges(false)` saving a published page makes it live at once, so the button
     * keeps the panel's own "Save changes" label.
     */
    protected function getSaveFormAction(): Action
    {
        $action = parent::getSaveFormAction()
            ->color(Color::Gray)
            ->outlined();
        $record = $this->getRecord();

        if ($record instanceof Page && $record->isLive() && ! CmsPlugin::get()->keepsChangesAsDraft()) {
            return $action;
        }

        return $action->label(__('alfakit-cms::cms.actions.save_draft'));
    }

    /**
     * The form shows the page with its pending changes.
     */
    protected function fillFormState(Schema $form): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Page || ! $record->hasPendingChanges()) {
            parent::fillFormState($form);

            return;
        }

        $form->record($record->withDraft());
        parent::fillFormState($form);
        $form->record($record);
    }

    /**
     * Without the `publish` ability the status and schedule stay as they are, whatever
     * the request sends.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if ($record === null || ! CmsAuthorization::allows('publish', $record)) {
            unset($data['status'], $data['publish_at']);
        }

        if (! CmsPlugin::get()->isMultilingual()) {
            unset($data['locale'], $data['translation_of_id']);
        }

        return parent::mutateFormDataBeforeSave($data);
    }

    /**
     * Published pages keep content changes as a draft; other pages save them directly
     * (and drop any autosaved draft).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Page) {
            return parent::handleRecordUpdate($record, $data);
        }

        if (PageDrafts::keepsChangesAsDraft($record)) {
            $settings = array_diff_key($data, array_flip(Page::REVISIONED));

            if ($settings !== []) {
                $record->fill($settings);
            }

            return PageDrafts::store($record, $data);
        }

        $record->fill($data);
        $record->draft = null;
        $record->draft_saved_at = null;
        $record->save();

        return $record;
    }

    /**
     * The live version (without the pending changes) as this form saves it, so an autosave
     * of the live values (after "Discard changes", or edits typed back) leaves no draft
     * although the form fills in defaults the stored content leaves out.
     *
     * @return array<string, mixed>
     */
    protected function getLiveFormState(Page $record): array
    {
        $draft = $record->draft;
        $form = $this->cachedForm;
        $record->draft = null;
        $this->cachedForm = null;

        try {
            return $this->getForm()?->getState() ?? [];
        } finally {
            $record->draft = $draft;
            $this->cachedForm = $form;
        }
    }

    /**
     * Autosave the editor's current (unvalidated) state as the page's draft, for the live
     * preview and so nothing typed is lost. Live content never changes here.
     *
     * The draft is a write of its own: when the editor's loaded version was still current
     * (`$recordVersion`), the new version is returned so the next save is not refused as a
     * conflict with the autosave. Someone else's change in between still is.
     *
     * @param  array<string, mixed>  $state
     * @return array{savedAt: string|null, hasChanges: bool, previewUrl: string|null, recordVersion: string|null}
     */
    #[Expose(rateLimit: 120)]
    public function cmsAutosave(array $state = [], ?string $recordVersion = null): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Page || ! CmsAuthorization::allows('update', $record)) {
            abort(403);
        }

        $form = $this->getForm();

        if ($form === null) {
            abort(404);
        }

        $current = $recordVersion !== null && $recordVersion !== '' && RecordVersion::matches($record, $recordVersion);

        $form->applyClientState($state);
        PageDrafts::store($record, $form->getState(), $this->getLiveFormState($record));

        return [
            'savedAt' => $record->draft_saved_at?->toAtomString() ?? now()->toAtomString(),
            'hasChanges' => $record->hasPendingChanges(),
            'previewUrl' => Route::has(PreviewLinks::ROUTE) ? PreviewLinks::url($record) : null,
            'recordVersion' => $current ? RecordVersion::of($record->fresh() ?? $record) : null,
        ];
    }
}
