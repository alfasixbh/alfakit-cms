<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Support\Site;

/**
 * Open the live page on the public site (shown for published pages when the public
 * routes are on).
 */
class ViewLiveAction extends Action
{
    public static function make(string $name = 'viewLive'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.view_live'));
        $this->icon('external-link');
        $this->color(Color::Gray);
        $this->visible(static fn (mixed $record): bool => $record instanceof Page && $record->isLive() && Site::forPage($record)->plugin->hasPublicRoutes());
        $this->url(static fn (mixed $record): ?string => $record instanceof Page ? Site::forPage($record)->pageUrl($record) : null, shouldOpenInNewTab: true);
    }
}
