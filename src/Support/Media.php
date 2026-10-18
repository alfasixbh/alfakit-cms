<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Support\Url;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Public URLs of block images: stored paths on the CMS disk, or absolute `http(s)` URLs.
 */
final class Media
{
    /**
     * The URL of a media library image picked by id (alfasix/alfakit-media-library).
     */
    public static function libraryUrl(mixed $id, \AlfaSix\AlfakitCms\CmsPlugin $plugin): ?string
    {
        if (! is_numeric($id) || ! $plugin->usesMediaLibrary()) {
            return null;
        }

        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::query()->whereKey((int) $id)->where('mime_type', 'like', 'image/%')->first();

        return $media === null ? null : Url::sanitize($media->getFullUrl(), ['http', 'https']);
    }

    public static function url(mixed $value, string $disk): ?string
    {
        if (is_array($value)) {
            $value = array_values($value)[0] ?? null;
        }

        if (! is_string($value) || ($value = trim($value)) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return Url::sanitize($value, ['http', 'https']);
        }

        // Stored paths never climb out of the disk or carry a scheme.
        if (str_contains($value, '..') || str_contains($value, ':')) {
            return null;
        }

        try {
            return Url::sanitize(Storage::disk($disk)->url(ltrim($value, '/')), ['http', 'https']);
        } catch (Throwable) {
            return null;
        }
    }
}
