<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\MenuResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Resources\MenuResource;

class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;

    public static function getResource(): string
    {
        return CmsPlugin::get()->getMenuResource();
    }
}
