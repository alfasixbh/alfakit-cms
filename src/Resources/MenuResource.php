<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Menu;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Resources\MenuResource\Pages;
use AlfaSix\AlfakitCms\Rules\SafeUrl;
use AlfaSix\AlfakitCms\Rules\UniqueMenuHandle;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\Menus;
use Illuminate\Database\Eloquent\Model;

/**
 * Navigation menus: nested items (up to three levels) linking to pages or URLs, dragged
 * into order. Share them with the site with `CmsPlugin::sharedMenus()` or `Cms::menu()`.
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static ?string $slug = 'cms/menus';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'menu';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static bool $isDiscovered = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('alfakit-cms::cms.menus.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.menus.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.menus.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return CmsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = CmsPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 1;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return CmsAuthorization::allows($action, $record ?? Menu::class);
    }

    public static function form(Schema $schema): Schema
    {
        $plugin = CmsPlugin::get();

        return $schema->components([
            Section::make(__('alfakit-cms::cms.menus.details'))->schema([
                TextInput::make('name')
                    ->label(__('alfakit-cms::cms.menus.name'))
                    ->required()
                    ->maxLength(120),
                TextInput::make('handle')
                    ->label(__('alfakit-cms::cms.menus.handle'))
                    ->helperText(__('alfakit-cms::cms.menus.handle_help'))
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->rule(static fn (?Model $record, Get $get): UniqueMenuHandle => new UniqueMenuHandle($record instanceof Menu ? $record : null, $get('locale'), alfakit()->getTenant())),
                Select::make('locale')
                    ->label(__('alfakit-cms::cms.fields.locale'))
                    ->placeholder(__('alfakit-cms::cms.menus.all_locales'))
                    ->options($plugin->getLocales())
                    ->native(false)
                    ->live()
                    ->visible($plugin->isMultilingual()),
            ])->columns(3),
            Section::make(__('alfakit-cms::cms.menus.items'))
                ->description(__('alfakit-cms::cms.menus.items_help'))
                ->schema([
                    static::itemsRepeater('items', 1),
                ]),
        ])->columns(1);
    }

    /**
     * A level of menu items; levels below `Menus::MAX_DEPTH` hold a nested repeater.
     */
    public static function itemsRepeater(string $name, int $depth): Repeater
    {
        $fields = [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('label')
                    ->label(__('alfakit-cms::cms.menus.item_label'))
                    ->helperText(__('alfakit-cms::cms.menus.item_label_help'))
                    ->required(static fn (Get $get): bool => $get('type') !== 'page')
                    ->maxLength(120),
                ToggleButtons::make('type')
                    ->label(__('alfakit-cms::cms.menus.item_type'))
                    ->options([
                        'page' => __('alfakit-cms::cms.menus.type_page'),
                        'url' => __('alfakit-cms::cms.menus.type_url'),
                    ])
                    ->default('page')
                    ->inline()
                    ->required()
                    ->live(),
                Select::make('page_id')
                    ->label(__('alfakit-cms::cms.menus.page'))
                    ->options(static fn (Get $get): array => static::getPageOptions($get('locale', isAbsolute: true)))
                    ->searchable()
                    ->required(static fn (Get $get): bool => $get('type') === 'page')
                    ->visible(static fn (Get $get): bool => $get('type') === 'page'),
                TextInput::make('url')
                    ->label(__('alfakit-cms::cms.menus.url'))
                    ->placeholder('https://')
                    ->maxLength(2048)
                    ->rule(new SafeUrl)
                    ->required(static fn (Get $get): bool => $get('type') === 'url')
                    ->visible(static fn (Get $get): bool => $get('type') === 'url'),
                Toggle::make('new_tab')
                    ->label(__('alfakit-cms::cms.fields.new_tab')),
            ]),
        ];

        if ($depth < Menus::MAX_DEPTH) {
            $fields[] = static::itemsRepeater('children', $depth + 1)
                ->label(__('alfakit-cms::cms.menus.children'))
                ->collapsed();
        }

        return Repeater::make($name)
            ->hiddenLabel($depth === 1)
            ->schema($fields)
            ->itemLabel(static fn (array $state): ?string => is_string($state['label'] ?? null) && $state['label'] !== '' ? $state['label'] : null)
            ->reorderable()
            ->collapsible()
            ->cloneable()
            ->defaultItems(0)
            ->maxItems(50)
            ->addActionLabel($depth === 1 ? __('alfakit-cms::cms.menus.add_item') : __('alfakit-cms::cms.menus.add_child'))
            ->columnSpanFull();
    }

    /**
     * @return array<int, string>
     */
    public static function getPageOptions(mixed $locale): array
    {
        $plugin = CmsPlugin::get();

        $pages = CmsPlugin::get()->getPageResource()::getEloquentQuery()
            ->when($plugin->isMultilingual() && is_string($locale) && $locale !== '', static fn ($query) => $query->where('locale', $locale))
            ->orderBy('path')
            ->limit(500)
            ->get()
            ->all();

        return PageResource::toOptions($pages, static fn (Page $page): string => $page->title.' — /'.$page->path);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('alfakit-cms::cms.menus.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('handle')
                    ->label(__('alfakit-cms::cms.menus.handle'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('locale')
                    ->label(__('alfakit-cms::cms.fields.locale'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? __('alfakit-cms::cms.menus.all_locales') : strtoupper($state))
                    ->visible(CmsPlugin::get()->isMultilingual()),
                TextColumn::make('items_count')
                    ->label(__('alfakit-cms::cms.menus.items'))
                    ->state(static fn (Menu $record): int => static::countItems($record->items ?? []))
                    ->numeric(),
                TextColumn::make('updated_at')
                    ->label(__('alfakit-cms::cms.fields.updated_at'))
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('alfakit-cms::cms.menus.empty_heading'))
            ->emptyStateDescription(__('alfakit-cms::cms.menus.empty_description'));
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public static function countItems(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (is_array($item)) {
                $count += 1 + static::countItems(is_array($item['children'] ?? null) ? $item['children'] : []);
            }
        }

        return $count;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}
