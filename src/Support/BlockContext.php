<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Support;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;

/**
 * What a block's `toFrontend()` may need besides its data: the page, the site it is
 * served on, the plugin options, the nesting depth and whether this is a preview.
 */
final class BlockContext
{
    public function __construct(
        public readonly CmsPlugin $plugin,
        public readonly ?Page $page = null,
        public readonly ?Site $site = null,
        public readonly int $depth = 0,
        public readonly bool $preview = false,
    ) {}

    public function nested(): self
    {
        return new self($this->plugin, $this->page, $this->site, $this->depth + 1, $this->preview);
    }
}
