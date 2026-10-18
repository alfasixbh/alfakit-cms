<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Events\PagePublished;
use AlfaSix\AlfakitCms\Events\PageUnpublished;
use AlfaSix\AlfakitCms\Models\Page;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Publishing a page now or at a later time, and taking it back to a draft. Scheduled
 * pages need no job: a published page whose `publish_at` is in the future is hidden from
 * every public query until that time.
 */
final class Publisher
{
    public static function publish(Page $page, DateTimeInterface|string|null $at = null): Page
    {
        $at = $at === null || $at === '' ? null : Carbon::parse($at);

        // Saved changes go live with it (and become a revision).
        $hadChanges = $page->hasPendingChanges();
        $page->applyDraft();
        $page->review = null;

        $page->status = PageStatus::Published;
        $page->publish_at = $at !== null && $at->isFuture() ? $at : null;

        if ($page->publish_at === null && $page->published_at === null) {
            $page->published_at = now();
        }

        if ($page->publish_at !== null) {
            $page->published_at = $page->publish_at;
        }

        $page->withoutRevision = ! $hadChanges;
        $page->save();

        PagePublished::dispatch($page);

        return $page;
    }

    /**
     * Throw away the saved changes of a page (the live version stays).
     */
    public static function discardChanges(Page $page): Page
    {
        $page->draft = null;
        $page->draft_saved_at = null;
        $page->withoutRevision = true;
        $page->save();

        return $page;
    }

    public static function unpublish(Page $page): Page
    {
        $page->status = PageStatus::Draft;
        $page->publish_at = null;
        $page->withoutRevision = true;
        $page->save();

        PageUnpublished::dispatch($page);

        return $page;
    }
}
