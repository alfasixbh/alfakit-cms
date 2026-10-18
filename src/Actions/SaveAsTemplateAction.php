<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\Page;
use AlfaSix\AlfakitCms\Models\Template;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Save the page's blocks as a template new pages can start from.
 */
class SaveAsTemplateAction extends Action
{
    public static function make(string $name = 'saveAsTemplate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit-cms::cms.actions.save_as_template'));
        $this->icon('layout-template');
        $this->color(Color::Gray);
        $this->modalHeading(__('alfakit-cms::cms.actions.save_as_template'));
        $this->modalDescription(__('alfakit-cms::cms.actions.save_as_template_description'));
        $this->modalWidth('md');
        $this->fillForm(static fn (mixed $record): array => ['name' => $record instanceof Page ? $record->title : '']);
        $this->schema([
            TextInput::make('name')->label(__('alfakit-cms::cms.menus.name'))->required()->maxLength(120),
            TextInput::make('description')->label(__('alfakit-cms::cms.fields.description'))->maxLength(255),
        ]);
        $this->successNotificationTitle(__('alfakit-cms::cms.notifications.template_saved'));
        $this->authorize(static fn (): bool => CmsAuthorization::allows('create', Template::class));
        $this->visible(static fn (?Model $record): bool => CmsPlugin::get()->hasTemplateLibrary() && $record instanceof Page);
        $this->action(static function (Page $record, array $data): void {
            Template::query()->create([
                'tenant_id' => $record->tenant_id,
                'name' => (string) $data['name'],
                'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
                'content' => $record->withDraft()->content,
            ]);
        });
        $this->after(fn () => $this->sendSuccessNotification());
    }
}
