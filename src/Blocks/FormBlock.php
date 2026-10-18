<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Support\BlockContext;

/**
 * A hook for forms: editors pick one of `CmsPlugin::formOptions()`, the server resolves
 * it with `CmsPlugin::formUsing()` and the frontend's `cms:form` component renders it.
 * Without `formUsing()` (or with an unknown form) the block isn't rendered.
 */
class FormBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'form';
    }

    public function getIcon(): ?string
    {
        return 'clipboard-list';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.content');
    }

    public function schema(): array
    {
        return [
            TextInput::make('heading')->label(static::label('heading'))->maxLength(200),
            Textarea::make('description')->label(static::label('description'))->rows(2)->maxLength(600),
            Select::make('form')
                ->label(static::label('form'))
                ->options(static fn (): array => CmsPlugin::get()->getFormOptions())
                ->helperText(__('alfakit-cms::cms.fields.form_help'))
                ->required()
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $form = $this->text($data, 'form', 120);

        if ($form === null || ! array_key_exists($form, $context->plugin->getFormOptions())) {
            return null;
        }

        $payload = $context->plugin->resolveForm($form, $context->page);

        return $payload === null ? null : [
            'heading' => $this->text($data, 'heading', 200),
            'description' => $this->text($data, 'description', 600),
            'form' => $form,
            'payload' => $payload,
        ];
    }
}
