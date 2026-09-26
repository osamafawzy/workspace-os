<?php

namespace Modules\Assets\Imports;

use App\Support\Import\ImportColumn;
use App\Support\Import\ImportRunner;
use App\Support\Import\RowCheck;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Pages\AddHeadsets;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\Manufacturer;
use Modules\Employees\Models\Employee;

/**
 * Headsets in bulk, in the shape the ops sheet is kept in.
 *
 * The template is that sheet: OID, Name, Headset Model, Headsets S/N, Headset
 * Status, the cord's model, serial and status, Site, Received Date, Account.
 * Its habits are taken as read — a dash for "none", the manufacturer written
 * into the model ("Jabra BIZ 1500 Direct USB"), the site by its code ("ALX"),
 * and one word for a headset's state whether it names a status or a condition.
 *
 * Everything underneath is the asset import's — serials matched and duplicates
 * caught, models and places found or created, holders by OID — so a headset is
 * checked exactly like any other asset, and a sheet with the asset headings
 * ("Serial Number", "Manufacturer", "Asset Tag"…) still imports.
 */
class HeadsetImporter extends AssetImporter
{
    /** What the office writes when a headset came without a cord. */
    private const NONE = ['-', '--', 'n/a', 'na', 'none', 'nil', 'no', 'no cord', 'without cord'];

    protected ?AssetType $headsetType = null;

    /** @var array<string, string> cord serials claimed by rows so far => row key */
    protected array $cords = [];

    public static function key(): string
    {
        return 'headsets';
    }

    public static function label(): string
    {
        return 'Headsets';
    }

    /**
     * The ops sheet's own columns, in its own order, and then the asset
     * import's others — understood when a file carries them, left out of the
     * template so it stays the sheet the office fills in.
     */
    public function columns(): array
    {
        $others = array_filter(
            parent::columns(),
            fn (ImportColumn $column): bool => ! in_array($column->field, ['asset_type', 'model', 'serial_number', 'status', 'site', 'account', 'purchase_date', 'employee_oid'], true),
        );

        return [
            new ImportColumn('employee_oid', 'OID', aliases: ['Employee OID', 'Assigned To (OID)', 'Assignee OID', 'User OID', 'Holder OID', 'Assigned To'], example: '7654321'),
            new ImportColumn('employee_name', 'Name', aliases: ['Employee Name', 'Employee', 'Full Name', 'User Name', 'Holder'], example: 'Sara Ali'),
            new ImportColumn('model', 'Headset Model', aliases: ['Model', 'Model Name', 'Asset Model', 'Model Number', 'Headset'], example: 'Jabra BIZ 1500 Direct USB'),
            new ImportColumn('serial_number', 'Headsets S/N', required: true, aliases: ['Headset S/N', 'Headset Serial', 'Serial Number', 'Serial', 'Serial No', 'S/N', 'SN', 'Service Tag'], example: '000A1B2C3D4E'),
            new ImportColumn('status', 'Headset Status', aliases: ['Status', 'Asset Status', 'State', 'Headset Condition'], example: 'New'),
            new ImportColumn('cord_model', 'Cord Model', aliases: ['Cord', 'Cable Model', 'Cord Type', 'QD Cord'], example: 'Jabra QD to USB-A'),
            new ImportColumn('cord_serial', 'Cord S/N', aliases: ['Cord Serial', 'Cord Serial Number', 'Cable S/N', 'Cable Serial'], example: '-'),
            new ImportColumn('cord_condition', 'Cord Status', aliases: ['Cord Condition', 'Cable Status', 'Cable Condition'], example: '-'),
            new ImportColumn('site', 'Site', aliases: ['Site Name', 'Site Code'], example: 'ALX'),
            new ImportColumn('purchase_date', 'Received Date', aliases: ['Received', 'Date Received', 'Purchase Date', 'Purchased', 'Date Purchased', 'Invoice Date'], example: now()->toDateString()),
            new ImportColumn('account', 'Account', aliases: ['Program', 'Client', 'LOB', 'Project'], example: 'Comcast'),
            ...array_map(
                fn (ImportColumn $column): ImportColumn => new ImportColumn($column->field, $column->label, $column->required, $column->aliases, $column->example, inTemplate: false),
                array_values($others),
            ),
        ];
    }

    /** The holder's name is personal data; it waits encrypted like any other. */
    public function sensitiveFields(): array
    {
        return ['employee_name'];
    }

    public function optionFields(): array
    {
        return [
            Select::make('headset_type_id')
                ->label('Headset type')
                ->options(fn (): array => AssetType::query()->headsets()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->helperText('Every row is imported as this type.'),

            Toggle::make('create_missing')
                ->label('Create manufacturers, models, sites, locations and accounts that do not exist yet')
                ->default(true),
        ];
    }

    public function defaultOptions(): array
    {
        return [
            'create_missing' => true,
            'existing' => ImportRunner::EXISTING_SKIP,
            'headset_type_id' => AssetType::defaultHeadsetType()->getKey(),
        ];
    }

    public function returnUrl(array $options = []): ?string
    {
        return AddHeadsets::getUrl();
    }

    public function prepare(array $options): void
    {
        parent::prepare($options);

        $this->cords = [];
        $this->headsetType = AssetType::query()->headsets()->find($options['headset_type_id'] ?? null);
    }

    public function check(array $row, RowCheck $check, array $options): void
    {
        if (! $this->headsetType) {
            $check->error('Choose a headset type on the upload screen.');

            return;
        }

        $state = $this->isNone($row['status']) ? '' : trim($row['status']);
        $row = $this->headsetRow($row);

        parent::check($row, $check, $options);

        $this->checkState($state, $check);
        $this->checkCord($row, $check);
        $this->checkName($row, $check);
    }

    public function save(array $row, ?Model $existing, array $options): Model
    {
        $row = $this->headsetRow($row);

        /** @var Asset $asset */
        $asset = parent::save($row, $existing, $options);

        $values = [];

        if ($row['cord_model'] !== '') {
            $values['cord_model'] = $row['cord_model'];
        }

        if ($row['cord_serial'] !== '') {
            $values['cord_serial'] = $row['cord_serial'];
        }

        if ($row['cord_condition'] !== '' && $condition = AssetCondition::fromLoose($row['cord_condition'])) {
            $values['cord_condition'] = $condition;
        }

        if ($values !== []) {
            $asset->update($values);
        }

        return $asset;
    }

    /**
     * The row as the asset import wants it: the type from the upload screen,
     * dashes read as empty, the manufacturer taken out of the model, and the
     * one state word read as a status, a condition, or both.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    protected function headsetRow(array $row): array
    {
        $row = array_map(fn (string $value): string => $this->isNone($value) ? '' : $value, $row);
        $row['asset_type'] = $this->headsetType?->name ?? '';

        if ($row['model'] !== '' && $row['manufacturer'] === '') {
            [$row['manufacturer'], $row['model']] = $this->splitModel($row['model']);
        }

        // "New" is both: the headset is available, and it is new. A word that
        // is only a condition leaves the status to the asset's own default, and
        // one that is neither is reported by checkState() rather than twice.
        if ($row['status'] !== '') {
            if ($row['condition'] === '' && AssetCondition::fromLoose($row['status'])) {
                $row['condition'] = $row['status'];
            }

            if (! AssetStatus::fromLoose($row['status'])) {
                $row['status'] = '';
            }
        }

        return $row;
    }

    /**
     * "Jabra BIZ 1500 Direct USB" is a Jabra, model "BIZ 1500 Direct USB".
     *
     * A manufacturer already in the catalogue is matched however many words its
     * name is; otherwise the first word is taken as the maker, which is how the
     * sheet is written. A one-word cell is left as a model on its own.
     *
     * @return array{0: string, 1: string} manufacturer, model
     */
    protected function splitModel(string $value): array
    {
        $known = $this->remember('manufacturers', fn () => Manufacturer::query()->orderByRaw('length(name) desc')->pluck('name'));

        foreach ($known ?? [] as $name) {
            if (mb_stripos($value, $name.' ') === 0) {
                return [$name, trim(mb_substr($value, mb_strlen($name)))];
            }
        }

        return str_contains($value, ' ')
            ? [(string) str($value)->before(' '), trim((string) str($value)->after(' '))]
            : ['', $value];
    }

    /** What the one state word in the sheet turned out to mean. */
    protected function checkState(string $word, RowCheck $check): void
    {
        if ($word === '' || AssetStatus::fromLoose($word)) {
            return;
        }

        if ($condition = AssetCondition::fromLoose($word)) {
            $check->warning("Headset Status \"{$word}\" describes the state it is in, not where it is: recorded in {$condition->getLabel()} condition.");

            return;
        }

        $check->error("Headset Status \"{$word}\" is neither a status (".collect(AssetStatus::cases())->map->getLabel()->implode(', ').') nor a condition ('.collect(AssetCondition::cases())->map->getLabel()->implode(', ').').');
    }

    /** @param  array<string, string>  $row */
    protected function checkCord(array $row, RowCheck $check): void
    {
        foreach (['cord_model' => 'Cord Model', 'cord_serial' => 'Cord S/N'] as $field => $label) {
            if (mb_strlen($row[$field]) > 100) {
                $check->error("{$label} is longer than 100 characters.");
            }
        }

        if ($row['cord_condition'] !== '' && ! AssetCondition::fromLoose($row['cord_condition'])) {
            $check->error("Cord Status \"{$row['cord_condition']}\" is not one of: ".collect(AssetCondition::cases())->map->getLabel()->implode(', ').', or a dash for no cord.');
        }

        if ($row['cord_serial'] === '') {
            return;
        }

        // A cord has one headset, as a headset has one serial.
        $cordKey = mb_strtolower($row['cord_serial']);
        $onOther = $this->remember('cord:'.$cordKey, fn () => Asset::query()->whereRaw('lower(cord_serial) = ?', [$cordKey])->first());

        if ($onOther && ! $onOther->is($check->existing)) {
            $check->error("Cord S/N {$row['cord_serial']} is already on headset {$onOther->serial_number}.");
        }

        if (isset($this->cords[$cordKey]) && $this->cords[$cordKey] !== $check->key) {
            $check->error("Cord S/N {$row['cord_serial']} is also on another row of this file.");
        }

        $this->cords[$cordKey] ??= (string) $check->key;
    }

    /**
     * The name beside the OID is the office's own cross-check, so it is used as
     * one: the OID says who holds the headset, and a name that disagrees is
     * worth seeing before the import runs.
     *
     * @param  array<string, string>  $row
     */
    protected function checkName(array $row, RowCheck $check): void
    {
        if ($row['employee_name'] === '') {
            return;
        }

        if ($row['employee_oid'] === '') {
            $check->warning("\"{$row['employee_name']}\" has no OID, so the headset is not given to anybody.");

            return;
        }

        $employee = $this->remember('employee:'.mb_strtolower($row['employee_oid']), fn () => Employee::query()->where('oid', $row['employee_oid'])->first());

        if ($employee && Spreadsheet::headerKey($employee->name) !== Spreadsheet::headerKey($row['employee_name'])) {
            $check->warning("OID {$row['employee_oid']} is {$employee->name}, not \"{$row['employee_name']}\". The OID decides.");
        }
    }

    /** Whether a cell means "nothing here". */
    protected function isNone(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::NONE, true);
    }
}
