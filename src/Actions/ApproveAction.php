<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PageReviews;
use Illuminate\Database\Eloquent\Model;

/**
 * Approve a page submitted for review: it is published (now or at the time picked) and
 * the author is told.
 */
class ApproveAction extends Action
{
    use RefreshesPageEditor;

    public static function make(string $name = 'approve'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.review.approve'));
        $this->icon('check');
        $this->color(Color::Success);
        $this->modalHeading(__('alfakit-cms::cms.review.approve_heading'));
        $this->modalDescription(static fn (mixed $record): string => $record instanceof Page && is_string($record->review['note'] ?? null) ? '“'.$record->review['note'].'”' : __('alfakit-cms::cms.review.approve_description'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.review.approve'));
        $this->modalWidth('md');
        $this->schema([
            DateTimePicker::make('publish_at')->label(__('alfakit-cms::cms.fields.publish_at'))->helperText(__('alfakit-cms::cms.fields.publish_at_help'))->seconds(false),
        ]);
        $this->successNotificationTitle(__('alfakit-cms::cms.review.notifications.approved'));
        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('publish', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && $record->getReviewStatus() === 'pending');
        $this->action(static fn (Page $record, array $data): Page => PageReviews::approve($record, is_string($data['publish_at'] ?? null) ? $data['publish_at'] : null));
        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
