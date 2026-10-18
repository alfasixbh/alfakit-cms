<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models\Concerns;

use AlfaSix\AlfakitCms\CmsPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `tenant_id` column of CMS records: the `tenant()` relationship the CMS resources
 * scope by on tenancy panels, and `forTenant()` for public queries.
 *
 * @mixin Model
 */
trait BelongsToCmsTenant
{
    /**
     * @return BelongsTo<Model, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(static::getCmsTenantModel(), 'tenant_id');
    }

    /**
     * Records of this tenant only; null means records without a tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): Builder
    {
        $column = $query->qualifyColumn('tenant_id');

        return $tenant === null ? $query->whereNull($column) : $query->where($column, $tenant->getKey());
    }

    /**
     * The tenant model of the panel serving the CMS (the current panel, else the first
     * tenancy panel with the plugin).
     *
     * @return class-string<Model>
     */
    public static function getCmsTenantModel(): string
    {
        $panel = alfakit()->getCurrentPanel();

        if ($panel?->getTenantModel() === null) {
            foreach (alfakit()->getPanels() as $candidate) {
                if ($candidate->hasPlugin(CmsPlugin::ID) && $candidate->getTenantModel() !== null) {
                    $panel = $candidate;

                    break;
                }
            }
        }

        return $panel?->getTenantModel() ?? static::class;
    }
}
