<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\Models\Page;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * No other page of the same tenant and locale may have the path this slug and parent give.
 */
class UniquePagePath implements ValidationRule
{
    public function __construct(
        protected ?Page $ignore,
        protected mixed $parentId,
        protected ?string $locale,
        protected bool $isHome,
        protected ?Model $tenant,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->isHome) {
            return;
        }

        $page = new Page([
            'slug' => is_string($value) ? $value : null,
            'parent_id' => is_numeric($this->parentId) ? (int) $this->parentId : null,
            'is_home' => false,
        ]);
        $page->slug = Page::normalizeSlug($page->slug);
        $path = $page->buildPath();

        $exists = Page::query()
            ->forTenant($this->tenant)
            ->when($this->locale === null, static fn ($query) => $query->whereNull('locale'), fn ($query) => $query->where('locale', $this->locale))
            ->when($this->ignore !== null, fn ($query) => $query->whereKeyNot($this->ignore?->getKey()))
            ->where('path', $path)
            ->exists();

        if ($exists) {
            $fail(__('alfakit-cms::cms.validation.path_taken', ['path' => '/'.$path]));
        }
    }
}
