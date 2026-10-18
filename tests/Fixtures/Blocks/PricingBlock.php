<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Tests\Fixtures\Blocks;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Blocks\CmsBlock;
use AlfaSix\AlfakitCms\Support\BlockContext;

class PricingBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'pricing';
    }

    public function schema(): array
    {
        return [TextInput::make('plan')->required()];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        return ['plan' => $this->text($data, 'plan'), 'depth' => $context->depth];
    }
}
