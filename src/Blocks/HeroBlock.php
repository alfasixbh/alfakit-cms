<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * A page opener: eyebrow, heading, intro, up to two buttons and an optional image shown
 * beside the text on wide screens.
 */
class HeroBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'hero';
    }

    public function getIcon(): ?string
    {
        return 'panel-top';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.layout');
    }

    public function schema(): array
    {
        return [
            TextInput::make('eyebrow')->label(static::label('eyebrow'))->maxLength(120),
            TextInput::make('heading')->label(static::label('heading'))->required()->maxLength(200),
            Textarea::make('subheading')->label(static::label('subheading'))->rows(3)->maxLength(600),
            $this->linkFields('primary', static::label('primary_button')),
            $this->linkFields('secondary', static::label('secondary_button')),
            Grid::make(2)->schema($this->imageFields('image')),
            Select::make('alignment')
                ->label(static::label('alignment'))
                ->options(['start' => __('alfakit-cms::cms.options.start'), 'center' => __('alfakit-cms::cms.options.center')])
                ->default('start')
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $heading = $this->text($data, 'heading', 200);

        if ($heading === null) {
            return null;
        }

        return [
            'eyebrow' => $this->text($data, 'eyebrow', 120),
            'heading' => $heading,
            'subheading' => $this->text($data, 'subheading', 600),
            'primaryAction' => $this->link($data, 'primary'),
            'secondaryAction' => $this->link($data, 'secondary'),
            'image' => $this->image($data, 'image', $context),
            'alignment' => $this->choice($data, 'alignment', ['start', 'center'], 'start'),
        ];
    }
}
