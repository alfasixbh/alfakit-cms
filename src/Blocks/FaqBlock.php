<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * Questions and answers as an accessible disclosure list; adds a `FAQPage` node to the
 * page's JSON-LD.
 */
class FaqBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'faq';
    }

    public function getIcon(): ?string
    {
        return 'circle-help';
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
                ->label(static::label('questions'))
                ->schema([
                    TextInput::make('question')->label(static::label('question'))->required()->maxLength(300),
                    Textarea::make('answer')->label(static::label('answer'))->required()->rows(3)->maxLength(4000),
                ])
                ->itemLabel(static fn (array $state): ?string => is_string($state['question'] ?? null) ? $state['question'] : null)
                ->minItems(1)
                ->maxItems(50)
                ->collapsible()
                ->addActionLabel(__('alfakit-cms::cms.actions.add_question'))
                ->columnSpanFull(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $items = [];

        foreach ($this->items($data, 'items', 50) as $item) {
            $question = $this->text($item, 'question', 300);
            $answer = $this->text($item, 'answer', 4000);

            if ($question !== null && $answer !== null) {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items === [] ? null : [
            'heading' => $this->text($data, 'heading', 200),
            'items' => $items,
        ];
    }

    public function jsonLd(array $data, BlockContext $context): ?array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $items),
        ];
    }
}
