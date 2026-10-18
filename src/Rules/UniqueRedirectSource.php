<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\Models\Redirect;
use AlfaSix\AlfakitCms\Support\Redirects;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * One redirect per source path and tenant.
 */
class UniqueRedirectSource implements ValidationRule
{
    public function __construct(protected ?Redirect $ignore, protected ?Model $tenant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $exists = Redirect::query()
            ->forTenant($this->tenant)
            ->where('from_path', Redirects::normalizePath($value))
            ->when($this->ignore?->exists === true, fn ($query) => $query->whereKeyNot($this->ignore?->getKey()))
            ->exists();

        if ($exists) {
            $fail(__('alfakit-cms::cms.validation.redirect_taken'));
        }
    }
}
