<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section as SchemaSection;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Forms\Components\PageBuilder;
use AlfaSix\AlfakitCms\Models\Section;
use AlfaSix\AlfakitCms\Resources\SectionResource\Pages;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable sections: blocks edited once and shown on many pages with the "Reusable
 * section" block.
 */
class SectionResource extends Resource
{
    protected static ?string $model = Section::class;

    protected static ?string $slug = 'cms/sections';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'layers';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static bool $isDiscovered = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('alfakit-cms::cms.sections_library.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.sections_library.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.sections_library.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return CmsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = CmsPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 3;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return CmsAuthorization::allows($action, $record ?? Section::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make()
                ->description(__('alfakit-cms::cms.sections_library.help'))
                ->schema([
                    TextInput::make('name')->label(__('alfakit-cms::cms.menus.name'))->required()->maxLength(120),
                    PageBuilder::make('content')->exceptBlocks(['section']),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('alfakit-cms::cms.menus.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('updated_at')->label(__('alfakit-cms::cms.fields.updated_at'))->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()])
            ->emptyStateHeading(__('alfakit-cms::cms.sections_library.empty_heading'))
            ->emptyStateDescription(__('alfakit-cms::cms.sections_library.help'))
            ->emptyStateActions([CreateAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSections::route('/'),
            'create' => Pages\CreateSection::route('/create'),
            'edit' => Pages\EditSection::route('/{record}/edit'),
        ];
    }
}
