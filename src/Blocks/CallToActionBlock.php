<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * A highlighted band with a heading, a short text and up to two buttons.
 */
class CallToActionBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'call-to-action';
    }

    public function getIcon(): ?string
    {
        return 'megaphone';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.layout');
    }

    public function schema(): array
    {
        return [
            TextInput::make('heading')->label(static::label('heading'))->required()->maxLength(200),
            Textarea::make('body')->label(static::label('body'))->rows(3)->maxLength(600),
            $this->linkFields('primary', static::label('primary_button')),
            $this->linkFields('secondary', static::label('secondary_button')),
            Select::make('tone')
                ->label(static::label('tone'))
                ->options([
                    'default' => __('alfakit-cms::cms.options.default'),
                    'primary' => __('alfakit-cms::cms.options.primary'),
                    'muted' => __('alfakit-cms::cms.options.muted'),
                ])
                ->default('primary')
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $heading = $this->text($data, 'heading', 200);

        return $heading === null ? null : [
            'heading' => $heading,
            'body' => $this->text($data, 'body', 600),
            'primaryAction' => $this->link($data, 'primary'),
            'secondaryAction' => $this->link($data, 'secondary'),
            'tone' => $this->choice($data, 'tone', ['default', 'primary', 'muted'], 'primary'),
        ];
    }
}
