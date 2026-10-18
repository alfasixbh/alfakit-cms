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
use AlfaSix\AlfakitCms\Models\Template;
use AlfaSix\AlfakitCms\Resources\TemplateResource\Pages;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Page templates: blocks a new page can start from ("Start from a template" on the
 * create page, "Save as template" on the editor).
 */
class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;

    protected static ?string $slug = 'cms/templates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'layout-template';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    protected static bool $isDiscovered = false;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('alfakit-cms::cms.templates_library.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.templates_library.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.templates_library.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return CmsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = CmsPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 4;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return CmsAuthorization::allows($action, $record ?? Template::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make()
                ->description(__('alfakit-cms::cms.templates_library.help'))
                ->schema([
                    TextInput::make('name')->label(__('alfakit-cms::cms.menus.name'))->required()->maxLength(120),
                    TextInput::make('description')->label(__('alfakit-cms::cms.fields.description'))->maxLength(255),
                    PageBuilder::make('content'),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('alfakit-cms::cms.menus.name'))->searchable()->sortable()->weight('medium')->description(static fn (Template $record): ?string => $record->description),
                TextColumn::make('updated_at')->label(__('alfakit-cms::cms.fields.updated_at'))->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()])
            ->emptyStateHeading(__('alfakit-cms::cms.templates_library.empty_heading'))
            ->emptyStateDescription(__('alfakit-cms::cms.templates_library.help'))
            ->emptyStateActions([CreateAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTemplates::route('/'),
            'create' => Pages\CreateTemplate::route('/create'),
            'edit' => Pages\EditTemplate::route('/{record}/edit'),
        ];
    }
}
