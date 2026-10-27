<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitCms\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\CodeEditor;
use AlfaSix\Alfakit\Forms\Components\ColorPicker;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\AlfakitCms\CmsPlugin;
use AlfaSix\AlfakitCms\Models\SiteSettings;
use AlfaSix\AlfakitCms\Support\CmsAuthorization;
use AlfaSix\AlfakitCms\Support\SiteDesign;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **Site design**: the public site's brand colours, fonts, corner radius, logo and
 * favicon, and, with `customCode()`, its custom CSS and code snippets. Editing needs the
 * `update` ability on `SiteSettings`; the code section also needs `customCode()` to allow
 * the user. Each save of the code keeps the previous version under **Code history**.
 */
class ManageSiteDesign extends Page
{
    protected static ?string $navigationIcon = 'palette';

    protected static ?string $slug = 'site-design';

    public static function getNavigationLabel(): string
    {
        return __('alfakit-cms::cms.design.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return CmsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = CmsPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 5;
    }

    public static function canAccess(): bool
    {
        return CmsPlugin::get()->hasSiteDesign() && CmsAuthorization::allows('update', SiteSettings::class);
    }

    public function getTitle(): string
    {
        return __('alfakit-cms::cms.design.title');
    }

    public function getSubheading(): ?string
    {
        return __('alfakit-cms::cms.design.subheading');
    }

    protected function getFormOperation(): string
    {
        return 'edit';
    }

    public function getSettings(): SiteSettings
    {
        return SiteSettings::forTenant(alfakit()->getTenant());
    }

    protected function canEditCode(): bool
    {
        $user = $this->getPanel()->auth()->user();

        return CmsPlugin::get()->canEditCustomCode($user instanceof Model ? $user : null);
    }

    public function form(Schema $schema): Schema
    {
        $plugin = CmsPlugin::get();
        $fonts = collect(SiteDesign::FONTS)->map(static fn (array $font, string $key): string => $key === 'system' ? __('alfakit-cms::cms.design.fonts.system') : $font['label'])->all();
        $radii = collect(SiteDesign::RADII)->map(static fn (string $value, string $key): string => __("alfakit-cms::cms.design.radii.{$key}"))->all();
        $color = static fn (string $name): ColorPicker => ColorPicker::make($name)
            ->label(__("alfakit-cms::cms.design.fields.{$name}"))
            ->helperText(__("alfakit-cms::cms.design.fields.{$name}_help"))
            ->hex()
            ->live()
            ->regex('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
        $image = static fn (string $name): FileUpload => FileUpload::make($name)
            ->label(__("alfakit-cms::cms.design.fields.{$name}"))
            ->helperText(__("alfakit-cms::cms.design.fields.{$name}_help"))
            ->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'])
            ->disk($plugin->getDisk())
            ->directory($plugin->getDirectory().'/site')
            ->visibility('public')
            ->maxSize(2048);

        return $schema->components([
            Section::make(__('alfakit-cms::cms.design.sections.brand'))
                ->description(__('alfakit-cms::cms.design.sections.brand_description'))
                ->columns(2)
                ->schema([$image('logo'), $image('favicon')]),
            Section::make(__('alfakit-cms::cms.design.sections.colors'))
                ->description(__('alfakit-cms::cms.design.sections.colors_description'))
                ->columns(2)
                ->schema([
                    ...array_map($color, SiteDesign::COLORS),
                    Callout::make()
                        ->columnSpanFull()
                        ->warning()
                        ->description(fn (Get $get): ?string => $this->contrastWarning($get('background'), $get('text')))
                        ->visible(fn (Get $get): bool => $this->contrastWarning($get('background'), $get('text')) !== null),
                ]),
            Section::make(__('alfakit-cms::cms.design.sections.typography'))
                ->columns(3)
                ->schema([
                    Select::make('heading_font')->label(__('alfakit-cms::cms.design.fields.heading_font'))->options($fonts)->placeholder(__('alfakit-cms::cms.design.fonts.default')),
                    Select::make('body_font')->label(__('alfakit-cms::cms.design.fields.body_font'))->options($fonts)->placeholder(__('alfakit-cms::cms.design.fonts.default')),
                    Select::make('radius')->label(__('alfakit-cms::cms.design.fields.radius'))->options($radii)->placeholder(__('alfakit-cms::cms.design.fonts.default')),
                ]),
            Section::make(__('alfakit-cms::cms.design.sections.code'))
                ->description(__('alfakit-cms::cms.design.sections.code_description'))
                ->visible(fn (): bool => $this->canEditCode())
                ->collapsible()
                ->columns(1)
                ->schema([
                    Callout::make()
                        ->warning()
                        ->heading(__('alfakit-cms::cms.design.code_warning_heading'))
                        ->description(__('alfakit-cms::cms.design.code_warning')),
                    CodeEditor::make('css')
                        ->label(__('alfakit-cms::cms.design.fields.css'))
                        ->helperText(__('alfakit-cms::cms.design.fields.css_help'))
                        ->language('css')
                        ->maxLength(SiteDesign::MAX_CODE)
                        ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (is_string($value) && ($problem = SiteDesign::cssProblem($value)) !== null) {
                                $fail(__("alfakit-cms::cms.design.css_problems.{$problem}"));
                            }
                        }),
                    CodeEditor::make('head')
                        ->label(__('alfakit-cms::cms.design.fields.head'))
                        ->helperText(__('alfakit-cms::cms.design.fields.head_help'))
                        ->language('html')
                        ->maxLength(SiteDesign::MAX_CODE),
                    CodeEditor::make('body')
                        ->label(__('alfakit-cms::cms.design.fields.body'))
                        ->helperText(__('alfakit-cms::cms.design.fields.body_help'))
                        ->language('html')
                        ->maxLength(SiteDesign::MAX_CODE),
                ]),
        ]);
    }

    public function contrastWarning(mixed $background, mixed $text): ?string
    {
        $background = SiteDesign::hex($background);
        $text = SiteDesign::hex($text);

        if ($background === null || $text === null || SiteDesign::contrast($background, $text) >= 4.5) {
            return null;
        }

        return __('alfakit-cms::cms.design.low_contrast', ['ratio' => number_format(SiteDesign::contrast($background, $text), 2)]);
    }

    protected function fillFormState(Schema $form): void
    {
        $settings = $this->getSettings();
        $data = SiteDesign::normalize($settings->design ?? []);

        if ($this->canEditCode()) {
            $data = [...$data, ...Arr::only($settings->code ?? [], ['css', 'head', 'body'])];
        }

        $form->fill($data);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('alfakit-cms::cms.design.save'))
                ->color(Color::Primary)
                ->button()
                ->keyBindings(['mod+s'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->fillForm(fn (): array => $this->getForm()?->getRawState() ?? [])
                ->action(fn (array $data): mixed => $this->save($data)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('codeHistory')
                ->label(__('alfakit-cms::cms.design.history.label'))
                ->icon('history')
                ->color(Color::Gray)
                ->visible(fn (): bool => $this->canEditCode() && ($this->getSettings()->code_history ?? []) !== [])
                ->modalHeading(__('alfakit-cms::cms.design.history.heading'))
                ->modalDescription(__('alfakit-cms::cms.design.history.description'))
                ->modalSubmitActionLabel(__('alfakit-cms::cms.design.history.restore'))
                ->form([
                    Select::make('version')
                        ->label(__('alfakit-cms::cms.design.history.version'))
                        ->options(fn (): array => $this->historyOptions())
                        ->required(),
                ])
                ->action(fn (array $data): mixed => $this->restoreCode((int) $data['version'])),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function historyOptions(): array
    {
        $options = [];

        foreach ($this->getSettings()->code_history ?? [] as $index => $version) {
            $when = is_string($version['saved_at'] ?? null) ? Carbon::parse($version['saved_at'])->timezone(config('app.timezone'))->isoFormat('lll') : '';
            $options[$index] = trim($when.(isset($version['by']) && $version['by'] !== '' ? ' · '.$version['by'] : ''));
        }

        return $options;
    }

    /**
     * Save the design (and the code, for people who may change it).
     *
     * @param  array<string, mixed>  $data  Validated form data
     */
    public function save(array $data): mixed
    {
        abort_unless(static::canAccess(), 403);

        DB::transaction(function () use ($data): void {
            $settings = $this->getSettings();
            $settings->design = SiteDesign::normalize($data);

            if ($this->canEditCode()) {
                $this->writeCode($settings, Arr::only($data, ['css', 'head', 'body']));
            }

            $settings->save();
        });

        Notification::make()->success()->title(__('alfakit-cms::cms.design.saved'))->send();

        return back();
    }

    protected function restoreCode(int $index): mixed
    {
        abort_unless(static::canAccess() && $this->canEditCode(), 403);

        $settings = $this->getSettings();
        $version = ($settings->code_history ?? [])[$index] ?? null;

        abort_unless(is_array($version), 404);

        $this->writeCode($settings, Arr::only($version, ['css', 'head', 'body']));
        $settings->save();

        Notification::make()->success()->title(__('alfakit-cms::cms.design.history.restored'))->send();

        return back();
    }

    /**
     * Store new code and keep the current one in the history (who saved it and when).
     *
     * @param  array<string, mixed>  $code
     */
    protected function writeCode(SiteSettings $settings, array $code): void
    {
        $code = array_map(static fn (mixed $value): string => is_string($value) ? $value : '', [
            'css' => $code['css'] ?? '',
            'head' => $code['head'] ?? '',
            'body' => $code['body'] ?? '',
        ]);

        $current = array_map(static fn (mixed $value): string => is_string($value) ? $value : '', [
            'css' => $settings->code['css'] ?? '',
            'head' => $settings->code['head'] ?? '',
            'body' => $settings->code['body'] ?? '',
        ]);

        if ($code === $current) {
            return;
        }

        // The version being replaced, with who saved it and when.
        if (implode('', $current) !== '') {
            $history = $settings->code_history ?? [];
            array_unshift($history, [...$current, 'saved_at' => $settings->code['saved_at'] ?? null, 'by' => $settings->code['by'] ?? '']);
            $settings->code_history = array_slice($history, 0, SiteSettings::HISTORY_LIMIT);
        }

        $user = $this->getPanel()->auth()->user();
        $by = $user instanceof Model ? (string) ($user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getKey()) : '';

        $settings->code = [...$code, 'by' => $by, 'saved_at' => now()->toIso8601String()];
    }
}
