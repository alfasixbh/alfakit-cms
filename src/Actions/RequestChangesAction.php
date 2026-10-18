<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PageReviews;
use Illuminate\Database\Eloquent\Model;

/**
 * Send a page under review back to its author with a note.
 */
class RequestChangesAction extends Action
{
    use RefreshesPageEditor;

    public static function make(string $name = 'requestChanges'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.review.request_changes'));
        $this->icon('message-square-warning');
        $this->color(Color::Warning);
        $this->modalHeading(__('alfakit-cms::cms.review.request_changes_heading'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.review.request_changes'));
        $this->modalWidth('md');
        $this->schema([
            Textarea::make('note')->label(__('alfakit-cms::cms.review.what_to_change'))->required()->rows(4)->maxLength(1000),
        ]);
        $this->successNotificationTitle(__('alfakit-cms::cms.review.notifications.changes_requested'));
        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('publish', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && $record->getReviewStatus() === 'pending');
        $this->action(static fn (Page $record, array $data): Page => PageReviews::requestChanges($record, is_string($data['note'] ?? null) ? $data['note'] : null));
        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
