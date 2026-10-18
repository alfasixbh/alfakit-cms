<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Versions of a page: every save that changes a revisioned attribute (`Page::REVISIONED`)
 * records the saved state, and restoring copies a version back (recorded as a new one).
 * Publishing state is not part of a revision, so restoring never publishes or unpublishes.
 */
final class PageRevisions
{
    public static function recordIfChanged(Page $page, bool $created = false): void
    {
        $skip = $page->withoutRevision;
        $page->withoutRevision = false;

        if ($skip) {
            return;
        }

        if (! $created && ! $page->wasChanged(Page::REVISIONED)) {
            return;
        }

        self::record($page, $page->revisionNote);
        $page->revisionNote = null;
    }

    public static function record(Page $page, ?string $note = null): PageRevision
    {
        $number = ((int) PageRevision::query()->where('page_id', $page->getKey())->max('number')) + 1;
        $author = alfakit()->auth() ?? auth()->user();

        $revision = new PageRevision([
            'page_id' => $page->getKey(),
            'number' => $number,
            'data' => self::snapshot($page),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
        ]);

        if ($author instanceof Model) {
            $revision->author()->associate($author);
        }

        $revision->save();

        self::prune($page);

        return $revision;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Page $page): array
    {
        $data = [];

        foreach (Page::REVISIONED as $attribute) {
            $data[$attribute] = $page->getAttribute($attribute);
        }

        return $data;
    }

    /**
     * Copy a revision back onto its page. A parent that no longer exists (or would create
     * a loop) is dropped, and a path taken by another page keeps the current slug.
     */
    public static function restore(PageRevision $revision): Page
    {
        $page = $revision->page;

        if (! $page instanceof Page) {
            abort(404);
        }

        $data = array_intersect_key($revision->data, array_flip(Page::REVISIONED));
        $current = ['slug' => $page->slug, 'parent_id' => $page->parent_id, 'is_home' => $page->is_home];

        $parent = $data['parent_id'] ?? null;

        if ($parent !== null && (! Page::query()->whereKey($parent)->exists() || in_array((int) $parent, $page->getSelfAndDescendantKeys(), true))) {
            $data['parent_id'] = null;
        }

        $page->fill($data);

        $taken = Page::query()->sameSiteAs($page)->whereKeyNot($page->getKey())->where('path', $page->buildPath())->exists();

        if ($taken) {
            $page->fill($current);
        }

        $page->revisionNote = __('alfakit-cms::cms.revisions.restored_note', ['number' => $revision->number]);
        $page->save();

        return $page;
    }

    private static function prune(Page $page): void
    {
        $limit = CmsPlugin::get()->getRevisionsLimit();

        if ($limit === 0) {
            return;
        }

        $keep = PageRevision::query()->where('page_id', $page->getKey())->orderByDesc('number')->limit($limit)->pluck('id');

        PageRevision::query()->where('page_id', $page->getKey())->whereNotIn('id', $keep)->delete();
    }
}
