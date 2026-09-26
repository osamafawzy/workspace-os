<?php

namespace Modules\Settings\Filament\Admin\Pages;

use App\Support\Audit\AuditLogger;
use App\Support\Branding;
use App\Support\Navigation\HasConfigurableNavigation;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Settings → Company: who this application belongs to and what it looks like.
 *
 * The logo is uploaded here and stored on the server's own disk — nothing is
 * fetched from anywhere, so the brand still shows with no internet connection.
 */
class CompanySettings extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'company';

    protected static ?string $slug = 'settings/company';

    protected static ?string $title = 'Company';

    /** @var array<string, string> form field => setting key */
    public const FIELDS = [
        'company_name' => 'branding.company_name',
        'app_name' => 'branding.app_name',
        'primary_color' => 'branding.primary_color',
        'logo' => 'branding.logo',
        'logo_dark' => 'branding.logo_dark',
        'resolve_hostnames' => AuditLogger::RESOLVE_HOSTNAMES,
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $data = [];

        foreach (self::FIELDS as $field => $key) {
            $data[$field] = $settings->get($key);
        }

        $data['company_name'] ??= Branding::DEFAULT_COMPANY;
        $data['app_name'] ??= Branding::DEFAULT_APP;
        $data['primary_color'] ??= Branding::DEFAULT_COLOR;
        $data['resolve_hostnames'] = (bool) $data['resolve_hostnames'];

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Name')
                    ->description('Shown in the sidebar, the browser tab, printed forms and the public building view.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company name')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('app_name')
                            ->label('Application name')
                            ->required()
                            ->maxLength(100),
                    ]),

                Section::make('Look')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('PNG or SVG, ideally wide and about 72 px tall. Replaces the name in the sidebar.'),

                        FileUpload::make('logo_dark')
                            ->label('Logo for dark mode')
                            ->image()
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('Optional. The normal logo is used when this is empty.'),

                        ColorPicker::make('primary_color')
                            ->label('Primary colour')
                            ->hex()
                            ->required()
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText('Buttons, links and highlights across the admin panel.'),
                    ]),

                Section::make('Audit log')
                    ->schema([
                        Toggle::make('resolve_hostnames')
                            ->label('Record the computer name of whoever made each change')
                            ->helperText('Looks the name up from the IP address on the company network. Leave off if saving starts to feel slow — a lookup that finds nothing can take a few seconds.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $settings = app(Settings::class);
        $old = [];
        $new = [];

        foreach (self::FIELDS as $field => $key) {
            $value = $data[$field] ?? null;

            if ($settings->get($key) === $value) {
                continue;
            }

            $old[$field] = $settings->get($key);
            $new[$field] = $value;
            $settings->set($key, $value);
        }

        if ($new !== []) {
            app(AuditLogger::class)->log('updated', 'Settings', null, $old, $new, 'Company settings');
        }

        Notification::make()->title('Saved')->success()->send();

        // The brand and colour are drawn by the panel around this page, so
        // they only change on a fresh load.
        $this->redirect(static::getUrl());
    }
}
