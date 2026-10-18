<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * A grid of images, each with alternative text and a caption.
 */
class GalleryBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'gallery';
    }

    public function getIcon(): ?string
    {
        return 'images';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.media');
    }

    public function schema(): array
    {
        return [
            TextInput::make('heading')->label(static::label('heading'))->maxLength(200),
            Select::make('columns')
                ->label(static::label('columns'))
                ->options([2 => '2', 3 => '3', 4 => '4'])
                ->default(3)
                ->native(false),
            Repeater::make('images')
                ->label(static::label('images'))
                ->schema([
                    ...$this->imageFields('image', required: true),
                    TextInput::make('caption')->label(static::label('caption'))->maxLength(300),
                ])
                ->grid(2)
                ->minItems(1)
                ->maxItems(48)
                ->collapsible()
                ->addActionLabel(__('alfakit-cms::cms.actions.add_image'))
                ->columnSpanFull(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $images = [];

        foreach ($this->items($data, 'images', 48) as $item) {
            $image = $this->image($item, 'image', $context);

            if ($image !== null) {
                $images[] = [...$image, 'caption' => $this->text($item, 'caption', 300)];
            }
        }

        return $images === [] ? null : [
            'heading' => $this->text($data, 'heading', 200),
            'columns' => (int) $this->choice($data, 'columns', [2, 3, 4], 3),
            'images' => $images,
        ];
    }
}
