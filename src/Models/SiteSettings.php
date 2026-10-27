<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The design and custom code of one site (one row per tenant): `design` holds the
 * colours, fonts, corner radius, logo and favicon; `code` the custom CSS and the head and
 * body snippets; `code_history` the earlier versions of `code`, newest first.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property array<string, mixed>|null $design
 * @property array<string, mixed>|null $code
 * @property array<int, array<string, mixed>>|null $code_history
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteSettings extends Model
{
    use BelongsToCmsTenant;

    /** Earlier versions of the custom code kept for restoring. */
    public const HISTORY_LIMIT = 20;

    protected $table = 'alfakit_cms_site_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'code' => 'array',
            'code_history' => 'array',
        ];
    }

    /**
     * The settings of a tenant's site (null without tenancy), new and unsaved when there are none.
     */
    public static function forTenant(?Model $tenant): self
    {
        $settings = static::query()->forTenant($tenant)->first();

        if ($settings instanceof self) {
            return $settings;
        }

        $settings = new self;
        $settings->tenant_id = $tenant?->getKey();

        return $settings;
    }
}
