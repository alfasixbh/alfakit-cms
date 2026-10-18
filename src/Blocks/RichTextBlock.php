<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * Formatted text from the rich editor, sanitised on the server before it is sent.
 */
class RichTextBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'rich-text';
    }

    public function getIcon(): ?string
    {
        return 'text';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.content');
    }

    public function schema(): array
    {
        return [
            RichEditor::make('content')->label(static::label('content'))->required()->columnSpanFull(),
            Select::make('width')
                ->label(static::label('width'))
                ->options(['prose' => __('alfakit-cms::cms.options.prose'), 'wide' => __('alfakit-cms::cms.options.wide')])
                ->default('prose')
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $html = $this->html($data, 'content');

        return $html === null ? null : [
            'html' => $html,
            'width' => $this->choice($data, 'width', ['prose', 'wide'], 'prose'),
        ];
    }
}
