<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Publisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Publish a page now, or schedule it for a later time (the `publish` ability).
 */
class PublishAction extends Action
{
    use RefreshesPageEditor;

    public static function make(string $name = 'publish'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(static fn (mixed $record): string => $record instanceof Page && $record->getState() !== PageState::Draft && $record->hasPendingChanges() ? __('alfakit-cms::cms.actions.publish_changes') : __('alfakit-cms::cms.actions.publish'));
        $this->icon('send');
        $this->color(Color::Success);
        $this->modalHeading(__('alfakit-cms::cms.actions.publish_heading'));
        $this->modalDescription(__('alfakit-cms::cms.actions.publish_description'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.actions.publish'));
        $this->modalWidth('md');
        $this->schema([
            DateTimePicker::make('publish_at')
                ->label(__('alfakit-cms::cms.fields.publish_at'))
                ->helperText(__('alfakit-cms::cms.fields.publish_at_help'))
                ->seconds(false)
                ->minDate(now()->startOfMinute()),
        ]);

        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('publish', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => $record instanceof Page
            && ($record->getState() !== PageState::Published || $record->hasPendingChanges())
            && $record->getReviewStatus() !== 'pending');

        $this->action(function (Page $record, array $data): void {
            Publisher::publish($record, is_string($data['publish_at'] ?? null) ? $data['publish_at'] : null);

            $this->successNotificationTitle($record->getState() === PageState::Scheduled
                ? __('alfakit-cms::cms.notifications.scheduled', ['date' => Carbon::parse($record->publish_at)->toDayDateTimeString()])
                : __('alfakit-cms::cms.notifications.published'));
        });

        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
