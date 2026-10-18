<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PageReviews;
use Illuminate\Database\Eloquent\Model;

/**
 * Ask a reviewer to publish the page (shown to people who can edit but not publish).
 */
class SubmitForReviewAction extends Action
{
    use RefreshesPageEditor;

    public static function make(string $name = 'submitForReview'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.review.submit'));
        $this->icon('send');
        $this->color(Color::Primary);
        $this->modalHeading(__('alfakit-cms::cms.review.submit_heading'));
        $this->modalDescription(__('alfakit-cms::cms.review.submit_description'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.review.submit'));
        $this->modalWidth('md');
        $this->schema([
            Textarea::make('note')->label(__('alfakit-cms::cms.review.note'))->helperText(__('alfakit-cms::cms.review.note_help'))->rows(3)->maxLength(1000),
        ]);
        $this->successNotificationTitle(__('alfakit-cms::cms.review.notifications.submitted'));
        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('submitForReview', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => CmsPlugin::get()->hasReview()
            && $record instanceof Page
            && $record->getReviewStatus() !== 'pending'
            && ! CmsAuthorization::allows('publish', $record));
        $this->action(static fn (Page $record, array $data): Page => PageReviews::submit($record, is_string($data['note'] ?? null) ? $data['note'] : null));
        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
