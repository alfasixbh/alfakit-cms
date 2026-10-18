<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\AlfakitCms\Rules\SafeUrl;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * A grid of features: an icon, a title, a description and an optional link each.
 */
class FeaturesBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'features';
    }

    public function getIcon(): ?string
    {
        return 'layout-grid';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.content');
    }

    public function schema(): array
    {
        return [
            TextInput::make('heading')->label(static::label('heading'))->maxLength(200),
            Textarea::make('intro')->label(static::label('intro'))->rows(2)->maxLength(600),
            Select::make('columns')
                ->label(static::label('columns'))
                ->options([2 => '2', 3 => '3', 4 => '4'])
                ->default(3)
                ->native(false),
            Repeater::make('items')
                ->label(static::label('features'))
                ->schema([
                    TextInput::make('icon')->label(static::label('icon'))->helperText(__('alfakit-cms::cms.fields.icon_help'))->maxLength(64),
                    TextInput::make('title')->label(static::label('title'))->required()->maxLength(160),
                    Textarea::make('description')->label(static::label('description'))->rows(2)->maxLength(500),
                    TextInput::make('url')->label(static::label('link_url'))->maxLength(2048)->rule(new SafeUrl),
                ])
                ->itemLabel(static fn (array $state): ?string => is_string($state['title'] ?? null) ? $state['title'] : null)
                ->grid(2)
                ->minItems(1)
                ->maxItems(24)
                ->collapsible()
                ->addActionLabel(__('alfakit-cms::cms.actions.add_feature'))
                ->columnSpanFull(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $items = [];

        foreach ($this->items($data, 'items', 24) as $item) {
            $title = $this->text($item, 'title', 160);

            if ($title === null) {
                continue;
            }

            $icon = $this->text($item, 'icon', 64);

            $items[] = [
                'icon' => $icon === null ? null : Icons::resolve($icon),
                'title' => $title,
                'description' => $this->text($item, 'description', 500),
                'url' => $this->url($item, 'url'),
            ];
        }

        return $items === [] ? null : [
            'heading' => $this->text($data, 'heading', 200),
            'intro' => $this->text($data, 'intro', 600),
            'columns' => (int) $this->choice($data, 'columns', [2, 3, 4], 3),
            'items' => $items,
        ];
    }
}
