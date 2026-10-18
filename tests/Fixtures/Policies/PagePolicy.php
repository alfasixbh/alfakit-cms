<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Tests\Fixtures\Policies;

use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Everyone may write pages; only users named "Publisher" may publish.
 */
class PagePolicy
{
    public function viewAny(Model $user): bool
    {
        return true;
    }

    public function view(Model $user, Page $page): bool
    {
        return true;
    }

    public function create(Model $user): bool
    {
        return true;
    }

    public function update(Model $user, Page $page): bool
    {
        return $user->getAttribute('name') !== 'Viewer';
    }

    public function delete(Model $user, Page $page): bool
    {
        return true;
    }

    public function publish(Model $user, Page|string|null $page = null): bool
    {
        return $user->getAttribute('name') === 'Publisher';
    }
}
