<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Models\Concerns\BelongsToCmsTenant;
use AlfaSix\AlfakitCms\Support\PageRevisions;
use AlfaSix\AlfakitCms\Support\Redirects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A CMS page: block content, SEO and publishing state. `path` is derived from the
 * parent's path and the slug on every save, and descendants follow when it changes.
 *
 * @property int $id
 * @property int|string|null $tenant_id
 * @property int|null $parent_id
 * @property int|null $translation_of_id
 * @property string|null $locale
 * @property string $title
 * @property string|null $slug
 * @property string $path
 * @property bool $is_home
 * @property PageStatus $status
 * @property Carbon|null $publish_at
 * @property Carbon|null $published_at
 * @property array<int, array<string, mixed>>|null $content
 * @property array<string, mixed>|null $seo
 * @property array<string, mixed>|null $draft
 * @property Carbon|null $draft_saved_at
 * @property array<string, mixed>|null $review
 * @property string|null $layout
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Page|null $parent
 * @property-read Page|null $translationOf
 */
class Page extends Model
{
    use BelongsToCmsTenant;

    /** The attributes a revision stores and restores. */
    public const REVISIONED = ['title', 'slug', 'parent_id', 'is_home', 'content', 'seo', 'layout'];

    protected $table = 'alfakit_cms_pages';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'draft',
        'path' => '',
        'is_home' => false,
        'sort' => 0,
    ];

    /** A note for the revision of the next save ("Restored from revision 3"). */
    public ?string $revisionNote = null;

    /** Skip the revision of the next save. */
    public bool $withoutRevision = false;

    protected static function booted(): void
    {
        static::saving(static function (Page $page): void {
            $page->slug = $page->is_home ? null : static::normalizeSlug($page->slug);
            $page->path = $page->buildPath();

            if ($page->status === PageStatus::Published && $page->published_at === null) {
                $page->published_at = $page->publish_at ?? now();
            }
        });

        static::created(static function (Page $page): void {
            if ($page->is_home) {
                $page->releaseOtherHomePages();
            }

            PageRevisions::recordIfChanged($page, created: true);
        });

        static::updated(static function (Page $page): void {
            if ($page->is_home && $page->wasChanged('is_home')) {
                $page->releaseOtherHomePages();
            }

            if ($page->wasChanged('path')) {
                Redirects::pageMoved($page, (string) $page->getOriginal('path'));

                $page->children()->get()->each(static function (Page $child): void {
                    $child->withoutRevision = true;
                    $child->save();
                });
            }

            PageRevisions::recordIfChanged($page);
        });
    }

    /**
     * Only one home page per tenant and locale: the others stop being it.
     */
    protected function releaseOtherHomePages(): void
    {
        static::query()
            ->sameSiteAs($this)
            ->whereKeyNot($this->getKey())
            ->where('is_home', true)
            ->get()
            ->each(static function (Page $other): void {
                $other->is_home = false;
                $other->withoutRevision = true;
                $other->save();
            });
    }

    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'is_home' => 'boolean',
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'content' => 'array',
            'seo' => 'array',
            'draft' => 'array',
            'draft_saved_at' => 'datetime',
            'review' => 'array',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('title');
    }

    /**
     * The page this one translates.
     *
     * @return BelongsTo<Page, $this>
     */
    public function translationOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translation_of_id');
    }

    /**
     * The translations of this page (pages whose "translation of" is this one).
     *
     * @return HasMany<Page, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'translation_of_id');
    }

    /**
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class, 'page_id')->orderByDesc('number');
    }

    /**
     * Published and live (no `publish_at`, or one in the past).
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), PageStatus::Published->value)
            ->where(static fn (Builder $query) => $query
                ->whereNull($query->qualifyColumn('publish_at'))
                ->orWhere($query->qualifyColumn('publish_at'), '<=', now()));
    }

    /**
     * Pages of the same tenant and locale as this one.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeSameSiteAs(Builder $query, Page $page): Builder
    {
        $page->tenant_id === null ? $query->whereNull($query->qualifyColumn('tenant_id')) : $query->where($query->qualifyColumn('tenant_id'), $page->tenant_id);
        $page->locale === null ? $query->whereNull($query->qualifyColumn('locale')) : $query->where($query->qualifyColumn('locale'), $page->locale);

        return $query;
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeForLocale(Builder $query, ?string $locale): Builder
    {
        return $locale === null ? $query : $query->where($query->qualifyColumn('locale'), $locale);
    }

    public function getState(): PageState
    {
        if ($this->status !== PageStatus::Published) {
            return PageState::Draft;
        }

        return $this->publish_at !== null && $this->publish_at->isFuture() ? PageState::Scheduled : PageState::Published;
    }

    /**
     * What editors see in lists: the state, or "changes not published yet" for a live page
     * with saved changes.
     */
    public function getDisplayState(): PageState
    {
        $state = $this->getState();

        return $state !== PageState::Draft && $this->hasPendingChanges() ? PageState::ChangesPending : $state;
    }

    /** Saved changes (or an autosave) that visitors don't see yet. */
    public function hasPendingChanges(): bool
    {
        return is_array($this->draft) && $this->draft !== [];
    }

    /**
     * An unsaved copy of the page with its pending changes applied (what the editor and
     * previews show).
     */
    public function withDraft(): static
    {
        $copy = clone $this;

        if ($this->hasPendingChanges()) {
            $copy->forceFill(array_intersect_key((array) $this->draft, array_flip(self::REVISIONED)));
        }

        return $copy;
    }

    /**
     * Copy the pending changes onto the page (it still has to be saved).
     */
    public function applyDraft(): static
    {
        if ($this->hasPendingChanges()) {
            $this->fill(array_intersect_key((array) $this->draft, array_flip(self::REVISIONED)));
        }

        $this->draft = null;
        $this->draft_saved_at = null;

        return $this;
    }

    /** `pending` (submitted for review), `changes_requested`, or null. */
    public function getReviewStatus(): ?string
    {
        $status = $this->review['status'] ?? null;

        return in_array($status, ['pending', 'changes_requested'], true) ? $status : null;
    }

    public function isLive(): bool
    {
        return $this->getState() === PageState::Published;
    }

    /**
     * The path from the parent's path and the slug, without slashes at the ends (the home
     * page's path is empty).
     */
    public function buildPath(): string
    {
        if ($this->is_home) {
            return '';
        }

        $parent = $this->parent_id === null ? null : static::query()->find($this->parent_id);
        $prefix = $parent instanceof self ? $parent->path : '';

        return trim($prefix.'/'.($this->slug ?? ''), '/');
    }

    /**
     * Keys of this page and every page below it (a page can't be moved under them).
     *
     * @return array<int, int>
     */
    public function getSelfAndDescendantKeys(): array
    {
        $keys = [(int) $this->getKey()];
        $frontier = $keys;

        while ($frontier !== []) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->whereKeyNot($keys)->pluck('id')->map(static fn (mixed $key): int => (int) $key)->all();
            $keys = [...$keys, ...$frontier];
        }

        return $keys;
    }

    /**
     * The ancestors from the root down to the parent.
     *
     * @return array<int, Page>
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $seen = [$this->getKey()];
        $parent = $this->parent;

        while ($parent instanceof self && ! in_array($parent->getKey(), $seen, true)) {
            array_unshift($ancestors, $parent);
            $seen[] = $parent->getKey();
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    /**
     * The source page of this page's translation group (itself when it translates nothing).
     */
    public function getTranslationSource(): Page
    {
        return $this->translationOf ?? $this;
    }

    public static function normalizeSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }

        $slug = trim(preg_replace('#/+#', '/', trim($slug)) ?? '', '/');

        return $slug === '' ? null : $slug;
    }
}
