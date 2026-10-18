<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\Support\Redirects;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A redirect may not lead back to its own source path.
 */
class NotRedirectLoop implements ValidationRule
{
    public function __construct(protected mixed $from) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! is_string($this->from) || trim($this->from) === '' || str_ends_with(trim($this->from), '*')) {
            return;
        }

        $to = trim($value);

        if (str_starts_with($to, '/') || preg_match('#^https?://#i', $to) === 1 && parse_url($to, PHP_URL_HOST) === request()->getHost()) {
            if (mb_strtolower(Redirects::normalizePath($to)) === mb_strtolower(Redirects::normalizePath($this->from))) {
                $fail(__('alfakit-cms::cms.validation.redirect_loop'));
            }
        }
    }
}
