<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitCms\CmsPlugin;
use Illuminate\Http\Request;

class CmsTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cms-team')
            ->path('cms-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->plugin(
                CmsPlugin::make()
                    ->siteName('Teams')
                    ->resolveTenantUsing(fn (Request $request): ?Team => Team::query()->where('slug', $request->header('X-Team'))->first()),
            );
    }
}
