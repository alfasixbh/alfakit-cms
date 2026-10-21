<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Publisher;
use Illuminate\Database\Eloquent\Model;

/**
 * Throw away changes that aren't published yet; visitors keep the live version.
 */
class DiscardChangesAction extends Action
{
    use RefreshesPageEditor;

    public static function make(string $name = 'discardChanges'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.discard_changes'));
        $this->icon('undo-2');
        $this->color(Color::Danger);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit-cms::cms.actions.discard_changes_heading'));
        $this->modalDescription(__('alfakit-cms::cms.actions.discard_changes_description'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.actions.discard_changes'));
        $this->successNotificationTitle(__('alfakit-cms::cms.notifications.discarded'));
        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('update', $record ?? Page::class));
        // On every published page, not only those with a draft when the editor loaded: an
        // autosave makes one without reloading the page (and its header actions).
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && ($record->hasPendingChanges() || $record->getState() !== PageState::Draft));
        $this->action(function (Page $record): Page {
            if (! $record->hasPendingChanges()) {
                Notification::make()->title(__('alfakit-cms::cms.notifications.nothing_to_discard'))->info()->send();

                $this->cancel();
            }

            return Publisher::discardChanges($record);
        });
        $this->refreshesPageEditor();
        $this->after(function (Page $record): void {
            $this->resetEditorForm($record);
            $this->sendSuccessNotification();
        });
    }

    /**
     * The editor's form still holds the discarded edits (it counts as edited, so the
     * reload keeps them) and its next autosave would store them as a draft again: give it
     * the live values instead (`refreshFormData()`).
     */
    protected function resetEditorForm(Page $record): void
    {
        $context = $this->getContext();

        if (! $context instanceof EditRecord || ! ($editing = $context->getRecord()) instanceof Page || ! $editing->is($record)) {
            return;
        }

        $editing->refresh();
        $context->refreshFormData(Page::REVISIONED);
    }
}
