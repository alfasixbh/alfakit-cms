<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;

/**
 * What restoring a revision would change, in plain language ("The title changes from
 * “Home” to “Welcome”", "A FAQ block is added").
 */
final class RevisionDiff
{
    /**
     * @param  array<string, mixed>  $from  The page now
     * @param  array<string, mixed>  $to  The revision
     * @return array<int, string>
     */
    public static function describe(array $from, array $to): array
    {
        $changes = [];

        foreach (['title', 'slug'] as $attribute) {
            $old = self::string($from[$attribute] ?? null);
            $new = self::string($to[$attribute] ?? null);

            if ($old !== $new) {
                $changes[] = __('alfakit-cms::cms.compare.'.$attribute, ['from' => $old ?? '—', 'to' => $new ?? '—']);
            }
        }

        if (($from['parent_id'] ?? null) != ($to['parent_id'] ?? null)) {
            $changes[] = __('alfakit-cms::cms.compare.parent');
        }

        if (json_encode($from['seo'] ?? null) !== json_encode($to['seo'] ?? null)) {
            $changes[] = __('alfakit-cms::cms.compare.seo');
        }

        $registry = CmsPlugin::get()->getBlockRegistry();
        $label = static fn (string $type): string => $registry->get($type)?->getLabel() ?? $type;
        $oldBlocks = self::blocks($from['content'] ?? null);
        $newBlocks = self::blocks($to['content'] ?? null);

        $oldTypes = array_count_values(array_column($oldBlocks, 'type'));
        $newTypes = array_count_values(array_column($newBlocks, 'type'));

        foreach ($newTypes as $type => $count) {
            for ($i = $oldTypes[$type] ?? 0; $i < $count; $i++) {
                $changes[] = __('alfakit-cms::cms.compare.block_added', ['block' => $label((string) $type)]);
            }
        }

        foreach ($oldTypes as $type => $count) {
            for ($i = $newTypes[$type] ?? 0; $i < $count; $i++) {
                $changes[] = __('alfakit-cms::cms.compare.block_removed', ['block' => $label((string) $type)]);
            }
        }

        $edited = 0;

        foreach ($newBlocks as $index => $block) {
            $other = $oldBlocks[$index] ?? null;

            if ($other !== null && $other['type'] === $block['type'] && json_encode($other['data']) !== json_encode($block['data'])) {
                $edited++;
            }
        }

        if ($edited > 0) {
            $changes[] = trans_choice('alfakit-cms::cms.compare.blocks_edited', $edited, ['count' => $edited]);
        }

        if (array_column($oldBlocks, 'type') !== array_column($newBlocks, 'type') && $oldTypes == $newTypes) {
            $changes[] = __('alfakit-cms::cms.compare.reordered');
        }

        return $changes === [] ? [__('alfakit-cms::cms.compare.same')] : $changes;
    }

    /**
     * @return array<int, array{type: string, data: mixed}>
     */
    private static function blocks(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $blocks = [];

        foreach (array_values($content) as $item) {
            if (is_array($item) && is_string($item['type'] ?? null)) {
                $blocks[] = ['type' => $item['type'], 'data' => $item['data'] ?? null];
            }
        }

        return $blocks;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
