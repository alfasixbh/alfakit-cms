<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\Blocks\CmsBlock;
use Throwable;

/**
 * Turns stored page content (Builder state: `[['type' => …, 'data' => […]], …]`) into the
 * render-ready blocks the frontend renderers draw: `[['type', 'id', 'data'], …]`. Unknown
 * block types and blocks whose `toFrontend()` returns null are left out.
 */
final class BlockRenderer
{
    /** Columns may hold blocks, but not columns inside columns inside columns. */
    public const MAX_DEPTH = 2;

    /**
     * @param  mixed  $content  The stored Builder state.
     * @return array<int, array{type: string, id: string, data: array<string, mixed>, style?: array<string, string>}>
     */
    public static function render(mixed $content, BlockContext $context): array
    {
        if (! is_array($content) || $context->depth > self::MAX_DEPTH) {
            return [];
        }

        $registry = $context->plugin->getBlockRegistry();
        $blocks = [];

        foreach (array_values($content) as $index => $item) {
            if (! is_array($item) || ! is_string($item['type'] ?? null)) {
                continue;
            }

            $block = $registry->get($item['type']);

            if ($block === null) {
                continue;
            }

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];

            // "Hide this block": kept in the editor, never shown to visitors.
            if ((bool) ($data[CmsBlock::HIDDEN] ?? false)) {
                continue;
            }

            try {
                $rendered = $block->toFrontend($data, $context);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($rendered === null) {
                continue;
            }

            $entry = [
                'type' => $item['type'],
                'id' => ($context->depth > 0 ? 'd'.$context->depth.'-' : '').'block-'.($index + 1),
                'data' => $rendered,
            ];

            // Background, spacing, visibility and anchor from the block's **Style** section.
            if ($context->plugin->hasSiteDesign() && ($style = CmsBlock::style($data)) !== null) {
                $entry['style'] = $style;
            }

            $blocks[] = $entry;
        }

        return $blocks;
    }

    /**
     * The JSON-LD nodes the rendered blocks add (`FAQPage`…).
     *
     * @param  array<int, array{type: string, id: string, data: array<string, mixed>}>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function jsonLd(array $blocks, BlockContext $context): array
    {
        $registry = $context->plugin->getBlockRegistry();
        $nodes = [];

        foreach ($blocks as $block) {
            $node = $registry->get($block['type'])?->jsonLd($block['data'], $context);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
