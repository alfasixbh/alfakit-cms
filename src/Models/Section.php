<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable section: blocks edited once and shown on many pages through the "Reusable
 * section" block. Changing it changes every page that uses it.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property array<int, array<string, mixed>>|null $content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Section extends Model
{
    use BelongsToCmsTenant;

    protected $table = 'alfakit_cms_sections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
