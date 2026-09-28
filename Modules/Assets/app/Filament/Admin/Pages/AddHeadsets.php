<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Filament\Pages\ImportData;
use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Actions\AssignAssets as AssignAssetsAction;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Support\AssetFields;
use Modules\Assets\Imports\HeadsetImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Adding New Headsets Data.
 *
 * One at a time, fast: pick the headset type and model and where they are
 * going once, then scan serial after serial — "Save and add another" keeps
 * everything but the labels. A box of them comes in by spreadsheet instead,
 * through the headset import, which reports what went in, what was a
 * duplicate and what failed.
 */
class AddHeadsets extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'headsets';

    protected static ?string $slug = 'headsets';

    protected static ?string $title = 'Adding New Headsets Data';

    protected string $view = 'assets::filament.pages.add-headsets';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', Asset::class) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'asset_type_id' => AssetType::defaultHeadsetType()->getKey(),
            'condition' => AssetCondition::New->value,
            'status' => AssetStatus::Available->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model(Asset::class)
            ->components([
                Section::make('Headset')
                    ->columns(3)
                    ->schema([
                        Select::make('asset_type_id')
                            ->label('Headset type')
                            ->options(fn (): array => AssetType::query()->headsets()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('asset_model_id', null)),

                        Select::make('asset_model_id')
                            ->label('Model')
                            ->options(fn (Get $get): array => AssetModel::query()
                                ->with('manufacturer')
                                ->where('asset_type_id', $get('asset_type_id'))
                                ->where('is_active', true)
                                ->get()
                                ->mapWithKeys(fn (AssetModel $model): array => [$model->getKey() => $model->fullName()])
                                ->sort()
                                ->all())
                            ->searchable(),

                        AssetFields::condition()->required(),

                        AssetFields::serial()->extraInputAttributes(['data-headset-serial' => true]),
                        AssetFields::tag(),
                        AssetFields::purchaseDate(),
                    ]),

                Section::make('Cord')
                    ->description('The cord in the box with it. Leave it empty when there is none.')
                    ->columns(3)
                    ->schema(AssetFields::cord()),

                Section::make('Where they go')
                    ->columns(3)
                    ->schema([
                        AssetFields::site(),
                        AssetFields::location(),
                        AssetFields::account(),
                    ]),

                // Handing it straight to somebody, rather than to the store.
                // It goes out through the same action as the Assign screen, so
                // there is a form to sign either way.
                Section::make('Hand it over')
                    ->description('Optional. Leave it empty and the headset goes into the store.')
                    ->visible(fn (): bool => Gate::allows('assign-assets'))
                    ->columns(3)
                    ->schema([
                        Select::make('assign_to')
                            ->label('Give it to')
                            ->placeholder('Nobody — into the store')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Employee::query()
                                ->where('status', '!=', EmployeeStatus::Left)
                                ->where(fn (Builder $query) => $query
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('oid', 'like', '%'.$search.'%'))
                                ->orderBy('name')
                                ->limit(25)
                                ->get()
                                ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->auditLabel()])
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Employee::query()->find($value)?->auditLabel())
                            ->helperText('Search by name or OID. A handover form is made to sign, as on the Assign Assets screen.')
                            ->columnSpan(2),

                        Textarea::make('handover_notes')
                            ->label('Notes for the form')
                            ->rows(2)
                            ->maxLength(2000)
                            ->placeholder('e.g. "Cord and pouch included".'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload a spreadsheet')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => auth()->user()?->can('import', Asset::class) ?? false)
                ->url(fn (): string => ImportData::getUrl(['importer' => HeadsetImporter::key()])),
        ];
    }

    public function save(bool $another = false): void
    {
        abort_unless(auth()->user()?->can('create', Asset::class), 403);

        $data = $this->form->getState();

        // Only a headset type, whatever the browser sent.
        abort_unless(AssetType::query()->headsets()->whereKey($data['asset_type_id'] ?? null)->exists(), 422);

        $asset = Asset::query()->create([
            ...collect($data)->only(['asset_type_id', 'asset_model_id', 'serial_number', 'asset_tag', 'condition', 'site_id', 'location_id', 'account_id', 'purchase_date', 'cord_model', 'cord_serial', 'cord_condition'])->all(),
            'status' => AssetStatus::Available,
        ]);

        $this->handOver($asset, $data['assign_to'] ?? null, $data['handover_notes'] ?? null);

        if ($another) {
            // Same type, model, place and date; new labels, and nobody to give
            // the next one to until it is said again.
            $this->form->fill([...$data, 'serial_number' => null, 'asset_tag' => null, 'cord_serial' => null, 'assign_to' => null, 'handover_notes' => null]);
            $this->dispatch('headset-saved');

            return;
        }

        $this->form->fill([
            'asset_type_id' => $data['asset_type_id'],
            'condition' => AssetCondition::New->value,
        ]);
    }

    /**
     * Gives the headset just added to somebody, when one was named.
     *
     * Through the Assign action itself, so the assignment record, the history
     * and the form to sign are the ones the Assign screen makes. The headset
     * stays in the store if the handover is refused: it is registered either
     * way, and what went wrong is said rather than swallowed.
     */
    protected function handOver(Asset $asset, mixed $employeeId, ?string $notes): void
    {
        $employee = filled($employeeId) ? Employee::query()->find($employeeId) : null;

        if (! $employee) {
            Notification::make()->title("Headset {$asset->serial_number} added")->success()->send();

            return;
        }

        abort_unless(Gate::allows('assign-assets'), 403);

        try {
            $form = app(AssignAssetsAction::class)->handle($employee, [$asset->getKey()], auth()->user(), $notes);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title("Headset {$asset->serial_number} added, but not handed over")
                ->body(collect($exception->errors())->flatten()->implode(' ').' It is in the store; use Assign Assets.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title("Headset {$asset->serial_number} added and given to {$employee->name}")
            ->body("Handover form {$form->number} is ready to print.")
            ->success()
            ->persistent()
            ->actions([
                Action::make('print')
                    ->label('Print the form')
                    ->button()
                    ->url(PrintHandoverForm::getUrl(['form' => $form->getKey()]), shouldOpenInNewTab: true)
                    ->close(),
            ])
            ->send();
    }

    /** @return Collection<int, Asset> the latest headsets, newest first */
    public function recentHeadsets(): Collection
    {
        return Asset::query()
            ->with(['assetType', 'assetModel.manufacturer', 'location'])
            ->whereHas('assetType', fn (Builder $type) => $type->where('is_headset', true))
            ->latest('id')
            ->limit(10)
            ->get();
    }
}
