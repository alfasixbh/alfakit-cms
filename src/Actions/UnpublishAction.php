<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Publisher;
use Illuminate\Database\Eloquent\Model;

/**
 * Take a published or scheduled page back to a draft (the `publish` ability).
 */
class UnpublishAction extends Action
{
    use RefreshesPageEditor;

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
        $this->modalHeading(__('alfakit-cms::cms.actions.unpublish_heading'));
        $this->modalDescription(__('alfakit-cms::cms.actions.unpublish_description'));
        $this->modalSubmitActionLabel(__('alfakit-cms::cms.actions.unpublish'));
        $this->successNotificationTitle(__('alfakit-cms::cms.notifications.unpublished'));

        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('publish', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && $record->getState() !== PageState::Draft);

        $this->action(static fn (Page $record): Page => Publisher::unpublish($record));

        $this->refreshesPageEditor();
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
