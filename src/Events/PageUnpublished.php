<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Events;

use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A page was turned back into a draft from the panel.
 */
class PageUnpublished
{
    use Dispatchable;

    public function __construct(public readonly Page $page) {}
}
