<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\Color;
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
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && $record->hasPendingChanges());
        $this->action(static fn (Page $record): Page => Publisher::discardChanges($record));
        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
