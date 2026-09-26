<?php

namespace Modules\Assets\Imports;

use App\Models\User;
use App\Support\Import\ImportColumn;
use App\Support\Import\Importer;
use App\Support\Import\ImportRunner;
use App\Support\Import\ParsesDates;
use App\Support\Import\RowCheck;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Lookup;
use Modules\Settings\Models\Site;

/**
 * Assets from a spreadsheet: a stock take, a supplier's delivery note, the old
 * system's export.
 *
 * A row is matched to an asset by serial number. Types, manufacturers and
 * models are named as the sheet names them and found — or created, with the
 * option on — in the catalogue; sites, locations and accounts likewise in
 * Settings. The holder is given by OID and must already be an employee:
 * inventing people from an asset sheet is how a register fills with typos.
 *
 * The headings match the export, so an export can be corrected and imported
 * back onto the same assets.
 */
class AssetImporter extends Importer
{
    use ParsesDates;

    /** @var array<string, mixed> */
    protected array $memo = [];

    /** @var array<string, string> tags claimed by rows so far => row key */
    protected array $tags = [];

    public static function key(): string
    {
        return 'assets';
    }

    public static function label(): string
    {
        return 'Assets';
    }

    public function authorize(User $user): bool
    {
        return $user->can('import', Asset::class);
    }

    public function columns(): array
    {
        return [
            new ImportColumn('asset_type', 'Asset Type', required: true, aliases: ['Type', 'Category', 'Device Type', 'Asset Category'], example: 'Laptop'),
            new ImportColumn('manufacturer', 'Manufacturer', aliases: ['Make', 'Brand', 'Vendor'], example: 'Dell'),
            new ImportColumn('model', 'Model', aliases: ['Model Name', 'Asset Model', 'Model Number'], example: 'Latitude 5440'),
            new ImportColumn('serial_number', 'Serial Number', required: true, aliases: ['Serial', 'Serial No', 'S/N', 'SN', 'Service Tag'], example: '5CG1234XYZ'),
            new ImportColumn('asset_tag', 'Asset Tag', aliases: ['Tag', 'Asset Number', 'Asset No', 'Asset ID', 'Barcode'], example: 'AT-000123'),
            new ImportColumn('computer_name', 'Computer Name', aliases: ['Hostname', 'PC Name', 'Device Name', 'Computer'], example: 'ALX-LT-0123'),
            new ImportColumn('status', 'Status', aliases: ['Asset Status', 'State'], example: 'Available'),
            new ImportColumn('condition', 'Condition', aliases: ['Asset Condition'], example: 'Good'),
            new ImportColumn('site', 'Site', aliases: ['Site Name'], example: 'Alexandria'),
            new ImportColumn('location', 'Location', aliases: ['Location Name', 'Room', 'Store'], example: 'IT Store Room'),
            new ImportColumn('account', 'Account', aliases: ['Program', 'Client', 'LOB', 'Project'], example: 'Telecom Client A'),
            new ImportColumn('employee_oid', 'Assigned To (OID)', aliases: ['OID', 'Employee OID', 'Assigned To', 'Assignee OID', 'User OID', 'Holder OID'], example: ''),
            new ImportColumn('purchase_date', 'Purchase Date', aliases: ['Purchased', 'Date Purchased', 'Invoice Date', 'Received Date'], example: '2024-01-15'),
            new ImportColumn('warranty_expires_at', 'Warranty Expiry', aliases: ['Warranty End', 'Warranty End Date', 'Warranty Until', 'Warranty Expiration', 'Warranty'], example: '2027-01-15'),
            new ImportColumn('notes', 'Notes', aliases: ['Comments', 'Remarks', 'Description'], example: ''),
        ];
    }

    public function optionFields(): array
    {
        return [
            Toggle::make('create_missing')
                ->label('Create asset types, manufacturers, models, sites, locations and accounts that do not exist yet')
                ->helperText('Off: a row naming one that does not exist is marked invalid instead.')
                ->default(true),
        ];
    }

    public function defaultOptions(): array
    {
        return ['create_missing' => true, 'existing' => ImportRunner::EXISTING_SKIP];
    }

    public function returnUrl(): ?string
    {
        return AssetResource::getUrl('index');
    }

    public function prepare(array $options): void
    {
        $this->memo = [];
        $this->tags = [];
    }

    public function check(array $row, RowCheck $check, array $options): void
    {
        $create = (bool) ($options['create_missing'] ?? true);
        $updating = ($options['existing'] ?? ImportRunner::EXISTING_SKIP) === ImportRunner::EXISTING_UPDATE;

        // ---- which asset ----------------------------------------------------
        $serial = $row['serial_number'];

        if ($serial === '') {
            $check->error('Serial number is empty.');
        } elseif (mb_strlen($serial) > 100) {
            $check->error('Serial number is longer than 100 characters.');
        } else {
            $check->key = mb_strtolower($serial);
            $check->existing = $this->remember('asset:'.$check->key, fn () => Asset::query()->whereRaw('lower(serial_number) = ?', [$check->key])->first());
        }

        if ($row['asset_tag'] !== '') {
            $tagKey = mb_strtolower($row['asset_tag']);
            $holder = $this->remember('tag:'.$tagKey, fn () => Asset::query()->whereRaw('lower(asset_tag) = ?', [$tagKey])->first());

            if ($holder && ! $holder->is($check->existing)) {
                $check->error("Asset tag {$row['asset_tag']} is already on {$holder->serial_number}.");
            }

            if (isset($this->tags[$tagKey]) && $this->tags[$tagKey] !== $check->key) {
                $check->error("Asset tag {$row['asset_tag']} is also on another row of this file.");
            }

            $this->tags[$tagKey] ??= (string) $check->key;
        }

        // ---- what it is ------------------------------------------------------
        $type = null;

        if ($row['asset_type'] === '') {
            $check->error('Asset type is empty.');
        } else {
            $type = $this->lookup(AssetType::class, $row['asset_type']);

            if (! $type) {
                $create
                    ? $check->warning("Asset type \"{$row['asset_type']}\" will be created.")
                    : $check->error("Asset type \"{$row['asset_type']}\" does not exist. Add it under Settings first.");
            }
        }

        if ($row['manufacturer'] !== '' && ! $this->lookup(Manufacturer::class, $row['manufacturer'])) {
            $create
                ? $check->warning("Manufacturer \"{$row['manufacturer']}\" will be created.")
                : $check->error("Manufacturer \"{$row['manufacturer']}\" does not exist.");
        }

        if ($row['model'] !== '') {
            $models = $this->models($row['model'], $row['manufacturer']);

            if ($models->count() > 1) {
                $check->error("More than one manufacturer makes a model called \"{$row['model']}\". Add a Manufacturer column to say which.");
            } elseif ($models->isEmpty()) {
                if ($row['manufacturer'] === '') {
                    $check->error("Model \"{$row['model']}\" does not exist. Give its manufacturer so it can be created.");
                } elseif ($create) {
                    $check->warning("Model \"{$row['manufacturer']} {$row['model']}\" will be created.");
                } else {
                    $check->error("Model \"{$row['manufacturer']} {$row['model']}\" does not exist.");
                }
            } elseif ($row['asset_type'] !== '' && (int) $models->first()->asset_type_id !== (int) $type?->getKey()) {
                // Also when the named type does not exist yet: a new type can
                // never be the one an existing model already is.
                $check->error("{$models->first()->fullName()} is a {$models->first()->assetType?->name}, not a {$row['asset_type']}.");
            }
        }

        // ---- state ----------------------------------------------------------
        if ($row['status'] !== '' && ! AssetStatus::fromLoose($row['status'])) {
            $check->error("Status \"{$row['status']}\" is not one of: ".collect(AssetStatus::cases())->map->getLabel()->implode(', ').'.');
        }

        if ($row['condition'] !== '' && ! AssetCondition::fromLoose($row['condition'])) {
            $check->error("Condition \"{$row['condition']}\" is not one of: ".collect(AssetCondition::cases())->map->getLabel()->implode(', ').'.');
        }

        $employee = null;

        if ($row['employee_oid'] !== '') {
            $employee = $this->remember('employee:'.mb_strtolower($row['employee_oid']), fn () => Employee::query()->where('oid', $row['employee_oid'])->first());

            if (! $employee) {
                $check->error("There is no employee with OID {$row['employee_oid']}. Import or add them first.");
            }
        } elseif (AssetStatus::fromLoose($row['status']) === AssetStatus::Assigned) {
            $check->error('An assigned asset needs the OID of who has it.');
        }

        $purchased = $this->dateField($row['purchase_date'], 'Purchase date', $check);
        $warranty = $this->dateField($row['warranty_expires_at'], 'Warranty expiry', $check);

        if ($purchased && $warranty && $warranty->lt($purchased)) {
            $check->error('The warranty ends before the purchase date.');
        }

        // ---- where ----------------------------------------------------------
        foreach (['site' => Site::class, 'account' => Account::class] as $field => $model) {
            if ($row[$field] !== '' && ! $this->lookup($model, $row[$field])) {
                $create
                    ? $check->warning(ucfirst($field)." \"{$row[$field]}\" will be created.")
                    : $check->error(ucfirst($field)." \"{$row[$field]}\" does not exist.");
            }
        }

        if ($row['location'] !== '') {
            $site = $row['site'] !== '' ? $this->lookup(Site::class, $row['site']) : null;
            $count = ($row['site'] !== '' && ! $site) ? 0 : Location::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($row['location'])])
                ->when($site, fn ($query) => $query->where('site_id', $site->getKey()))
                ->count();

            if ($count > 1) {
                $check->error("More than one site has a location called \"{$row['location']}\". Add a Site column to say which.");
            } elseif ($count === 0) {
                $create && $row['site'] !== ''
                    ? $check->warning("Location \"{$row['location']}\" will be created at {$row['site']}.")
                    : $check->error("Location \"{$row['location']}\" does not exist".($row['site'] !== '' ? " at {$row['site']}" : '').'.');
            }
        }

        foreach (['asset_tag' => 100, 'computer_name' => 100, 'asset_type' => 100, 'manufacturer' => 100, 'model' => 100, 'site' => 100, 'location' => 100, 'account' => 100, 'notes' => 5000] as $field => $limit) {
            if (mb_strlen($row[$field]) > $limit) {
                $check->error("{$field} is longer than {$limit} characters.");
            }
        }

        if ($check->existing && $updating && ! $check->hasErrors()) {
            $check->warning('Updates the existing asset. Empty cells leave its current values alone.');
        }
    }

    public function save(array $row, ?Model $existing, array $options): Model
    {
        $type = $this->lookupOrCreate(AssetType::class, $row['asset_type']);
        $values = ['asset_type_id' => $type->getKey()];

        if ($row['model'] !== '') {
            $model = $this->models($row['model'], $row['manufacturer'])->first();

            if (! $model) {
                $manufacturer = $this->lookupOrCreate(Manufacturer::class, $row['manufacturer']);
                $model = AssetModel::query()->create([
                    'manufacturer_id' => $manufacturer->getKey(),
                    'asset_type_id' => $type->getKey(),
                    'name' => $row['model'],
                    'is_active' => true,
                ]);
                $this->memo = array_filter($this->memo, fn (string $key): bool => ! str_starts_with($key, 'models:'), ARRAY_FILTER_USE_KEY);
            }

            $values['asset_model_id'] = $model->getKey();
        }

        foreach (['asset_tag', 'computer_name', 'notes'] as $field) {
            if ($row[$field] !== '') {
                $values[$field] = $row[$field];
            }
        }

        if ($row['status'] !== '') {
            $values['status'] = AssetStatus::fromLoose($row['status']);
        }

        if ($row['condition'] !== '') {
            $values['condition'] = AssetCondition::fromLoose($row['condition']);
        }

        foreach (['site' => Site::class, 'account' => Account::class] as $field => $model) {
            if ($row[$field] !== '') {
                $values["{$field}_id"] = $this->lookupOrCreate($model, $row[$field])->getKey();
            }
        }

        if ($row['location'] !== '') {
            $site = $row['site'] !== '' ? $this->lookupOrCreate(Site::class, $row['site']) : null;
            $values['location_id'] = (Location::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($row['location'])])
                ->when($site, fn ($query) => $query->where('site_id', $site->getKey()))
                ->first()
                ?? Location::query()->create(['site_id' => $site?->getKey(), 'name' => $row['location'], 'is_active' => true]))->getKey();
        }

        if ($row['employee_oid'] !== '') {
            $values['employee_id'] = Employee::query()->where('oid', $row['employee_oid'])->value('id');
        }

        foreach (['purchase_date', 'warranty_expires_at'] as $field) {
            if ($row[$field] !== '') {
                $values[$field] = $this->parseDate($row[$field]);
            }
        }

        unset($this->memo['asset:'.mb_strtolower($row['serial_number'])]);

        if ($existing instanceof Asset) {
            $existing->update($values);

            return $existing;
        }

        return Asset::query()->create(['serial_number' => $row['serial_number'], ...$values]);
    }

    /**
     * Models called this, made by the named manufacturer when there is one.
     *
     * @return Collection<int, AssetModel>
     */
    protected function models(string $name, string $manufacturer): Collection
    {
        return $this->remember('models:'.mb_strtolower($manufacturer).'|'.mb_strtolower($name), function () use ($name, $manufacturer) {
            $maker = $manufacturer !== '' ? $this->lookup(Manufacturer::class, $manufacturer) : null;

            if ($manufacturer !== '' && ! $maker) {
                return collect();
            }

            return AssetModel::query()
                ->with(['manufacturer', 'assetType'])
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($maker, fn ($query) => $query->where('manufacturer_id', $maker->getKey()))
                ->get();
        }) ?? collect();
    }

    /** @param  class-string<Lookup>  $model */
    protected function lookup(string $model, string $value): ?Lookup
    {
        return $this->remember(class_basename($model).':'.mb_strtolower($value), fn () => $model::query()
            ->where(fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($value)])->orWhereRaw('lower(code) = ?', [mb_strtolower($value)]))
            ->first());
    }

    /** @param  class-string<Lookup>  $model */
    protected function lookupOrCreate(string $model, string $value): Lookup
    {
        $found = $this->lookup($model, $value);

        if ($found) {
            return $found;
        }

        unset($this->memo[class_basename($model).':'.mb_strtolower($value)]);

        return $model::query()->create(['name' => $value, 'is_active' => true]);
    }

    /**
     * @template T
     *
     * @param  callable(): (T|null)  $find
     * @return T|null
     */
    protected function remember(string $key, callable $find): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $found = $find();
            $this->memo[$key] = ($found instanceof Collection ? $found : ($found ?? false));
        }

        $value = $this->memo[$key];

        return $value instanceof Collection ? $value : ($value ?: null);
    }
}
