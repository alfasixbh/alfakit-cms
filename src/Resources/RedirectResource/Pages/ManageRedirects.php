<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\RedirectResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Resources\RedirectResource;

class ManageRedirects extends ListRecords
{
    protected static string $resource = RedirectResource::class;

    public static function getResource(): string
    {
        return CmsPlugin::get()->getRedirectResource();
    }

    protected function getHeaderActions(): array
    {
        return [
            static::getResource()::makeCreateAction(),
        ];
    }
}
