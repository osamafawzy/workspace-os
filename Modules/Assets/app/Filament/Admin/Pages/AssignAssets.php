<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Modules\Assets\Actions\AssignAssets as AssignAssetsAction;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetAssignment;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Assign Assets.
 *
 * Pick the employee, gather what they are getting — scanned, searched, or
 * arriving from an asset's own Assign button — and hand it all over in one
 * go. The handover form opens straight after, ready to print and sign.
 */
class AssignAssets extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'assign-assets';

    protected static ?string $slug = 'assign-assets';

    protected static ?string $title = 'Assign Assets';

    protected string $view = 'assets::filament.pages.assign-assets';

    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    /** @var list<int> the assets to hand over */
    public array $selected = [];

    public string $search = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('assign-assets');
    }

    public function mount(): void
    {
        $this->form->fill(['employee_id' => $this->employeeId]);

        // Arriving from an asset's own Assign button.
        $asset = request()->integer('asset');

        if ($asset) {
            $this->add($asset);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->placeholder('Search by name or OID')
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
                    ->live()
                    ->afterStateUpdated(fn (mixed $state) => $this->employeeId = filled($state) ? (int) $state : null),

                Textarea::make('notes')
                    ->label('Notes for the form')
                    ->rows(2)
                    ->maxLength(2000)
                    ->placeholder('Printed on the handover form, e.g. "Charger and bag included".'),
            ]);
    }

    public function employee(): ?Employee
    {
        return $this->employeeId
            ? Employee::query()->with(['department', 'site', 'location'])->find($this->employeeId)
            : null;
    }

    /** @return Collection<int, Asset> what the employee holds now */
    public function heldAssets(): Collection
    {
        return $this->employeeId
            ? Asset::query()->with(['assetType', 'assetModel.manufacturer'])->where('employee_id', $this->employeeId)->orderBy('assigned_at')->get()
            : collect();
    }

    /** @return Collection<int, AssetAssignment> */
    public function recentAssignments(): Collection
    {
        return $this->employeeId
            ? AssetAssignment::query()->with(['asset.assetType', 'handoverForm'])->where('employee_id', $this->employeeId)->latest('assigned_at')->limit(10)->get()
            : collect();
    }

    /** @return Collection<int, Asset> */
    public function selectedAssets(): Collection
    {
        return Asset::query()->with(['assetType', 'assetModel.manufacturer', 'location'])->whereKey($this->selected)->orderBy('serial_number')->get();
    }

    /** @return Collection<int, Asset> assets free to hand over, matching the search */
    public function availableAssets(): Collection
    {
        return self::assignable()
            ->with(['assetType', 'assetModel.manufacturer', 'location'])
            ->whereKeyNot($this->selected)
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->search($this->search))
            ->orderBy('serial_number')
            ->limit(15)
            ->get();
    }

    /** The search box's Enter: a scanned label adds its asset straight away. */
    public function scan(): void
    {
        $term = mb_strtolower(trim($this->search));

        if ($term === '') {
            return;
        }

        $asset = Asset::query()
            ->where(fn (Builder $query) => $query->whereRaw('lower(serial_number) = ?', [$term])->orWhereRaw('lower(asset_tag) = ?', [$term]))
            ->first();

        if (! $asset) {
            $matches = $this->availableAssets();

            if ($matches->count() === 1) {
                $asset = $matches->first();
            } else {
                return;
            }
        }

        $this->add($asset->getKey());
        $this->search = '';
    }

    public function add(int $assetId): void
    {
        $asset = Asset::query()->find($assetId);

        if (! $asset) {
            return;
        }

        if (! $asset->isAssignable()) {
            Notification::make()
                ->title("{$asset->serial_number} cannot be handed out")
                ->body($asset->employee_id ? 'It is with somebody already; return it first.' : 'It is '.mb_strtolower($asset->status->getLabel()).'.')
                ->warning()
                ->send();

            return;
        }

        $this->selected = array_values(array_unique([...$this->selected, $asset->getKey()]));
    }

    public function remove(int $assetId): void
    {
        $this->selected = array_values(array_diff($this->selected, [$assetId]));
    }

    public function assign(): void
    {
        abort_unless(Gate::allows('assign-assets'), 403);

        $employee = $this->employee();

        if (! $employee) {
            Notification::make()->title('Choose the employee first')->warning()->send();

            return;
        }

        try {
            $form = app(AssignAssetsAction::class)->handle($employee, $this->selected, auth()->user(), $this->data['notes'] ?? null);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Nothing was assigned')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title(count($this->selected).' asset(s) assigned to '.$employee->name)
            ->body("Handover form {$form->number} is ready to print.")
            ->success()
            ->send();

        $this->redirect(PrintHandoverForm::getUrl(['form' => $form->getKey()]));
    }

    /** @return Builder<Asset> */
    public static function assignable(): Builder
    {
        return Asset::query()
            ->whereNull('employee_id')
            ->whereIn('status', [AssetStatus::Available, AssetStatus::Returned]);
    }
}
