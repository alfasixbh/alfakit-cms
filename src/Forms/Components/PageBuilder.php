<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Forms\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\AlfakitCms\Blocks\CmsBlock;
use AlfaSix\AlfakitCms\CmsPlugin;

/**
 * The page content editor: a Builder with the plugin's blocks, grouped and searchable in
 * the picker, collapsible and cloneable. Use it in your own resources to give any model
 * block content that the CMS renderers can draw (`BlockRenderer::render()`).
 */
class PageBuilder extends Builder
{
    /** @var array<int, string> */
    protected array $exceptBlocks = [];

    /**
     * Leave blocks out of this editor by name (`['section']`).
     *
     * @param  array<int, string>  $names
     */
    public function exceptBlocks(array $names): static
    {
        $this->exceptBlocks = array_values($names);

        return $this;
    }

    /**
     * Whether the block at `$key` is hidden from visitors.
     */
    public function isItemHidden(mixed $key): bool
    {
        $state = $this->getState();

        return is_array($state) && is_scalar($key) && (bool) ($state[$key]['data'][CmsBlock::HIDDEN] ?? false);
    }

    public function getType(): string
    {
        return 'Builder';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.fields.content'));
        $this->blocks(fn (): array => CmsPlugin::get()->getBlockRegistry()->toBuilderBlocks(except: $this->exceptBlocks));
        $this->blockIcons();
        $this->blockPickerColumns(['default' => 1, 'md' => 2]);
        $this->searchable();
        $this->collapsible();
        $this->cloneable();
        $this->blockActionsMenu();
        // "Hide from visitors" / "Show to visitors" in each block's menu.
        $this->extraItemActions([
            Action::make('toggleBlockVisibility')
                ->label(fn (array $arguments): string => $this->isItemHidden($arguments['item'] ?? null)
                    ? __('alfakit-cms::cms.actions.show_block')
                    : __('alfakit-cms::cms.actions.hide_block'))
                ->icon(fn (array $arguments): string => $this->isItemHidden($arguments['item'] ?? null) ? 'eye' : 'eye-off')
                ->action(function (array $arguments): void {
                    $key = $arguments['item'] ?? null;
                    $state = $this->getState();

                    if (! is_array($state) || ! is_scalar($key) || ! is_array($state[$key] ?? null)) {
                        return;
                    }

                    $data = is_array($state[$key]['data'] ?? null) ? $state[$key]['data'] : [];
                    $data[CmsBlock::HIDDEN] = ! ($data[CmsBlock::HIDDEN] ?? false);
                    $state[$key]['data'] = $data;
                    $this->state($state);
                }),
        ]);
        $this->addActionLabel(__('alfakit-cms::cms.actions.add_block'));
        $this->columnSpanFull();
    }
}
