<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * One image with alternative text, an optional caption and a width.
 */
class ImageBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'image';
    }

    public function getIcon(): ?string
    {
        return 'image';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.media');
    }

    public function schema(): array
    {
        return [
            ...$this->imageFields('image', required: true),
            TextInput::make('caption')->label(static::label('caption'))->maxLength(300),
            Select::make('width')
                ->label(static::label('width'))
                ->options([
                    'contained' => __('alfakit-cms::cms.options.contained'),
                    'wide' => __('alfakit-cms::cms.options.wide'),
                    'full' => __('alfakit-cms::cms.options.full'),
                ])
                ->default('contained')
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $image = $this->image($data, 'image', $context);

        return $image === null ? null : [
            'image' => $image,
            'caption' => $this->text($data, 'caption', 300),
            'width' => $this->choice($data, 'width', ['contained', 'wide', 'full'], 'contained'),
        ];
    }
}
