<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Events;

use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A revision was copied back onto its page.
 */
class PageRevisionRestored
{
    use Dispatchable;

    public function __construct(public readonly Page $page, public readonly PageRevision $revision) {}
}
