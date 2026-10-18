<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\Alfakit\Support\Url;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link target the site may render: `http(s)`, `mailto:`, `tel:`, a relative path or a
 * fragment (never `javascript:` or `data:`).
 */
class SafeUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || Url::sanitize($value) === null) {
            $fail(__('alfakit-cms::cms.validation.url'));
        }
    }
}
