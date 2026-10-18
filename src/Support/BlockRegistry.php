<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\AlfakitCms\Blocks;
use AlfaSix\AlfakitCms\Blocks\CmsBlock;
use InvalidArgumentException;

/**
 * The blocks of the page builder: the defaults, the plugin's `blocks()` (a block with a
 * default's name replaces it), minus `withoutBlocks()`, limited to `onlyBlocks()`.
 */
final class BlockRegistry
{
    /** @var array<int, class-string<CmsBlock>> */
    public const DEFAULTS = [
        Blocks\HeroBlock::class,
        Blocks\RichTextBlock::class,
        Blocks\ImageBlock::class,
        Blocks\GalleryBlock::class,
        Blocks\CallToActionBlock::class,
        Blocks\FeaturesBlock::class,
        Blocks\FaqBlock::class,
        Blocks\TestimonialsBlock::class,
        Blocks\EmbedBlock::class,
        Blocks\ColumnsBlock::class,
        Blocks\FormBlock::class,
        Blocks\SectionBlock::class,
    ];

    /**
     * @param  array<string, CmsBlock>  $blocks
     */
    private function __construct(private readonly array $blocks) {}

    /**
     * @param  array<int, class-string<CmsBlock>|CmsBlock>  $custom
     * @param  array<int, string>  $without
     * @param  array<int, string>|null  $only
     */
    public static function make(array $custom = [], array $without = [], ?array $only = null): self
    {
        $blocks = [];

        foreach ([...self::DEFAULTS, ...$custom] as $block) {
            if (is_string($block)) {
                if (! is_a($block, CmsBlock::class, true)) {
                    throw new InvalidArgumentException("The CMS block [{$block}] must extend [".CmsBlock::class.'].');
                }

                $block = $block::make();
            }

            $name = $block->getName();

            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
                throw new InvalidArgumentException("The CMS block name [{$name}] may only contain lowercase letters, digits, dashes and underscores.");
            }

            $blocks[$name] = $block;
        }

        $blocks = array_diff_key($blocks, array_flip($without));

        if ($only !== null) {
            $ordered = [];

            foreach ($only as $name) {
                if (isset($blocks[$name])) {
                    $ordered[$name] = $blocks[$name];
                }
            }

            $blocks = $ordered;
        }

        return new self($blocks);
    }

    /**
     * @return array<string, CmsBlock>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    public function get(string $name): ?CmsBlock
    {
        return $this->blocks[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /**
     * The Builder blocks of the page editor.
     *
     * @param  array<int, string>  $except
     * @return array<int, Block>
     */
    public function toBuilderBlocks(array $except = []): array
    {
        $blocks = [];

        foreach ($this->blocks as $name => $block) {
            if (! in_array($name, $except, true)) {
                $blocks[] = $block->toBuilderBlock();
            }
        }

        return $blocks;
    }
}
