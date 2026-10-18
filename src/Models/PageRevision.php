<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A saved version of a page (`Page::REVISIONED` attributes), numbered from 1.
 *
 * @property int $id
 * @property int $page_id
 * @property int $number
 * @property array<string, mixed> $data
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Page|null $page
 * @property-read Model|null $author
 */
class PageRevision extends Model
{
    protected $table = 'alfakit_cms_page_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAuthorName(): ?string
    {
        $author = $this->author;

        if (! $author instanceof Model) {
            return null;
        }

        $name = $author->getAttribute('name') ?? $author->getAttribute('email');

        return is_scalar($name) ? (string) $name : null;
    }

    public function getTitle(): string
    {
        $title = $this->data['title'] ?? '';

        return is_scalar($title) ? (string) $title : '';
    }
}
