<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Events;

use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reviewer approved a page (it was published) or asked for changes.
 */
class PageReviewed
{
    use Dispatchable;

    public function __construct(public readonly Page $page, public readonly bool $approved, public readonly ?string $note) {}
}
