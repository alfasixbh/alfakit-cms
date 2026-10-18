<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Publisher;
use Illuminate\Support\Enumerable;

/**
 * Publish the selected pages now (each one checked against the `publish` ability).
 */
class PublishBulkAction extends BulkAction
{
    public static function make(string $name = 'publish'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.publish'));
        $this->icon('send');
        $this->color(Color::Success);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit-cms::cms.actions.publish_bulk_heading'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.actions.publish'));
        $this->successNotificationTitle(__('alfakit-cms::cms.notifications.published_bulk'));
        $this->authorize(static fn (): bool => CmsAuthorization::allows('publish', Page::class));
        $this->authorizeIndividualRecords('publish');

        $this->action(static function (Enumerable $records): void {
            $records->each(static fn (mixed $record) => $record instanceof Page ? Publisher::publish($record) : null);
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
