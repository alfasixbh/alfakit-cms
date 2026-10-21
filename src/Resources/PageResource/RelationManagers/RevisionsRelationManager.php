<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\PageResource\RelationManagers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Events\PageRevisionRestored;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\PageRevision;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\PageRevisions;
use AlfaSix\AlfakitCms\Support\PreviewLinks;
use AlfaSix\AlfakitCms\Support\RevisionDiff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

/**
 * The saved versions of a page, newest first, each with a preview link and a restore
 * action (the `restoreRevision` ability on the page).
 */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $icon = 'history';

    protected static bool $isLazy = false;

    public static function getTitle(): string
    {
        return __('alfakit-cms::cms.revisions.title');
    }

    public function getModelLabel(): string
    {
        return __('alfakit-cms::cms.revisions.label');
    }

    public function getPluralModelLabel(): string
    {
        return __('alfakit-cms::cms.revisions.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CmsAuthorization::allows('update', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('alfakit-cms::cms.revisions.number'))
                    ->formatStateUsing(static fn (mixed $state): string => '#'.(is_scalar($state) ? (string) $state : ''))
                    ->weight('medium'),
                TextColumn::make('title')
                    ->label(__('alfakit-cms::cms.fields.title'))
                    ->state(static fn (PageRevision $record): string => $record->getTitle())
                    ->description(static fn (PageRevision $record): ?string => $record->note),
                TextColumn::make('author')
                    ->label(__('alfakit-cms::cms.revisions.author'))
                    ->state(static fn (PageRevision $record): string => $record->getAuthorName() ?? __('alfakit-cms::cms.revisions.unknown_author')),
                TextColumn::make('created_at')
                    ->label(__('alfakit-cms::cms.revisions.saved_at'))
                    ->since(),
            ])
            ->recordActions([
                Action::make('previewRevision')
                    ->label(__('alfakit-cms::cms.actions.preview'))
                    ->icon('eye')
                    ->color(Color::Gray)
                    ->visible(static fn (): bool => Route::has(PreviewLinks::ROUTE))
                    ->url(fn (PageRevision $record): ?string => Route::has(PreviewLinks::ROUTE) ? PreviewLinks::url($this->getCmsPage(), $record) : null, shouldOpenInNewTab: true),
                Action::make('compareRevision')
                    ->label(__('alfakit-cms::cms.compare.action'))
                    ->icon('git-compare')
                    ->color(Color::Gray)
                    ->modalHeading(static fn (PageRevision $record): string => __('alfakit-cms::cms.compare.heading', ['number' => $record->number]))
                    // Modal content, not a description: an action with neither a form, a
                    // confirmation nor content opens no modal, so "Compare" did nothing.
                    ->modalContent(fn (PageRevision $record): array => [Html::make(new HtmlString('<ul class="list-disc ps-5 text-start">'.implode('', array_map(
                        static fn (string $change): string => '<li>'.e($change).'</li>',
                        RevisionDiff::describe(PageRevisions::snapshot($this->getCmsPage()->withDraft()), $record->data),
                    )).'</ul>'))])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('alfakit-cms::cms.compare.close')),
                Action::make('restoreRevision')
                    ->label(__('alfakit-cms::cms.revisions.restore'))
                    ->icon('rotate-ccw')
                    ->color(Color::Warning)
                    ->requiresConfirmation()
                    ->modalHeading(static fn (PageRevision $record): string => __('alfakit-cms::cms.revisions.restore_heading', ['number' => $record->number]))
                    ->modalDescription(__('alfakit-cms::cms.revisions.restore_description'))
                    ->modalSubmitActionLabel(__('alfakit-cms::cms.revisions.restore'))
                    ->authorize(fn (): bool => CmsAuthorization::allows('restoreRevision', $this->getCmsPage()))
                    ->action(function (PageRevision $record): void {
                        $page = PageRevisions::restore($record);

                        PageRevisionRestored::dispatch($page, $record);
                    })
                    ->successNotificationTitle(__('alfakit-cms::cms.notifications.restored'))
                    ->after(fn (Action $action) => $action->sendSuccessNotification())
                    ->successRedirectUrl(fn (): string => CmsPlugin::get()->getPageResource()::getUrl('edit', ['record' => $this->getCmsPage()])),
            ])
            ->defaultSort('number', 'desc')
            ->emptyStateHeading(__('alfakit-cms::cms.revisions.empty'));
    }

    protected function getCmsPage(): Page
    {
        $page = $this->getOwnerRecord();

        if (! $page instanceof Page) {
            abort(404);
        }

        return $page;
    }
}
