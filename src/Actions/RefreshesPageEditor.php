<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Run from the page editor, reload it afterwards so the form shows the new state.
 *
 * @mixin \AlfaSix\Alfakit\Actions\Action
 */
trait RefreshesPageEditor
{
    protected function refreshesPageEditor(): void
    {
        $this->successRedirectUrl(function (?Model $record): ?string {
            $context = $this->getContext();
            $page = $record instanceof Page ? $record : ($record?->getAttribute('page') ?? null);

            if (! $page instanceof Page || ! is_object($context) || ! method_exists($context, 'getRecord') || ! $context->getRecord() instanceof Page || ! $context->getRecord()->is($page)) {
                return null;
            }

            return CmsPlugin::get()->getPageResource()::getUrl('edit', ['record' => $page]);
        });
    }
}
