<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A navigation menu: nested items (`label`, `type` page|url, `page_id`, `url`, `new_tab`,
 * `children`) stored as JSON, found by its handle (`main`, `footer`).
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $handle
 * @property string|null $locale
 * @property array<int, array<string, mixed>>|null $items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Menu extends Model
{
    use BelongsToCmsTenant;

    protected $table = 'alfakit_cms_menus';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }
}
