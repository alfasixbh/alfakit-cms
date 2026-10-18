<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Http\Controllers;

use AlfaSix\AlfakitCms\Http\Responses\CmsPageResponse;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use AlfaSix\AlfakitCms\Support\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a page (or one of its revisions) whatever its status, behind a temporary
 * signed URL (`PreviewLinks::url()`). Answers are `noindex` and never cached.
 */
class PreviewController
{
    public function __invoke(Request $request, int $page): Response
    {
        $record = Page::query()->find($page);

        if (! $record instanceof Page) {
            abort(404);
        }

        $revisionNumber = $request->query('revision');

        if (is_string($revisionNumber) && ctype_digit($revisionNumber)) {
            $revision = PageRevision::query()->where('page_id', $record->getKey())->where('number', (int) $revisionNumber)->first();

            if (! $revision instanceof PageRevision) {
                abort(404);
            }

            // An unsaved copy with the revision's content (and the page's current place).
            $record = (clone $record)->forceFill(array_intersect_key($revision->data, array_flip(['title', 'content', 'seo', 'layout'])));
        } else {
            // The page with its saved (and autosaved) changes: what it will look like once published.
            $record = $record->withDraft();
        }

        $site = Site::forPage($record);
        $request->attributes->set(Site::REQUEST_ATTRIBUTE, $site);

        if ($record->locale !== null && $site->plugin->isMultilingual()) {
            app()->setLocale($record->locale);
        }

        return CmsPageResponse::make($record, $site, $request, preview: true);
    }
}
