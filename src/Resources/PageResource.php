<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources;

use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkActionGroup;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitCms\Actions\PreviewAction;
use AlfaSix\AlfakitCms\Actions\PublishAction;
use AlfaSix\AlfakitCms\Actions\PublishBulkAction;
use AlfaSix\AlfakitCms\Actions\UnpublishAction;
use AlfaSix\AlfakitCms\Actions\UnpublishBulkAction;
use AlfaSix\AlfakitCms\Actions\ViewLiveAction;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageState;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Forms\Components\LivePreview;
use AlfaSix\AlfakitCms\Forms\Components\PageBuilder;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\Template;
use AlfaSix\AlfakitCms\Resources\PageResource\Pages;
use AlfaSix\AlfakitCms\Resources\PageResource\RelationManagers\RevisionsRelationManager;
use AlfaSix\AlfakitCms\Rules\UniquePagePath;
use AlfaSix\AlfakitCms\Rules\ValidParent;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Seo;
use AlfaSix\AlfakitCms\Support\SeoAdvisor;
use AlfaSix\AlfakitCms\Support\Site;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The CMS pages: block content, publishing (drafts, scheduling), SEO, translations and
 * revisions. Extend it and pass your class to `CmsPlugin::make()->pageResource(...)`.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $slug = 'cms/pages';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'file-text';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static bool $isDiscovered = false;

    public static function getPlugin(): CmsPlugin
    {
        return CmsPlugin::get();
    }

    public static function getModelLabel(): string
    {
        return __('alfakit-cms::cms.pages.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.pages.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.pages.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'path'];
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Every ability goes through `CmsAuthorization` (the plugin's `authorize()` closure or
     * the `Page` policy).
     */
    public static function can(string $action, ?Model $record = null): bool
    {
        return CmsAuthorization::allows($action, $record ?? Page::class);
    }

    /**
     * Permission prefixes for alfasix/alfakit-shield: the usual resource ones plus the
     * CMS abilities (policy methods `publish()`, `submitForReview()`, `restoreRevision()`).
     * Subclass the resource and implement `HasShieldPermissions` to let Shield use them.
     *
     * @return list<string>
     */
    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'publish', 'submit_for_review', 'restore_revision'];
    }

    public static function canPublish(?Model $record = null): bool
    {
        return CmsAuthorization::allows('publish', $record ?? Page::class);
    }

    // ---------------------------------------------------------------- Form

    /**
     * One column, so nothing gets squeezed (also beside the live preview): the editor bar
     * with the status and the main actions, the page's name and web address, its blocks,
     * where it appears, search and sharing, and the settings most pages don't need under
     * "Advanced".
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            LivePreview::make(),
            Section::make(__('alfakit-cms::cms.sections.page'))->schema(static::getPageFields())->columns(1),
            Section::make(__('alfakit-cms::cms.sections.content'))
                ->description(__('alfakit-cms::cms.sections.content_description'))
                ->schema([
                    PageBuilder::make('content')->hiddenLabel(),
                ]),
            Section::make(__('alfakit-cms::cms.sections.placement'))
                ->description(__('alfakit-cms::cms.sections.placement_description'))
                ->schema(static::getPlacementFields())
                ->columns(['default' => 1, 'md' => 2]),
            Section::make(__('alfakit-cms::cms.sections.publishing'))
                ->schema(static::getPublishingFields())
                ->columns(['default' => 1, 'md' => 2])
                ->visible(static fn (string $operation): bool => $operation === 'create'),
            Section::make(__('alfakit-cms::cms.sections.seo'))
                ->description(__('alfakit-cms::cms.sections.seo_description'))
                ->collapsible()
                ->collapsed()
                ->schema(static::getSeoFields())
                ->columns(['default' => 1, 'md' => 2]),
            Section::make(__('alfakit-cms::cms.sections.advanced'))
                ->description(__('alfakit-cms::cms.sections.advanced_description'))
                ->schema(static::getAdvancedFields())
                ->columns(['default' => 1, 'md' => 2]),
        ])->columns(1);
    }

    /**
     * Start a new page from a template: code templates (`CmsPlugin::templates()`) and the
     * ones saved in the panel. Picking one fills the content.
     *
     * @return array<string, string>
     */
    public static function getTemplateOptions(): array
    {
        $options = [];

        foreach (static::getPlugin()->getTemplates() as $key => $template) {
            $options['code:'.$key] = $template['label'];
        }

        if (static::getPlugin()->hasTemplateLibrary()) {
            foreach (TemplateResource::getEloquentQuery()->orderBy('name')->limit(200)->get() as $template) {
                if ($template instanceof Template) {
                    $options['db:'.$template->getKey()] = $template->name;
                }
            }
        }

        return $options;
    }

    /**
     * The block content of a template option, or null.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function getTemplateContent(mixed $option): ?array
    {
        if (! is_string($option)) {
            return null;
        }

        if (str_starts_with($option, 'code:')) {
            $content = static::getPlugin()->getTemplates()[substr($option, 5)]['content'] ?? null;

            return is_array($content) ? array_values($content) : null;
        }

        if (str_starts_with($option, 'db:') && ctype_digit(substr($option, 3))) {
            $template = TemplateResource::getEloquentQuery()->find((int) substr($option, 3));

            return $template instanceof Template && is_array($template->content) ? array_values($template->content) : null;
        }

        return null;
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getPageFields(): array
    {
        return [
            Select::make('template')
                ->label(__('alfakit-cms::cms.fields.template'))
                ->helperText(__('alfakit-cms::cms.fields.template_help'))
                ->placeholder(__('alfakit-cms::cms.fields.blank_page'))
                ->options(static fn (): array => static::getTemplateOptions())
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(static function (Set $set, mixed $state): void {
                    if (($content = static::getTemplateContent($state)) !== null) {
                        $set('content', $content);
                    }
                })
                ->visible(static fn (string $operation): bool => $operation === 'create' && static::getTemplateOptions() !== [])
                ->columnSpanFull(),
            TextInput::make('title')
                ->label(__('alfakit-cms::cms.fields.title'))
                ->helperText(__('alfakit-cms::cms.fields.title_help'))
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(static function (Set $set, Get $get, ?string $state, string $operation): void {
                    if ($operation === 'create' && blank($get('slug'))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->label(__('alfakit-cms::cms.fields.slug'))
                ->helperText(__('alfakit-cms::cms.fields.slug_help'))
                ->required(static fn (Get $get): bool => ! $get('is_home'))
                ->hidden(static fn (Get $get): bool => (bool) $get('is_home'))
                ->maxLength(190)
                ->regex('/^[\pL\pN_-]+$/u')
                ->prefix(static fn (Get $get): string => static::getPathPrefix($get('parent_id'), $get('locale')))
                ->rule(static fn (Get $get, ?Model $record): UniquePagePath => new UniquePagePath(
                    $record instanceof Page ? $record : null,
                    $get('parent_id'),
                    static::getPlugin()->isMultilingual() ? (is_string($get('locale')) ? $get('locale') : null) : null,
                    (bool) $get('is_home'),
                    alfakit()->getTenant(),
                )),
        ];
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getPublishingFields(): array
    {
        return [
            Select::make('status')
                ->label(__('alfakit-cms::cms.fields.status'))
                ->helperText(__('alfakit-cms::cms.fields.status_help'))
                ->options([
                    PageStatus::Draft->value => __('alfakit-cms::cms.states.draft'),
                    PageStatus::Published->value => __('alfakit-cms::cms.states.published'),
                ])
                ->default(PageStatus::Draft->value)
                ->required()
                ->native(false)
                ->live()
                ->disabled(static fn (?Model $record): bool => ! static::canPublish($record)),
            DateTimePicker::make('publish_at')
                ->label(__('alfakit-cms::cms.fields.publish_at'))
                ->helperText(__('alfakit-cms::cms.fields.publish_at_help'))
                ->seconds(false)
                ->visible(static fn (Get $get): bool => $get('status') === PageStatus::Published->value || $get('status') === PageStatus::Published)
                ->disabled(static fn (?Model $record): bool => ! static::canPublish($record)),
        ];
    }

    /**
     * "Show advanced settings" and what it reveals: the translation link, the layout and
     * the order among sibling pages (the technical search settings show in their section).
     *
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getAdvancedFields(): array
    {
        $plugin = static::getPlugin();

        return [
            Toggle::make('advanced')
                ->label(__('alfakit-cms::cms.fields.advanced'))
                ->helperText(__('alfakit-cms::cms.fields.advanced_help'))
                ->dehydrated(false)
                ->live()
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getPlacementFields(): array
    {
        $plugin = static::getPlugin();

        return [
            Toggle::make('is_home')
                ->label(__('alfakit-cms::cms.fields.is_home'))
                ->helperText(__('alfakit-cms::cms.fields.is_home_help'))
                ->live(),
            Select::make('locale')
                ->label(__('alfakit-cms::cms.fields.locale'))
                ->options($plugin->getLocales())
                ->default($plugin->getDefaultLocale())
                ->required()
                ->native(false)
                ->live()
                ->visible($plugin->isMultilingual()),
            Select::make('parent_id')
                ->label(__('alfakit-cms::cms.fields.parent'))
                ->helperText(__('alfakit-cms::cms.fields.parent_help'))
                ->placeholder(__('alfakit-cms::cms.fields.no_parent'))
                ->options(static fn (?Model $record, Get $get): array => static::getParentOptions($record instanceof Page ? $record : null, $get('locale')))
                ->searchable()
                ->live()
                ->hidden(static fn (Get $get): bool => (bool) $get('is_home'))
                ->rule(static fn (?Model $record): ValidParent => new ValidParent($record instanceof Page ? $record : null, alfakit()->getTenant())),
        ];
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getSeoFields(): array
    {
        $plugin = static::getPlugin();

        return [
            Html::make(static fn (Get $get, mixed $record): HtmlString => SeoAdvisor::snippet(
                is_string($get('title')) ? $get('title') : null,
                is_array($get('seo')) ? $get('seo') : [],
                SeoAdvisor::url($record instanceof Page ? $record : null, $get('slug'), $get('parent_id'), $get('locale'), (bool) $get('is_home')),
            ))->columnSpanFull(),
            Callout::make(__('alfakit-cms::cms.seo.tips'))
                ->warning()
                ->description(static fn (Get $get): HtmlString => static::listHtml(static::seoWarnings($get)))
                ->visible(static fn (Get $get): bool => static::seoWarnings($get) !== [])
                ->columnSpanFull(),
            Group::make([
                TextInput::make('title')
                    ->label(__('alfakit-cms::cms.fields.meta_title'))
                    ->helperText(static fn (?string $state): string => __('alfakit-cms::cms.fields.meta_title_help').' '.__('alfakit-cms::cms.seo.counter', ['count' => mb_strlen((string) $state), 'limit' => SeoAdvisor::TITLE_LIMIT]))
                    ->live(debounce: 500)
                    ->maxLength(200),
                Textarea::make('description')
                    ->label(__('alfakit-cms::cms.fields.meta_description'))
                    ->helperText(static fn (?string $state): string => __('alfakit-cms::cms.fields.meta_description_help').' '.__('alfakit-cms::cms.seo.counter', ['count' => mb_strlen((string) $state), 'limit' => SeoAdvisor::DESCRIPTION_LIMIT]))
                    ->live(debounce: 500)
                    ->rows(3)
                    ->maxLength(320),
                FileUpload::make('image')
                    ->label(__('alfakit-cms::cms.fields.og_image'))
                    ->helperText(__('alfakit-cms::cms.fields.og_image_help'))
                    ->image()
                    ->disk($plugin->getDisk())
                    ->directory($plugin->getDirectory().'/seo')
                    ->visibility('public')
                    ->maxSize(5120),
            ])->statePath('seo')->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            // The technical settings, with "Show advanced settings".
            Group::make([
                TextInput::make('canonical')
                    ->label(__('alfakit-cms::cms.fields.canonical'))
                    ->helperText(__('alfakit-cms::cms.fields.canonical_help'))
                    ->url()
                    ->maxLength(2048)
                    ->dehydratedWhenHidden(),
                Select::make('schema_type')
                    ->label(__('alfakit-cms::cms.fields.schema_type'))
                    ->options(array_combine(Seo::SCHEMA_TYPES, Seo::SCHEMA_TYPES))
                    ->placeholder('WebPage')
                    ->native(false)
                    ->dehydratedWhenHidden(),
                Toggle::make('noindex')
                    ->label(__('alfakit-cms::cms.fields.noindex'))
                    ->helperText(__('alfakit-cms::cms.fields.noindex_help'))
                    ->dehydratedWhenHidden(),
            ])->statePath('seo')->columns(['default' => 1, 'md' => 2])->columnSpanFull()
                ->visible(static fn (Get $get): bool => (bool) $get('advanced')),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function seoWarnings(Get $get): array
    {
        return SeoAdvisor::warnings(is_string($get('title')) ? $get('title') : null, is_array($get('seo')) ? $get('seo') : [], $get('content'));
    }

    /**
     * @param  array<int, string>  $items
     */
    public static function listHtml(array $items): HtmlString
    {
        return new HtmlString('<ul class="list-disc ps-5">'.implode('', array_map(static fn (string $item): string => '<li>'.e($item).'</li>', $items)).'</ul>');
    }

    /**
     * "Published · changes not published yet · in review": the page's state in words.
     */
    public static function describeState(?Page $page): string
    {
        if ($page === null || ! $page->exists) {
            return PageState::Draft->getLabel();
        }

        $parts = [$page->getDisplayState()->getLabel()];

        if ($page->getState() === PageState::Scheduled && $page->publish_at !== null) {
            $parts[] = __('alfakit-cms::cms.states.scheduled_for', ['date' => $page->publish_at->toDayDateTimeString()]);
        }

        if (($review = $page->getReviewStatus()) !== null) {
            $parts[] = __('alfakit-cms::cms.review.states.'.$review);
        }

        return implode(' · ', $parts);
    }

    /**
     * The editor bar's status: a badge and a plain-language sentence ("Draft", "Not
     * visible to visitors yet."). `$changed` describes the page once its draft differs
     * from what visitors see.
     *
     * @return array{label: string, color: string, description: string}
     */
    public static function describeEditorStatus(?Page $page, bool $changed = false): array
    {
        $state = $page === null || ! $page->exists ? PageState::Draft : $page->getState();

        if ($state !== PageState::Draft && ($changed || $page->hasPendingChanges())) {
            $status = [
                'label' => PageState::ChangesPending->getLabel(),
                'color' => PageState::ChangesPending->getColor(),
                'description' => __('alfakit-cms::cms.editor.changes_pending'),
            ];
        } else {
            $status = [
                'label' => $state->getLabel(),
                'color' => $state->getColor(),
                'description' => match ($state) {
                    PageState::Scheduled => __('alfakit-cms::cms.editor.scheduled', ['date' => $page?->publish_at?->toDayDateTimeString() ?? '']),
                    PageState::Published => __('alfakit-cms::cms.editor.published'),
                    default => __('alfakit-cms::cms.editor.draft'),
                },
            ];
        }

        if (($review = $page?->getReviewStatus()) !== null) {
            $status['description'] .= ' '.__('alfakit-cms::cms.editor.review_'.$review);
        }

        return $status;
    }

    /**
     * The URL path before the slug: route prefix, locale prefix and the parent's path.
     */
    public static function getPathPrefix(mixed $parentId, mixed $locale): string
    {
        $parent = is_numeric($parentId) ? static::getEloquentQuery()->find((int) $parentId) : null;
        $page = new Page(['locale' => is_string($locale) ? $locale : null]);
        $path = Site::forPage($page)->pathFor($parent instanceof Page ? $parent->path : '', is_string($locale) ? $locale : null);

        return rtrim($path, '/').'/';
    }

    /**
     * Pages that may become the parent: same tenant and locale, not the page itself or
     * one of its descendants.
     *
     * @return array<int, string>
     */
    public static function getParentOptions(?Page $page, mixed $locale): array
    {
        $query = static::getEloquentQuery()->where('is_home', false)->orderBy('path');

        if (static::getPlugin()->isMultilingual()) {
            $query->where('locale', is_string($locale) ? $locale : static::getPlugin()->getDefaultLocale());
        }

        if ($page?->exists === true) {
            $query->whereKeyNot($page->getSelfAndDescendantKeys());
        }

        return static::toOptions($query->limit(500)->get()->all(), static fn (Page $parent): string => $parent->title.' — /'.$parent->path);
    }

    /**
     * Pages in other locales this page may translate (only pages that translate nothing
     * themselves, so groups stay flat).
     *
     * @return array<int, string>
     */
    public static function getTranslationOptions(?Page $page, mixed $locale): array
    {
        $query = static::getEloquentQuery()->whereNull('translation_of_id')->orderBy('locale')->orderBy('path');

        if (is_string($locale)) {
            $query->where('locale', '!=', $locale);
        }

        if ($page?->exists === true) {
            $query->whereKeyNot($page->getKey());
        }

        return static::toOptions($query->limit(500)->get()->all(), static fn (Page $source): string => '['.strtoupper((string) $source->locale).'] '.$source->title);
    }

    /**
     * Select options keyed by page id.
     *
     * @param  array<int, Model>  $pages
     * @param  Closure(Page): string  $label
     * @return array<int, string>
     */
    public static function toOptions(array $pages, Closure $label): array
    {
        $options = [];

        foreach ($pages as $page) {
            if ($page instanceof Page) {
                $options[(int) $page->getKey()] = $label($page);
            }
        }

        return $options;
    }

    // ---------------------------------------------------------------- Table

    public static function table(Table $table): Table
    {
        $plugin = static::getPlugin();

        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('alfakit-cms::cms.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(static fn (Page $record): string => '/'.$record->path),
                TextColumn::make('state')
                    ->label(__('alfakit-cms::cms.fields.status'))
                    ->state(static fn (Page $record): string => $record->getDisplayState()->getLabel())
                    ->badge()
                    ->color(static fn (Page $record): string => $record->getDisplayState()->getColor())
                    ->description(static fn (Page $record): ?string => $record->getState() === PageState::Scheduled ? $record->publish_at?->toDayDateTimeString() : null),
                TextColumn::make('review_status')
                    ->label(__('alfakit-cms::cms.review.column'))
                    ->state(static fn (Page $record): ?string => ($review = $record->getReviewStatus()) === null ? null : __('alfakit-cms::cms.review.states.'.$review))
                    ->badge()
                    ->color(static fn (Page $record): string => $record->getReviewStatus() === 'pending' ? 'warning' : 'danger')
                    ->visible($plugin->hasReview()),
                TextColumn::make('locale')
                    ->label(__('alfakit-cms::cms.fields.locale'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (?string $state): string => strtoupper((string) $state))
                    ->visible($plugin->isMultilingual()),
                TextColumn::make('updated_at')
                    ->label(__('alfakit-cms::cms.fields.updated_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('alfakit-cms::cms.fields.status'))
                    ->options(PageState::options())
                    ->query(static function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            PageState::Draft->value => $query->where('status', PageStatus::Draft->value),
                            PageState::Scheduled->value => $query->where('status', PageStatus::Published->value)->where('publish_at', '>', now()),
                            PageState::Published->value => $query->where('status', PageStatus::Published->value)->where(static fn (Builder $query) => $query->whereNull('publish_at')->orWhere('publish_at', '<=', now())),
                            default => $query,
                        };
                    }),
                ...($plugin->isMultilingual() ? [
                    SelectFilter::make('locale')
                        ->label(__('alfakit-cms::cms.fields.locale'))
                        ->options($plugin->getLocales()),
                ] : []),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    PreviewAction::make(),
                    ViewLiveAction::make(),
                    PublishAction::make(),
                    UnpublishAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    PublishBulkAction::make(),
                    UnpublishBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('path')
            ->emptyStateHeading(__('alfakit-cms::cms.pages.empty_heading'))
            ->emptyStateDescription(__('alfakit-cms::cms.pages.empty_description'))
            ->emptyStateIcon('file-plus')
            ->emptyStateActions([
                CreateAction::make('createFirstPage')->label(__('alfakit-cms::cms.pages.create_first'))->icon('plus'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RevisionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
