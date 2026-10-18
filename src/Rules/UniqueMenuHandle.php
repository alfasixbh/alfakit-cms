<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\Models\Menu;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * One menu per handle, tenant and locale.
 */
class UniqueMenuHandle implements ValidationRule
{
    public function __construct(protected ?Menu $ignore, protected mixed $locale, protected ?Model $tenant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $locale = is_string($this->locale) && $this->locale !== '' ? $this->locale : null;

        $exists = Menu::query()
            ->forTenant($this->tenant)
            ->where('handle', $value)
            ->when($locale === null, static fn ($query) => $query->whereNull('locale'), static fn ($query) => $query->where('locale', $locale))
            ->when($this->ignore?->exists === true, fn ($query) => $query->whereKeyNot($this->ignore?->getKey()))
            ->exists();

        if ($exists) {
            $fail(__('alfakit-cms::cms.validation.menu_taken'));
        }
    }
}
