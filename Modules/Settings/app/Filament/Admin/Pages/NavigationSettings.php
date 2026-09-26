<?php

namespace Modules\Settings\Filament\Admin\Pages;

use App\Support\Audit\AuditLogger;
use App\Support\Navigation\HasConfigurableNavigation;
use App\Support\Navigation\Navigation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Settings → Navigation: rename, reorder, regroup or hide sidebar entries.
 *
 * Only what differs from the defaults in config/navigation.php is stored, so a
 * label nobody has touched keeps following the code when the code changes.
 * Hiding an entry takes it out of the sidebar; it does not lock anyone out —
 * who may open a screen is still up to roles and permissions.
 */
class NavigationSettings extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'navigation';

    protected static ?string $slug = 'settings/navigation';

    protected static ?string $title = 'Navigation';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $navigation = app(Navigation::class);
        $data = ['groups' => [], 'items' => []];

        foreach ($navigation->groups() as $key => $group) {
            $data['groups'][self::field($key)] = [
                'label' => $group['label'],
                'sort' => $group['sort'],
                'collapsed' => $group['collapsed'],
            ];
        }

        foreach ($navigation->items() as $key => $item) {
            $data['items'][self::field($key)] = [
                'label' => $item['label'],
                'group' => $item['group'],
                'sort' => $item['sort'],
                'hidden' => $item['hidden'],
            ];
        }

        $this->form->fill($data);
    }

    /**
     * Keys use dashes, which read badly as form state paths, so the fields
     * use underscores and are mapped back on save.
     */
    protected static function field(string $key): string
    {
        return str_replace('-', '_', $key);
    }

    public function form(Schema $schema): Schema
    {
        $groupOptions = collect(config('navigation.groups'))
            ->map(fn (array $group, string $key): string => app(Navigation::class)->groups()[$key]['label'])
            ->all();

        $groupSections = [];

        foreach (config('navigation.groups') as $key => $defaults) {
            $groupSections[] = Grid::make(3)->schema([
                TextInput::make('groups.'.self::field($key).'.label')
                    ->label('Label')
                    ->placeholder($defaults['label'])
                    ->required()
                    ->maxLength(60),
                TextInput::make('groups.'.self::field($key).'.sort')
                    ->label('Order')
                    ->numeric()
                    ->integer()
                    ->required(),
                Toggle::make('groups.'.self::field($key).'.collapsed')
                    ->label('Start collapsed')
                    ->inline(false),
            ]);
        }

        $itemRows = [];

        foreach (config('navigation.items') as $key => $defaults) {
            $itemRows[] = Grid::make(4)->schema([
                TextInput::make('items.'.self::field($key).'.label')
                    ->label('Label')
                    ->placeholder($defaults['label'])
                    ->helperText(isset($defaults['phase']) ? 'Planned — phase '.$defaults['phase'] : null)
                    ->required()
                    ->maxLength(60),
                Select::make('items.'.self::field($key).'.group')
                    ->label('Group')
                    ->options($groupOptions)
                    ->placeholder('Top level')
                    // Dashboard is the one entry outside a group; grouped
                    // entries cannot carry icons, so moving them out would
                    // leave them without one.
                    ->disabled(($defaults['group'] ?? null) === null)
                    ->required(($defaults['group'] ?? null) !== null),
                TextInput::make('items.'.self::field($key).'.sort')
                    ->label('Order')
                    ->numeric()
                    ->integer()
                    ->required(),
                Toggle::make('items.'.self::field($key).'.hidden')
                    ->label('Hidden')
                    ->inline(false),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Groups')
                    ->description('The modules in the sidebar. Lower order comes first.')
                    ->schema($groupSections),

                Section::make('Entries')
                    ->description('Each screen in the sidebar. Order is within its group.')
                    ->schema($itemRows),
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to defaults')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Every label, order, group and hidden entry goes back to how it ships.')
                ->action(fn () => $this->resetToDefaults()),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $navigation = app(Navigation::class);
        $before = $navigation->overrides();
        $overrides = ['groups' => [], 'items' => []];

        foreach (config('navigation.groups') as $key => $defaults) {
            $row = $state['groups'][self::field($key)] ?? [];
            $overrides['groups'][$key] = array_filter([
                'label' => ($row['label'] ?? null) !== $defaults['label'] ? ($row['label'] ?? null) : null,
                'sort' => (int) ($row['sort'] ?? 0) !== (int) ($defaults['sort'] ?? 0) ? (int) $row['sort'] : null,
                'collapsed' => ! empty($row['collapsed']) ? true : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        foreach (config('navigation.items') as $key => $defaults) {
            $row = $state['items'][self::field($key)] ?? [];
            $group = $row['group'] ?? ($defaults['group'] ?? null);

            $overrides['items'][$key] = array_filter([
                'label' => ($row['label'] ?? null) !== $defaults['label'] ? ($row['label'] ?? null) : null,
                'group' => ($defaults['group'] ?? null) !== null && $group !== $defaults['group'] ? $group : null,
                'sort' => (int) ($row['sort'] ?? 0) !== (int) ($defaults['sort'] ?? 0) ? (int) $row['sort'] : null,
                'hidden' => ! empty($row['hidden']) ? true : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $overrides = [
            'groups' => array_filter($overrides['groups']),
            'items' => array_filter($overrides['items']),
        ];

        $navigation->saveOverrides($overrides);

        if ($before !== $overrides) {
            app(AuditLogger::class)->log('updated', 'Settings', null, $before, $overrides, 'Sidebar navigation');
        }

        Notification::make()->title('Saved')->success()->send();

        // The sidebar is drawn outside this page, so it changes on reload.
        $this->redirect(static::getUrl());
    }

    public function resetToDefaults(): void
    {
        abort_unless(static::canAccess(), 403);

        $navigation = app(Navigation::class);
        $before = $navigation->overrides();

        $navigation->saveOverrides([]);

        app(AuditLogger::class)->log('reset', 'Settings', null, $before, [], 'Sidebar navigation');

        Notification::make()->title('Sidebar reset to defaults')->success()->send();

        $this->redirect(static::getUrl());
    }
}
