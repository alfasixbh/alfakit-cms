<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Redirect;
use AlfaSix\AlfakitCms\Resources\RedirectResource\Pages;
use AlfaSix\AlfakitCms\Rules\NotRedirectLoop;
use AlfaSix\AlfakitCms\Rules\SafeUrl;
use AlfaSix\AlfakitCms\Rules\UniqueRedirectSource;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Redirects from old paths (or path prefixes ending in `*`) to pages or URLs, created
 * and edited in modals. Published pages that move get one automatically.
 */
class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static ?string $slug = 'cms/redirects';

    protected static ?string $recordTitleAttribute = 'from_path';

    protected static ?string $navigationIcon = 'split';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static bool $isDiscovered = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('alfakit-cms::cms.redirects.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.redirects.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.redirects.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return CmsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = CmsPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 2;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return CmsAuthorization::allows($action, $record ?? Redirect::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::getFormComponents())->columns(2);
    }

    /**
     * @return array<Component>
     */
    public static function getFormComponents(): array
    {
        return [
            TextInput::make('from_path')
                ->label(__('alfakit-cms::cms.redirects.from'))
                ->helperText(__('alfakit-cms::cms.redirects.from_help'))
                ->required()
                ->maxLength(512)
                ->placeholder('/old-page')
                ->live(onBlur: true)
                ->rule(static fn (?Model $record): UniqueRedirectSource => new UniqueRedirectSource($record instanceof Redirect ? $record : null, alfakit()->getTenant()))
                ->columnSpanFull(),
            TextInput::make('to_url')
                ->label(__('alfakit-cms::cms.redirects.to'))
                ->helperText(__('alfakit-cms::cms.redirects.to_help'))
                ->required()
                ->maxLength(2048)
                ->placeholder('/new-page')
                ->rule(new SafeUrl)
                ->rule(static fn (Get $get): NotRedirectLoop => new NotRedirectLoop($get('from_path')))
                ->columnSpanFull(),
            Select::make('status_code')
                ->label(__('alfakit-cms::cms.redirects.status_code'))
                ->options(static::getStatusCodeOptions())
                ->default(301)
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label(__('alfakit-cms::cms.redirects.active'))
                ->default(true)
                ->inline(false),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getStatusCodeOptions(): array
    {
        return [
            301 => __('alfakit-cms::cms.redirects.codes.301'),
            302 => __('alfakit-cms::cms.redirects.codes.302'),
            307 => __('alfakit-cms::cms.redirects.codes.307'),
            308 => __('alfakit-cms::cms.redirects.codes.308'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('alfakit-cms::cms.redirects.from'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->wrap(),
                TextColumn::make('to_url')
                    ->label(__('alfakit-cms::cms.redirects.to'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->wrap(),
                TextColumn::make('status_code')
                    ->label(__('alfakit-cms::cms.redirects.status_code'))
                    ->badge()
                    ->color(static fn (mixed $state): string => in_array((int) $state, [301, 308], true) ? 'info' : 'gray')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('alfakit-cms::cms.redirects.active'))
                    ->boolean(),
                TextColumn::make('hits')
                    ->label(__('alfakit-cms::cms.redirects.hits'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('last_hit_at')
                    ->label(__('alfakit-cms::cms.redirects.last_hit'))
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('alfakit-cms::cms.redirects.active')),
                SelectFilter::make('status_code')->label(__('alfakit-cms::cms.redirects.status_code'))->options(static::getStatusCodeOptions()),
            ])
            ->recordActions([
                EditAction::make()->schema(static fn (): array => static::getFormComponents()),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('from_path')
            ->emptyStateHeading(__('alfakit-cms::cms.redirects.empty_heading'))
            ->emptyStateDescription(__('alfakit-cms::cms.redirects.empty_description'));
    }

    public static function makeCreateAction(): CreateAction
    {
        return CreateAction::make()
            ->schema(static fn (): array => static::getFormComponents())
            ->mutateDataUsing(static fn (array $data): array => [...$data, 'tenant_id' => alfakit()->getTenant()?->getKey()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageRedirects::route('/'),
        ];
    }
}
