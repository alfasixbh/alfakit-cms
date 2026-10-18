<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A page template: blocks a new page can start from ("Start from a template").
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string|null $description
 * @property array<int, array<string, mixed>>|null $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Template extends Model
{
    use BelongsToCmsTenant;

    protected $table = 'alfakit_cms_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
