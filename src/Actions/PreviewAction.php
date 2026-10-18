<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Open a signed preview link of the page, as visitors would see it, in a new tab.
 */
class PreviewAction extends Action
{
    public static function make(string $name = 'preview'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.preview'));
        $this->icon('eye');
        $this->color(Color::Gray);
        $this->authorize(static fn (?Model $record): bool => CmsAuthorization::allows('view', $record ?? Page::class));
        $this->visible(static fn (?Model $record): bool => $record instanceof Page && $record->exists && Route::has(PreviewLinks::ROUTE));
        $this->url(static fn (?Model $record): ?string => $record instanceof Page && Route::has(PreviewLinks::ROUTE) ? PreviewLinks::url($record) : null, shouldOpenInNewTab: true);
    }
}
