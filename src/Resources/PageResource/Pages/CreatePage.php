<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\PageResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Resources\PageResource;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    public static function getResource(): string
    {
        return CmsPlugin::get()->getPageResource();
    }

    /**
     * Users without the `publish` ability always create drafts.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! CmsAuthorization::allows('publish', Page::class)) {
            $data['status'] = PageStatus::Draft->value;
            $data['publish_at'] = null;
        }

        if (! CmsPlugin::get()->isMultilingual()) {
            $data['locale'] = null;
            $data['translation_of_id'] = null;
        }

        return parent::mutateFormDataBeforeCreate($data);
    }
}
