<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\AlfakitCms\Rules\EmbeddableUrl;
use AlfaSix\AlfakitCms\Support\BlockContext;
use AlfaSix\AlfakitCms\Support\Embeds;

/**
 * A YouTube or Vimeo video, an `https` video file, or a page of one of the plugin's
 * `embedHosts()`. Other URLs are rejected when saving and dropped when rendering.
 */
class EmbedBlock extends CmsBlock
{
    public function getName(): string
    {
        return 'embed';
    }

    public function getIcon(): ?string
    {
        return 'square-play';
    }

    public function getGroup(): ?string
    {
        return __('alfakit-cms::cms.block_groups.media');
    }

    public function schema(): array
    {
        return [
            TextInput::make('url')
                ->label(static::label('embed_url'))
                ->helperText(__('alfakit-cms::cms.fields.embed_help'))
                ->required()
                ->maxLength(2048)
                ->rule(new EmbeddableUrl),
            TextInput::make('title')->label(static::label('embed_title'))->helperText(__('alfakit-cms::cms.fields.embed_title_help'))->required()->maxLength(200),
            TextInput::make('caption')->label(static::label('caption'))->maxLength(300),
            Select::make('aspect_ratio')
                ->label(static::label('aspect_ratio'))
                ->options(['16/9' => '16:9', '4/3' => '4:3', '1/1' => '1:1', '21/9' => '21:9', '9/16' => '9:16'])
                ->default('16/9')
                ->native(false),
        ];
    }

    public function toFrontend(array $data, BlockContext $context): ?array
    {
        $embed = Embeds::resolve($this->text($data, 'url'), $context->plugin->getEmbedHosts());

        if ($embed === null) {
            return null;
        }

        return [
            'embed' => [...$embed, 'title' => $this->text($data, 'title', 200) ?? __('alfakit-cms::cms.fields.embed_title')],
            'caption' => $this->text($data, 'caption', 300),
            'aspectRatio' => $this->choice($data, 'aspect_ratio', ['16/9', '4/3', '1/1', '21/9', '9/16'], '16/9'),
        ];
    }
}
