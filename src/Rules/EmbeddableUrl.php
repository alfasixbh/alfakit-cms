<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Rules;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Support\Embeds;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL the embed block can show (YouTube, Vimeo, an `https` video file or one of the
 * plugin's `embedHosts()`).
 */
class EmbeddableUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || Embeds::resolve($value, CmsPlugin::get()->getEmbedHosts()) === null) {
            $fail(__('alfakit-cms::cms.validation.embed'));
        }
    }
}
