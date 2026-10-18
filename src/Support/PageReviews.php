<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Events\PageReviewed;
use AlfaSix\AlfakitCms\Events\PageSubmittedForReview;
use AlfaSix\AlfakitCms\Models\Page;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The review workflow: an author submits a page, a reviewer (someone with the `publish`
 * ability) approves it, which publishes it, or asks for changes with a note. Each step
 * sends a database notification (the panel's bell) to the other side.
 */
final class PageReviews
{
    public static function submit(Page $page, ?string $note = null): Page
    {
        $user = alfakit()->auth();

        $page->review = array_filter([
            'status' => 'pending',
            'note' => self::note($note),
            'submitted_by_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'submitted_by_id' => $user instanceof Model ? $user->getKey() : null,
            'submitted_by_name' => self::name($user),
            'submitted_at' => now()->toAtomString(),
        ], static fn (mixed $value): bool => $value !== null);
        $page->withoutRevision = true;
        $page->save();

        $reviewers = array_filter(CmsPlugin::get()->getReviewers($page), static fn (Model $reviewer): bool => ! ($user instanceof Model && $reviewer->is($user)));

        if ($reviewers !== []) {
            self::notification($page)
                ->title(__('alfakit-cms::cms.review.notifications.submitted_title', ['title' => $page->title]))
                ->body(__('alfakit-cms::cms.review.notifications.submitted_body', ['name' => self::name($user) ?? __('alfakit-cms::cms.revisions.unknown_author')]).(self::note($note) !== null ? ' “'.self::note($note).'”' : ''))
                ->info()
                ->sendToDatabase(array_values(array_filter($reviewers, static fn (Model $reviewer): bool => $reviewer instanceof Authenticatable)));
        }

        PageSubmittedForReview::dispatch($page);

        return $page;
    }

    public static function approve(Page $page, DateTimeInterface|string|null $at = null): Page
    {
        $submitter = self::submitter($page);

        Publisher::publish($page, $at);

        if ($submitter instanceof Authenticatable) {
            self::notification($page)
                ->title(__('alfakit-cms::cms.review.notifications.approved_title', ['title' => $page->title]))
                ->success()
                ->sendToDatabase($submitter);
        }

        PageReviewed::dispatch($page, true, null);

        return $page;
    }

    public static function requestChanges(Page $page, ?string $note): Page
    {
        $submitter = self::submitter($page);
        $reviewer = alfakit()->auth();

        $page->review = [
            ...(is_array($page->review) ? $page->review : []),
            'status' => 'changes_requested',
            'note' => self::note($note),
            'reviewed_by_name' => self::name($reviewer),
            'reviewed_at' => now()->toAtomString(),
        ];
        $page->withoutRevision = true;
        $page->save();

        if ($submitter instanceof Authenticatable) {
            self::notification($page)
                ->title(__('alfakit-cms::cms.review.notifications.changes_title', ['title' => $page->title]))
                ->body(self::note($note))
                ->warning()
                ->sendToDatabase($submitter);
        }

        PageReviewed::dispatch($page, false, self::note($note));

        return $page;
    }

    /**
     * Who submitted the page for review.
     */
    public static function submitter(Page $page): ?Model
    {
        $type = $page->review['submitted_by_type'] ?? null;
        $id = $page->review['submitted_by_id'] ?? null;

        if (! is_string($type) || $id === null) {
            return null;
        }

        $class = Model::getActualClassNameForMorph($type);

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        try {
            return $class::query()->find($id);
        } catch (Throwable) {
            return null;
        }
    }

    private static function notification(Page $page): Notification
    {
        $notification = Notification::make();

        try {
            $url = CmsPlugin::get()->getPageResource()::getUrl('edit', ['record' => $page]);
            $notification->actions([NotificationAction::make('open')->label(__('alfakit-cms::cms.review.notifications.open'))->url($url)]);
        } catch (Throwable) {
            // Outside a panel request (queues, console) the notification has no link.
        }

        return $notification;
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 1000);
    }

    private static function name(mixed $user): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }

        $name = $user->getAttribute('name') ?? $user->getAttribute('email');

        return is_scalar($name) ? (string) $name : null;
    }
}
