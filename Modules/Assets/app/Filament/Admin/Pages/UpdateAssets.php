<?php

namespace Modules\Assets\Filament\Admin\Pages;

use App\Support\Navigation\HasConfigurableNavigation;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Filament\Admin\Support\AssetFields;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\UpdateReason;

/**
 * Asset Management → Update Assets: scan, change, save, scan the next one.
 *
 * Built for somebody going round with a barcode scanner or a pile of returned
 * kit: the box takes a serial number, asset tag or computer name (a scanner
 * types it and presses Enter), the asset opens with the fields that change day
 * to day, and after saving the cursor is back in the box for the next one.
 * Every change goes into the asset's history and the audit log.
 */
class UpdateAssets extends Page
{
    use HasConfigurableNavigation;

    protected static string $navigationKey = 'update-assets';

    protected static ?string $slug = 'update-assets';

    protected static ?string $title = 'Update Assets';

    protected string $view = 'assets::filament.pages.update-assets';

    /** What was typed or scanned. */
    public string $lookup = '';

    /** The asset open for updating. In the address bar, so it can be linked to. */
    #[Url(as: 'asset')]
    public ?int $assetId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var list<int> several assets the lookup could mean */
    public array $candidates = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('assets.view') && auth()->user()?->hasPermission('assets.update');
    }

    public function mount(): void
    {
        if ($this->assetId) {
            $this->open($this->assetId);
        }
    }

    public function asset(): ?Asset
    {
        return $this->assetId
            ? Asset::query()->with(AssetResource::EAGER_LOADS)->find($this->assetId)
            : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->asset() ?? Asset::class)
            ->components([
                Section::make('Status')
                    ->columns(3)
                    ->schema([
                        AssetFields::status(),
                        AssetFields::condition(),
                        AssetFields::employee(),
                    ]),

                Section::make('Where')
                    ->columns(3)
                    ->schema([
                        AssetFields::site(),
                        AssetFields::location(),
                        AssetFields::account(),
                    ]),

                Section::make('Labels')
                    ->columns(3)
                    ->schema([
                        AssetFields::serial(),
                        AssetFields::tag(),
                        AssetFields::computerName(),
                        // Read by the computer name field to decide whether
                        // this type has one; never written from here.
                        Hidden::make('asset_type_id')->dehydrated(false),
                    ]),

                Section::make('Why')
                    ->columns(3)
                    ->schema([
                        Select::make('update_reason_id')
                            ->label('Reason for the change')
                            ->options(fn (): array => UpdateReason::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->helperText('Kept on the asset\'s history. The list is Settings → Update Reasons.')
                            ->columnSpan(2)
                            // Somebody who may manage the catalogue can add a
                            // reason without leaving the screen.
                            ->createOptionForm(fn (): ?array => (auth()->user()?->can('create', UpdateReason::class) ?? false) ? [
                                TextInput::make('name')->label('Reason')->required()->maxLength(100)->unique(UpdateReason::class, 'name'),
                                TextInput::make('description')->label('Note')->maxLength(255),
                            ] : null)
                            ->createOptionUsing(function (array $data): int {
                                abort_unless(auth()->user()?->can('create', UpdateReason::class), 403);

                                return UpdateReason::query()->create([...$data, 'is_active' => true])->getKey();
                            }),

                        AssetFields::notes()->rows(2)->columnSpan(1),
                    ]),
            ]);
    }

    /** The lookup box's Enter: the one asset it names, or the choice. */
    public function find(): void
    {
        $term = trim(mb_substr($this->lookup, 0, 200));
        $this->candidates = [];

        if ($term === '') {
            return;
        }

        $lower = mb_strtolower($term);

        // A label, or an employee's OID: scanning a badge brings up everything
        // that person holds.
        $exact = Asset::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(serial_number) = ?', [$lower])
                ->orWhereRaw('lower(asset_tag) = ?', [$lower])
                ->orWhereRaw('lower(computer_name) = ?', [$lower])
                ->orWhereHas('employee', fn (Builder $employee) => $employee->whereRaw('lower(oid) = ?', [$lower])))
            ->limit(20)
            ->pluck('id');

        $matches = $exact->isNotEmpty() ? $exact : Asset::query()->search($term)->limit(20)->pluck('id');

        if ($matches->count() === 1) {
            $this->open($matches->first());

            return;
        }

        $this->closeAsset();

        if ($matches->isEmpty()) {
            Notification::make()->title("No asset matches “{$term}”")->warning()->send();

            return;
        }

        $this->candidates = $matches->all();
    }

    public function open(int $id): void
    {
        $asset = Asset::query()->find($id);

        if (! $asset) {
            $this->closeAsset();

            return;
        }

        abort_unless(auth()->user()?->can('update', $asset), 403);

        $this->assetId = $asset->getKey();
        $this->candidates = [];
        $this->form->fill($asset->attributesToArray());
    }

    public function save(): void
    {
        $asset = $this->asset() ?? abort(404);

        abort_unless(auth()->user()?->can('update', $asset), 403);

        $data = $this->form->getState();
        $before = $asset->history()->value('id');

        $asset->withReason($data['update_reason_id'] ?? null)->update(collect($data)->only([
            'status', 'condition', 'site_id', 'location_id', 'account_id',
            'serial_number', 'asset_tag', 'computer_name', 'notes',
        ])->all());

        $entry = $asset->history()->first();
        $changed = $entry && $entry->getKey() !== $before ? array_keys($entry->changes ?? []) : [];

        Notification::make()
            ->title($changed === [] ? 'Nothing had changed' : "{$asset->serial_number} updated")
            ->body($changed === [] ? null : collect($changed)->map(fn (string $field): string => Asset::TRACKED[$field] ?? $field)->implode(', '))
            ->success()
            ->send();

        // Ready for the next scan.
        $this->lookup = '';
        $this->open($asset->getKey());
        $this->dispatch('asset-saved');
    }

    public function closeAsset(): void
    {
        $this->assetId = null;
        $this->data = [];
    }

    /** @return Collection<int, Asset> */
    public function candidateAssets(): Collection
    {
        return Asset::query()->with(AssetResource::EAGER_LOADS)->whereKey($this->candidates)->orderBy('serial_number')->get();
    }
}
