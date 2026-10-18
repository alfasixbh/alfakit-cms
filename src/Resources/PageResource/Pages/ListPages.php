<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Resources\PageResource\Pages;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Enums\PageStatus;
use AlfaSix\AlfakitCms\Resources\PageResource;
use Illuminate\Database\Eloquent\Builder;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public static function getResource(): string
    {
        return CmsPlugin::get()->getPageResource();
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make()->label(__('alfakit-cms::cms.tabs.all')),
            'published' => Tab::make()->label(__('alfakit-cms::cms.states.published'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PageStatus::Published->value)->where(static fn (Builder $query) => $query->whereNull('publish_at')->orWhere('publish_at', '<=', now()))),
            'scheduled' => Tab::make()->label(__('alfakit-cms::cms.states.scheduled'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PageStatus::Published->value)->where('publish_at', '>', now())),
            'draft' => Tab::make()->label(__('alfakit-cms::cms.states.draft'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('status', PageStatus::Draft->value)),
            ...(CmsPlugin::get()->hasReview() ? [
                'review' => Tab::make()->label(__('alfakit-cms::cms.review.states.pending'))
                    ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('review->status', 'pending')),
            ] : []),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
