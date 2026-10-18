<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\Models\Page;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * The parent must be a page of the same tenant, and not the page itself or one of its
 * descendants (that would make a loop).
 */
class ValidParent implements ValidationRule
{
    public function __construct(protected ?Page $page, protected ?Model $tenant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value) || ! Page::query()->forTenant($this->tenant)->whereKey((int) $value)->exists()) {
            $fail(__('alfakit-cms::cms.validation.parent'));

            return;
        }

        if ($this->page?->exists === true && in_array((int) $value, $this->page->getSelfAndDescendantKeys(), true)) {
            $fail(__('alfakit-cms::cms.validation.parent_loop'));
        }
    }
}
