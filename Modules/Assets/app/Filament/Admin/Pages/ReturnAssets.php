<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Modules\Assets\Actions\ReturnAssets as ReturnAssetsAction;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Filament\Admin\Resources\HandoverForms\HandoverFormResource;
use Modules\Assets\Filament\Admin\Support\AssetFields;
use Modules\Assets\Models\Asset;
use Modules\Employees\Models\Employee;

/**
 * Asset Management → Return Assets.
 *
 * Find what is coming back — scan it, search for it, or list everything one
 * employee holds — say what condition each piece is in, and record the return.
 * A receipt for the employee to sign opens straight after.
 */
class ReturnAssets extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'return-assets';

    protected static ?string $slug = 'return-assets';

    protected static ?string $title = 'Return Assets';

    protected string $view = 'assets::filament.pages.return-assets';

    /** Narrows the search to what one employee holds. */
    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    /** @var array<int, string> asset id => the condition it came back in */
    public array $selected = [];

    public string $search = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('return-assets');
    }

    public function mount(): void
    {
        $this->form->fill(['returned_at' => now()->format('Y-m-d H:i:s')]);

        $asset = request()->integer('asset');

        if ($asset) {
            $this->add($asset);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model(Asset::class)
            ->components([
                Grid::make(2)->schema([
                    DateTimePicker::make('returned_at')
                        ->label('Returned')
                        ->required()
                        ->seconds(false)
                        ->maxDate(now()->endOfDay()),

                    TextInput::make('returned_by_name')
                        ->label('Brought back by')
                        ->maxLength(150)
                        ->placeholder('The employee, unless somebody else brought it'),

                    AssetFields::site()->label('Put back at site'),
                    AssetFields::location()->label('Location'),
                ]),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(2000)
                    ->placeholder('Printed on the receipt, e.g. "Charger missing".'),
            ]);
    }

    public function employee(): ?Employee
    {
        return $this->employeeId ? Employee::query()->find($this->employeeId) : null;
    }

    /** @return Collection<int, Asset> */
    public function selectedAssets(): Collection
    {
        return Asset::query()
            ->with(['assetType', 'assetModel.manufacturer', 'employee'])
            ->whereKey(array_keys($this->selected))
            ->orderBy('serial_number')
            ->get();
    }

    /** @return Collection<int, Asset> assets somebody holds, matching the search */
    public function heldAssets(): Collection
    {
        return Asset::query()
            ->with(['assetType', 'assetModel.manufacturer', 'employee'])
            ->whereNotNull('employee_id')
            ->whereKeyNot(array_keys($this->selected))
            ->when($this->employeeId, fn (Builder $query) => $query->where('employee_id', $this->employeeId))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->search($this->search))
            ->when(! $this->employeeId && trim($this->search) === '', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->orderBy('serial_number')
            ->limit(25)
            ->get();
    }

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
            $matches = $this->heldAssets();

            if ($matches->count() !== 1) {
                return;
            }

            $asset = $matches->first();
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

        if (! $asset->employee_id) {
            Notification::make()->title("{$asset->serial_number} is not with anybody")->warning()->send();

            return;
        }

        // What it went out as is the best first guess at what it comes back as.
        $this->selected[$asset->getKey()] ??= ($asset->condition ?? AssetCondition::Good)->value;
    }

    public function addAllFromEmployee(): void
    {
        if (! $this->employeeId) {
            return;
        }

        Asset::query()->where('employee_id', $this->employeeId)->pluck('id')->each(fn (int $id) => $this->add($id));
    }

    public function remove(int $assetId): void
    {
        unset($this->selected[$assetId]);
    }

    public function forEmployee(?int $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    public function recordReturn(): void
    {
        abort_unless(Gate::allows('return-assets'), 403);

        $data = $this->form->getState();

        try {
            $receipts = app(ReturnAssetsAction::class)->handle($this->selected, $data, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Nothing was returned')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $count = count($this->selected);

        Notification::make()
            ->title("{$count} asset(s) returned")
            ->body('Receipt '.$receipts->pluck('number')->implode(', ').' ready to print.')
            ->success()
            ->send();

        if ($receipts->count() === 1) {
            $this->redirect(PrintHandoverForm::getUrl(['form' => $receipts->first()->getKey()]));

            return;
        }

        // Several employees: a receipt each, listed with the rest.
        $this->redirect(HandoverFormResource::getUrl('index'));
    }

    /** @return array<string, string> */
    public function conditionOptions(): array
    {
        return collect(AssetCondition::cases())->mapWithKeys(fn (AssetCondition $condition): array => [$condition->value => $condition->getLabel()])->all();
    }
}
