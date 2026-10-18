<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Page;

/**
 * Pending changes of a page: the editor's saves of a published page (and every
 * autosave) are kept in `draft` until they are published, so visitors keep seeing the
 * live version while editors work. Previews and the editor show the page with them.
 */
final class PageDrafts
{
    /**
     * Whether saving the page keeps its changes as a draft instead of making them live.
     */
    public static function keepsChangesAsDraft(Page $page): bool
    {
        return CmsPlugin::get()->keepsChangesAsDraft() && $page->exists && $page->getOriginal('status') === PageStatus::Published;
    }

    /**
     * Store the revisioned attributes of `$data` as the page's pending changes; the draft
     * is cleared when they equal the live version.
     *
     * @param  array<string, mixed>  $data
     */
    public static function store(Page $page, array $data): Page
    {
        $changes = array_intersect_key($data, array_flip(Page::REVISIONED));

        if (array_key_exists('slug', $changes)) {
            $changes['slug'] = Page::normalizeSlug(is_string($changes['slug']) ? $changes['slug'] : null);
        }

        $live = PageRevisions::snapshot($page);
        $differs = false;

        foreach ($changes as $attribute => $value) {
            if (json_encode($value) !== json_encode($live[$attribute] ?? null)) {
                $differs = true;

                break;
            }
        }

        $page->draft = $differs ? [...$live, ...$changes] : null;
        $page->draft_saved_at = $differs ? now() : null;
        $page->withoutRevision = true;
        $page->save();

        return $page;
    }
}
