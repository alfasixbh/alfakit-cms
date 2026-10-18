<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed links that render a page (or one of its revisions) as visitors would
 * see it, whatever its status. Anyone holding the link can open it until it expires
 * (`CmsPlugin::previewLinkLifetime()`), so share it with care.
 */
final class PreviewLinks
{
    public const ROUTE = 'alfakit-cms.preview';

    public static function url(Page $page, ?PageRevision $revision = null): string
    {
        $parameters = ['page' => $page->getKey()];

        if ($revision !== null && (int) $revision->page_id === (int) $page->getKey()) {
            $parameters['revision'] = $revision->number;
        }

        return URL::temporarySignedRoute(self::ROUTE, now()->addMinutes(CmsPlugin::get()->getPreviewLinkLifetime()), $parameters);
    }
}
