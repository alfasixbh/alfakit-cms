<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Tests\Fixtures\Blocks\PricingBlock;

class CmsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cms')
            ->path('cms')
            ->login()
            ->middleware(['web'])
            ->plugin(
                CmsPlugin::make()
                    ->siteName('Acme')
                    ->blocks([PricingBlock::class])
                    ->formOptions(fn (): array => ['contact' => 'Contact form'])
                    ->formUsing(fn (string $form): array => ['key' => $form, 'action' => '/forms/'.$form])
                    ->publicRoutes()
                    ->api(middleware: []),
            );
    }
}
