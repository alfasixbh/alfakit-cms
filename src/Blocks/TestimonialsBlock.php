<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * Quotes from real customers, written by editors (the block ships without sample
 * content): quote, name, role and an optional photo.
 */
class TestimonialsBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'testimonials';
    }

    public function getIcon(): ?string
    {
        return 'quote';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.content');
    }

    public function schema(): array
    {
        return [
            TextInput::make('heading')->label(static::label('heading'))->maxLength(200),
            Repeater::make('items')
                ->label(static::label('testimonials'))
                ->schema([
                    Textarea::make('quote')->label(static::label('quote'))->required()->rows(3)->maxLength(1000),
                    TextInput::make('author')->label(static::label('author'))->required()->maxLength(120),
                    TextInput::make('role')->label(static::label('role'))->maxLength(160),
                    $this->imageUpload('avatar')->label(static::label('photo'))->avatar(),
                ])
                ->itemLabel(static fn (array $state): ?string => is_string($state['author'] ?? null) ? $state['author'] : null)
                ->grid(2)
                ->minItems(1)
                ->maxItems(24)
                ->collapsible()
                ->addActionLabel(__('alfakit-cms::cms.actions.add_testimonial'))
                ->columnSpanFull(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $items = [];

        foreach ($this->items($data, 'items', 24) as $item) {
            $quote = $this->text($item, 'quote', 1000);
            $author = $this->text($item, 'author', 120);

            if ($quote === null || $author === null) {
                continue;
            }

            $avatar = $this->image($item, 'avatar', $context);

            $items[] = [
                'quote' => $quote,
                'author' => $author,
                'role' => $this->text($item, 'role', 160),
                'avatar' => $avatar === null ? null : ['url' => $avatar['url'], 'alt' => $author],
            ];
        }

        return $items === [] ? null : [
            'heading' => $this->text($data, 'heading', 200),
            'items' => $items,
        ];
    }
}
