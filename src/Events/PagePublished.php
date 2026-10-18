<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Events;

use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A page was published from the panel (now, or scheduled for `$page->publish_at`).
 */
class PagePublished
{
    use Dispatchable;

    public function __construct(public readonly Page $page) {}
}
