<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use AlfaSix\AlfakitCms\Support\Redirects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A redirect from a path (`/old`, or a prefix ending in `*`) to a path or URL.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property string $from_path
 * @property string $to_url
 * @property int $status_code
 * @property bool $is_active
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Redirect extends Model
{
    use BelongsToCmsTenant;

    public const STATUS_CODES = [301, 302, 307, 308];

    protected $table = 'alfakit_cms_redirects';

    protected $guarded = ['id'];

    protected $attributes = [
        'status_code' => 301,
        'is_active' => true,
        'hits' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(static function (Redirect $redirect): void {
            $redirect->from_path = Redirects::normalizePath($redirect->from_path);
            $redirect->to_url = trim($redirect->to_url);
        });

        static::saved(static fn (Redirect $redirect) => Redirects::flush($redirect->tenant_id));
        static::deleted(static fn (Redirect $redirect) => Redirects::flush($redirect->tenant_id));
    }

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function isWildcard(): bool
    {
        return str_ends_with($this->from_path, '*');
    }
}
