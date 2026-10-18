<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitCms\CmsPlugin;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * "Or choose from the media library": a searchable list of the images of Spatie's media
 * library, when alfasix/alfakit-media-library is installed (null otherwise, and on
 * tenancy panels, whose media isn't tenant-scoped).
 */
final class MediaLibraryPicker
{
    public static function make(string $name): ?Select
    {
        if (! CmsPlugin::get()->usesMediaLibrary()) {
            return null;
        }

        return Select::make($name)
            ->label(__('alfakit-cms::cms.fields.media_library'))
            ->helperText(__('alfakit-cms::cms.fields.media_library_help'))
            ->searchable()
            ->getSearchResultsUsing(static fn (?string $search): array => self::options($search))
            ->getOptionLabelUsing(static fn (mixed $value): ?string => is_numeric($value) ? (Media::query()->find((int) $value)?->getAttribute('name') ?? null) : null)
            ->options(static fn (): array => self::options(null));
    }

    /**
     * @return array<int, string>
     */
    private static function options(?string $search): array
    {
        $query = Media::query()->where('mime_type', 'like', 'image/%')->latest('id')->limit(50);

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(static fn ($query) => $query->where('name', 'like', $term)->orWhere('file_name', 'like', $term));
        }

        $options = [];

        foreach ($query->get() as $media) {
            $name = $media->getAttribute('name');
            $options[(int) $media->getKey()] = is_string($name) && $name !== '' ? $name : (string) $media->getAttribute('file_name');
        }

        return $options;
    }
}
