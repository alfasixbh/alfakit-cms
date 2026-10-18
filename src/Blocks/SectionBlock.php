<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\AlfakitCms\Models\Section;
use AlfaSix\AlfakitCms\Resources\SectionResource;
use AlfaSix\AlfakitCms\Support\BlockContext;
use AlfaSix\AlfakitCms\Support\BlockRenderer;

/**
 * Shows a reusable section (blocks edited once under "Reusable sections"). Editing the
 * section changes every page that shows it.
 */
class SectionBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'section';
    }

    public function getIcon(): ?string
    {
        return 'layers';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.layout');
    }

    public function getSummary(array $data): ?string
    {
        $id = $data['section'] ?? null;

        return is_numeric($id) ? Section::query()->whereKey((int) $id)->value('name') : null;
    }

    public function schema(): array
    {
        return [
            Select::make('section')
                ->label(static::label('section'))
                ->helperText(__('alfakit-cms::cms.fields.section_help'))
                ->options(static fn (): array => SectionResource::getEloquentQuery()->orderBy('name')->limit(200)->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $id = $data['section'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $tenant = $context->site !== null ? $context->site->tenant : ($context->page?->tenant_id === null ? null : $context->page->tenant);
        $section = Section::query()->forTenant($tenant)->whereKey((int) $id)->first();

        if (! $section instanceof Section) {
            return null;
        }

        $blocks = BlockRenderer::render($section->content, $context->nested());

        return $blocks === [] ? null : ['name' => $section->name, 'blocks' => $blocks];
    }
}
