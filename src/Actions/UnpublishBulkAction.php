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
 * Turn the selected pages back into drafts (each one checked against `publish`).
 */
class UnpublishBulkAction extends BulkAction
{
    public static function make(string $name = 'unpublish'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.unpublish'));
        $this->icon('eye-off');
        $this->color(Color::Warning);
        $this->requiresConfirmation();
        $this->modalHeading(__('alfakit-cms::cms.actions.unpublish_bulk_heading'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.actions.unpublish'));
        $this->successNotificationTitle(__('alfakit-cms::cms.notifications.unpublished_bulk'));
        $this->authorize(static fn (): bool => CmsAuthorization::allows('publish', Page::class));
        $this->authorizeIndividualRecords('publish');

        $this->action(static function (Enumerable $records): void {
            $records->each(static fn (mixed $record) => $record instanceof Page ? Publisher::unpublish($record) : null);
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }
}
