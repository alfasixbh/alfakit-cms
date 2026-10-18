<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Blocks;

use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Hidden;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Support\Url;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Forms\Components\MediaLibraryPicker;
use AlfaSix\AlfakitCms\Rules\SafeUrl;
use AlfaSix\AlfakitCms\Support\BlockContext;
use AlfaSix\AlfakitCms\Support\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * A page builder block: its form (`schema()`) and the JSON its frontend component gets
 * (`toFrontend()`). Register custom blocks with `CmsPlugin::make()->blocks([...])` and a
 * renderer component under the `cms:block:{name}` registry key in each frontend:
 *
 * ```php
 * class PricingBlock extends CmsBlock
 * {
 *     public function getName(): string
 *     {
 *         return 'pricing';
 *     }
 *
 *     public function schema(): array
 *     {
 *         return [TextInput::make('heading')->required()];
 *     }
 *
 *     public function toFrontend(array $data, BlockContext $context): array
 *     {
 *         return ['heading' => $this->text($data, 'heading')];
 *     }
 * }
 * ```
 *
 * `toFrontend()` is the only data that reaches the browser: stored block data is
 * user input, so build plain values with the helpers below (`text()` escapes nothing but
 * the renderers print it as text, `html()` sanitises, `url()` drops unsafe schemes).
 */
abstract class CmsBlock
{
    /** The data key of the "Hide this block" toggle every block gets. */
    public const HIDDEN = 'cms_hidden';

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    /** The block type stored in page content (`hero`, `pricing`). */
    abstract public function getName(): string;

    /**
     * The block's fields.
     *
     * @return array<Component>
     */
    abstract public function schema(): array;

    /**
     * The render-ready JSON of one block for the frontend renderer. Return null to skip
     * the block (an embed with an unsupported URL, an image without a file).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    abstract public function toFrontend(array $data, BlockContext $context): ?array;

    public function getLabel(): string
    {
        $key = 'alfakit-cms::cms.blocks.'.$this->getName().'.label';
        $label = __($key);

        return $label === $key ? Str::headline($this->getName()) : $label;
    }

    public function getIcon(): ?string
    {
        return null;
    }

    /** The picker group (`Content`, `Media`); null for ungrouped. */
    public function getGroup(): ?string
    {
        return null;
    }

    /** How many of this block a page may have. */
    public function getMaxItems(): ?int
    {
        return null;
    }

    /**
     * Structured data this block adds to the page's JSON-LD graph (`FAQPage` for the FAQ
     * block), from its `toFrontend()` data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function jsonLd(array $data, BlockContext $context): ?array
    {
        return null;
    }

    /**
     * The block as a Builder block of the page editor.
     */
    /**
     * A plain-language explanation shown in the block picker
     * (`alfakit-cms::cms.blocks.{name}.description` by default).
     */
    public function getDescription(): ?string
    {
        $key = 'alfakit-cms::cms.blocks.'.$this->getName().'.description';
        $description = __($key);

        return $description === $key ? null : $description;
    }

    /**
     * What the editor shows next to the block's label for an item (its heading or first
     * text), so collapsed blocks are easy to tell apart.
     *
     * @param  array<string, mixed>  $data
     */
    public function getSummary(array $data): ?string
    {
        foreach (['heading', 'title', 'question', 'plan', 'caption', 'name'] as $key) {
            if (($text = $this->text($data, $key, 60)) !== null) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The block as a Builder block of the page editor: its fields plus "Hide this block".
     */
    public function toBuilderBlock(): Block
    {
        $label = $this->getLabel();

        $block = Block::make($this->getName())
            ->label(function (?array $state) use ($label): string {
                $hidden = $state !== null && (bool) ($state[self::HIDDEN] ?? false);

                return $label.($hidden ? ' ('.__('alfakit-cms::cms.fields.hidden_badge').')' : '');
            })
            ->summary(fn (array $state): ?string => $this->getSummary($state))
            ->description($this->getDescription())
            ->icon($this->getIcon())
            ->group($this->getGroup())
            ->maxItems($this->getMaxItems())
            ->schema([
                ...$this->schema(),
                // Switched with "Hide from visitors" in the block's menu.
                Hidden::make(self::HIDDEN)->default(false),
            ]);

        if (CmsPlugin::get()->hasBlockPreviews()) {
            /** @var view-string $view */
            $view = 'alfakit-cms::block-preview';

            $block->preview(fn (array $data): View => view($view, [
                'label' => $label,
                'icon' => $this->getIcon(),
                'summary' => $this->getSummary($data),
                'hidden' => (bool) ($data[self::HIDDEN] ?? false),
            ]));
        }

        return $block;
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * A trimmed string, or null when empty.
     *
     * @param  array<string, mixed>  $data
     */
    protected function text(array $data, string $key, ?int $limit = null): ?string
    {
        $value = data_get($data, $key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit === null ? $value : Str::limit($value, $limit, '');
    }

    /**
     * Rich editor content as sanitised HTML (`Html::sanitize()`, the panel's sanitiser).
     *
     * @param  array<string, mixed>  $data
     */
    protected function html(array $data, string $key): ?string
    {
        $value = data_get($data, $key);

        if (is_array($value)) {
            $value = json_encode($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $html = trim(RichContentRenderer::make($value)->toHtml());

        return $html === '' ? null : $html;
    }

    /**
     * A safe link target (`http(s)`, `mailto:`, `tel:`, relative paths, fragments).
     *
     * @param  array<string, mixed>  $data
     */
    protected function url(array $data, string $key): ?string
    {
        return Url::sanitize(data_get($data, $key));
    }

    /**
     * A button or link: `['label' => …, 'url' => …, 'newTab' => bool]`, or null without a
     * label or a safe URL.
     *
     * @param  array<string, mixed>  $data
     * @return array{label: string, url: string, newTab: bool}|null
     */
    protected function link(array $data, string $prefix): ?array
    {
        $label = $this->text($data, "{$prefix}_label", 120);
        $url = $this->url($data, "{$prefix}_url");

        if ($label === null || $url === null) {
            return null;
        }

        return ['label' => $label, 'url' => $url, 'newTab' => (bool) data_get($data, "{$prefix}_new_tab", false)];
    }

    /**
     * An uploaded image (or an absolute image URL) with its alternative text.
     *
     * @param  array<string, mixed>  $data
     * @return array{url: string, alt: string}|null
     */
    protected function image(array $data, string $key, BlockContext $context, ?string $altKey = null): ?array
    {
        $url = Media::url(data_get($data, $key), $context->plugin->getDisk())
            ?? Media::libraryUrl(data_get($data, "{$key}_media"), $context->plugin);

        if ($url === null) {
            return null;
        }

        return ['url' => $url, 'alt' => $this->text($data, $altKey ?? "{$key}_alt", 300) ?? ''];
    }

    /**
     * One of the allowed values, else the default.
     *
     * @template T of string|int
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, T>  $allowed
     * @param  T  $default
     * @return T
     */
    protected function choice(array $data, string $key, array $allowed, string|int $default): string|int
    {
        $value = data_get($data, $key);

        foreach ($allowed as $option) {
            if ($value !== null && (string) $value === (string) $option) {
                return $option;
            }
        }

        return $default;
    }

    /**
     * The items of a repeater, as arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function items(array $data, string $key, int $limit = 100): array
    {
        $items = data_get($data, $key);

        if (! is_array($items)) {
            return [];
        }

        return array_slice(array_values(array_filter($items, is_array(...))), 0, $limit);
    }

    // ---------------------------------------------------------------- Field helpers

    /**
     * A translated field label (`alfakit-cms::cms.fields.{key}`).
     */
    protected static function label(string $key): string
    {
        return __('alfakit-cms::cms.fields.'.$key);
    }

    /**
     * Label, URL and "open in a new tab" fields stored as `{prefix}_label`, `{prefix}_url`
     * and `{prefix}_new_tab` (read them back with `link()`).
     */
    protected function linkFields(string $prefix, ?string $legend = null): Grid
    {
        return Grid::make(3)->schema([
            TextInput::make("{$prefix}_label")->label($legend ?? static::label('button_label'))->maxLength(120),
            TextInput::make("{$prefix}_url")->label(static::label('button_url'))->maxLength(2048)->rule(new SafeUrl)->placeholder('/contact'),
            Toggle::make("{$prefix}_new_tab")->label(static::label('new_tab'))->inline(false),
        ])->columnSpanFull();
    }

    /**
     * An image upload on the CMS disk.
     */
    protected function imageUpload(string $name): FileUpload
    {
        $plugin = CmsPlugin::get();

        return FileUpload::make($name)
            ->label(static::label('image'))
            ->image()
            ->disk($plugin->getDisk())
            ->directory($plugin->getDirectory())
            ->visibility('public')
            ->maxSize(10240);
    }

    /**
     * An image: an upload (with the image editor), a pick from the media library when
     * alfasix/alfakit-media-library is installed, and its alternative text. Read it back
     * with `image($data, $name, $context)`.
     *
     * @return array<Component>
     */
    protected function imageFields(string $name, bool $required = false, bool $withAlt = true): array
    {
        $library = MediaLibraryPicker::make("{$name}_media");

        $upload = $this->imageUpload($name)
            ->imageEditor()
            ->required($required ? static fn (Get $get): bool => blank($get("{$name}_media")) : false);

        return array_values(array_filter([
            $upload,
            $library,
            $withAlt ? $this->altText($name) : null,
        ]));
    }

    /**
     * The alternative text of an image (`{name}_alt`).
     */
    protected function altText(string $name): TextInput
    {
        return TextInput::make("{$name}_alt")
            ->label(static::label('alt'))
            ->helperText(__('alfakit-cms::cms.fields.alt_help'))
            ->maxLength(300);
    }
}
