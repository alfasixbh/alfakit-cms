<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Events;

use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An author submitted a page for review.
 */
class PageSubmittedForReview
{
    use Dispatchable;

    public function __construct(public readonly Page $page) {}
}
