<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Support\BlockContext;
use AlfaSix\AlfakitCms\Support\BlockRenderer;

/**
 * Two to four columns side by side (stacked on small screens), each holding its own
 * blocks (any block but columns).
 */
class ColumnsBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'columns';
    }

    public function getIcon(): ?string
    {
        return 'columns-3';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.layout');
    }

    public function schema(): array
    {
        return [
            Select::make('align')
                ->label(static::label('vertical_alignment'))
                ->options([
                    'start' => __('alfakit-cms::cms.options.top'),
                    'center' => __('alfakit-cms::cms.options.middle'),
                ])
                ->default('start')
                ->native(false),
            Repeater::make('columns')
                ->label(static::label('columns'))
                ->schema([
                    Builder::make('blocks')
                        ->label(static::label('blocks'))
                        ->blocks(static fn (): array => CmsPlugin::get()->getBlockRegistry()->toBuilderBlocks(except: ['columns']))
                        ->collapsible()
                        ->columnSpanFull(),
                ])
                ->defaultItems(2)
                ->minItems(2)
                ->maxItems(4)
                ->grid(2)
                ->addActionLabel(__('alfakit-cms::cms.actions.add_column'))
                ->columnSpanFull(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $columns = [];

        foreach ($this->items($data, 'columns', 4) as $column) {
            $columns[] = ['blocks' => BlockRenderer::render($column['blocks'] ?? [], $context->nested())];
        }

        if (count(array_filter($columns, static fn (array $column): bool => $column['blocks'] !== [])) === 0) {
            return null;
        }

        return [
            'align' => $this->choice($data, 'align', ['start', 'center'], 'start'),
            'columns' => $columns,
        ];
    }
}
