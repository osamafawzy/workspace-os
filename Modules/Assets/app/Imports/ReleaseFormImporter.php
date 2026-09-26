<?php

namespace Modules\Assets\Imports;

use App\Models\User;
use App\Support\Import\ImportColumn;
use App\Support\Import\Importer;
use App\Support\Import\ImportRunner;
use App\Support\Import\ParsesDates;
use App\Support\Import\RowCheck;
use App\Support\Spreadsheet\Spreadsheet;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\RelationManagers\ItemsRelationManager;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\ReleaseBatchItem;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;

/**
 * A whole release from the Release Data Form, in one upload.
 *
 * The form is the office's own: a line per laptop with the employee it is for,
 * the labels on the machine, and what the paperwork needs. Its rows become the
 * new data of a draft release batch — the same rows the New Data Table holds —
 * so the checks, the release and the handover forms afterwards are unchanged.
 *
 * What the batch already says (type, model, site, account) is what the release
 * uses: those columns are read as a cross-check, and a row that disagrees says
 * so on the preview rather than quietly going in as something else. Nothing is
 * written to the employees either: the OID says who a laptop is for, and the
 * name, mobile and emergency contacts beside it are checked against the record
 * that will be printed.
 */
class ReleaseFormImporter extends Importer
{
    use ParsesDates;

    protected ?ReleaseBatch $batch = null;

    /** @var array<string, mixed> */
    protected array $memo = [];

    /** @var array<string, string> serials and tags claimed by rows so far => row key */
    protected array $serials = [];

    protected array $tags = [];

    public static function key(): string
    {
        return 'release-form';
    }

    public static function label(): string
    {
        return 'Release Data Form';
    }

    public function authorize(User $user): bool
    {
        return $user->can('create', ReleaseBatch::class);
    }

    /** The form's own columns, in its own order. */
    public function columns(): array
    {
        return [
            // The line number in the sheet. Declared so "Serial" is never read
            // as the machine's serial, which the form calls the service tag.
            new ImportColumn('row_no', 'Serial', aliases: ['No', 'No.', 'Sr', 'Sr No', 'S No', 'Line', 'Item'], example: '1'),
            new ImportColumn('employee_oid', 'Employee_ID', aliases: ['OID', 'Employee OID', 'Employee ID', 'Staff ID'], example: '7654321'),
            new ImportColumn('employee_name', 'Employee_Name', aliases: ['Employee Name', 'Name', 'Full Name'], example: 'Sara Ali'),
            new ImportColumn('employee_mobile', 'Mobile_No', aliases: ['Mobile', 'Mobile Number', 'Phone', 'Phone No'], example: '01012345678'),
            new ImportColumn('computer_name', 'Laptop_Name', aliases: ['Computer Name', 'Hostname', 'PC Name', 'Device Name', 'Laptop Name'], example: 'ALX-LT-0123'),
            new ImportColumn('model', 'Laptop_Model', aliases: ['Model', 'Asset Model', 'Laptop Model'], example: 'Dell Latitude 5440'),
            new ImportColumn('ram', 'RAM', aliases: ['Memory', 'RAM Size'], example: '16 GB'),
            new ImportColumn('serial_number', 'Laptop_Service_Tag', required: true, aliases: ['Service Tag', 'Serial Number', 'Serial No', 'S/N', 'SN', 'Laptop Serial'], example: '5CG1234XYZ'),
            new ImportColumn('owner', 'Laptop_Owner', aliases: ['Owner', 'Owned By'], example: 'Concentrix'),
            new ImportColumn('account', 'Account', aliases: ['Client', 'Program'], example: 'Telecom Client A'),
            new ImportColumn('lob', 'LOB', aliases: ['Line of Business', 'Line Of Business'], example: 'Customer Care'),
            new ImportColumn('site', 'Site', aliases: ['Site Name', 'Site Code'], example: 'ALX'),
            new ImportColumn('delivery_date', 'Delivery_Date', aliases: ['Delivery Date', 'Delivered', 'Handover Date'], example: now()->toDateString()),
            new ImportColumn('emergency_contact_1', 'Emergency_Contact1', aliases: ['Emergency Contact 1', 'Emergency Contact'], example: 'Mona Ali 01098765432'),
            new ImportColumn('emergency_contact_2', 'Emergency_Contact2', aliases: ['Emergency Contact 2'], example: ''),

            // Understood when a file carries them; the form does not.
            new ImportColumn('asset_tag', 'Asset Tag', aliases: ['Tag', 'Asset Number', 'Asset No', 'Barcode'], inTemplate: false),
            new ImportColumn('condition', 'Condition', aliases: ['Asset Condition'], inTemplate: false),
            new ImportColumn('notes', 'Notes', aliases: ['Comments', 'Remarks'], inTemplate: false),
        ];
    }

    /** The employee's own details, checked and then forgotten. */
    public function sensitiveFields(): array
    {
        return ['employee_name', 'employee_mobile', 'emergency_contact_1', 'emergency_contact_2'];
    }

    public function optionFields(): array
    {
        return [
            Select::make('release_batch_id')
                ->label('Add the rows to this release')
                ->options(fn (): array => ReleaseBatch::query()
                    ->with(['assetType', 'assetModel.manufacturer'])
                    ->where('status', ReleaseBatch::DRAFT)
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (ReleaseBatch $batch): array => [$batch->getKey() => trim("{$batch->number} — {$batch->describe()}", ' —')])
                    ->all())
                ->required()
                ->searchable()
                ->helperText('Drafts only. Start the release first — its type, model, site and account are what the rows are released as.'),
        ];
    }

    public function defaultOptions(): array
    {
        return ['existing' => ImportRunner::EXISTING_UPDATE];
    }

    public function returnUrl(array $options = []): ?string
    {
        $batch = $this->batch ?? ReleaseBatch::query()->find($options['release_batch_id'] ?? null);

        return $batch
            ? ReleaseBatchResource::getUrl($batch->isDraft() ? 'edit' : 'view', ['record' => $batch])
            : ReleaseBatchResource::getUrl('index');
    }

    public function prepare(array $options): void
    {
        $this->memo = [];
        $this->serials = [];
        $this->tags = [];

        $batch = ReleaseBatch::query()->with(['assetType', 'assetModel.manufacturer', 'site', 'account'])->find($options['release_batch_id'] ?? null);

        // Only a draft this user may still work on, whatever the browser sent.
        $this->batch = $batch && $batch->isDraft() && (auth()->user()?->can('update', $batch) ?? false) ? $batch : null;
    }

    public function check(array $row, RowCheck $check, array $options): void
    {
        if (! $this->batch) {
            $check->error('Choose a draft release on the upload screen.');

            return;
        }

        $this->checkLabels($row, $check);
        $this->checkEmployee($row, $check);
        $this->checkAgainstTheBatch($row, $check);

        foreach (['ram' => 60, 'owner' => 60, 'lob' => 100, 'computer_name' => 100, 'notes' => 5000] as $field => $limit) {
            if (mb_strlen($row[$field]) > $limit) {
                $check->error(ucfirst(str_replace('_', ' ', $field))." is longer than {$limit} characters.");
            }
        }

        if ($row['condition'] !== '' && ! AssetCondition::fromLoose($row['condition'])) {
            $check->error("Condition \"{$row['condition']}\" is not one of: ".collect(AssetCondition::cases())->map->getLabel()->implode(', ').'.');
        }

        $this->dateField($row['delivery_date'], 'Delivery date', $check);

        if ($this->batch->items()->count() + count($this->serials) > ItemsRelationManager::MAX_ROWS) {
            $check->error('This release is full at '.number_format(ItemsRelationManager::MAX_ROWS).' rows. Start another for the rest.');
        }
    }

    public function save(array $row, ?Model $existing, array $options): Model
    {
        $values = [
            'computer_name' => $row['computer_name'] ?: null,
            'employee_oid' => $row['employee_oid'] ?: null,
            'asset_tag' => $row['asset_tag'] ?: null,
            'ram' => $row['ram'] ?: null,
            'owner' => $row['owner'] ?: null,
            'lob' => $row['lob'] ?: null,
            'delivery_date' => $row['delivery_date'] !== '' ? $this->parseDate($row['delivery_date']) : null,
            'notes' => $row['notes'] ?: null,
            'condition' => AssetCondition::fromLoose($row['condition']) ?? AssetCondition::New,
        ];

        if ($existing instanceof ReleaseBatchItem) {
            // Empty cells leave what is already on the row alone.
            $existing->update(array_filter($values, fn ($value): bool => filled($value)));

            return $existing;
        }

        return $this->batch->items()->create(['serial_number' => $row['serial_number'], ...$values]);
    }

    /** The machine's own labels: the service tag, and a tag if the file has one. */
    protected function checkLabels(array $row, RowCheck $check): void
    {
        $serial = $row['serial_number'];

        if ($serial === '') {
            $check->error('Laptop_Service_Tag is empty: it is the serial the asset is created with.');
        } elseif (mb_strlen($serial) > 100) {
            $check->error('Laptop_Service_Tag is longer than 100 characters.');
        } else {
            $check->key = mb_strtolower($serial);
            $check->existing = $this->remember('item:'.$check->key, fn () => $this->batch->items()->whereRaw('lower(serial_number) = ?', [$check->key])->first());

            $onAsset = $this->remember('asset:'.$check->key, fn () => Asset::query()->whereRaw('lower(serial_number) = ?', [$check->key])->first());

            if ($onAsset) {
                $check->error("Serial {$serial} is already in the register, on a {$onAsset->assetType?->name}.");
            }

            $this->serials[$check->key] ??= $check->key;
        }

        if ($row['asset_tag'] === '') {
            return;
        }

        $tagKey = mb_strtolower($row['asset_tag']);
        $onOther = $this->remember('tag:'.$tagKey, fn () => Asset::query()->whereRaw('lower(asset_tag) = ?', [$tagKey])->first());

        if ($onOther) {
            $check->error("Asset tag {$row['asset_tag']} is already on {$onOther->serial_number}.");
        }

        if (isset($this->tags[$tagKey]) && $this->tags[$tagKey] !== $check->key) {
            $check->error("Asset tag {$row['asset_tag']} is also on another row of this file.");
        }

        $this->tags[$tagKey] ??= (string) $check->key;
    }

    /**
     * Who it is for. The OID decides; the rest of the line is the office's own
     * cross-check of the record the handover form will print.
     */
    protected function checkEmployee(array $row, RowCheck $check): void
    {
        if ($row['employee_oid'] === '') {
            $check->warning('No OID: this one goes into stock rather than to anybody.');

            return;
        }

        $employee = $this->remember('employee:'.mb_strtolower($row['employee_oid']), fn () => Employee::query()
            ->with('emergencyContacts')
            ->where('oid', $row['employee_oid'])
            ->first());

        if (! $employee) {
            $check->error("No employee has OID {$row['employee_oid']}. Import or add them first.");

            return;
        }

        if ($employee->status === EmployeeStatus::Left) {
            $check->error("{$employee->name} ({$employee->oid}) has left.");
        } elseif ($employee->status === EmployeeStatus::OnLeave) {
            $check->warning("{$employee->name} is on leave.");
        }

        if ($row['employee_name'] !== '' && Spreadsheet::headerKey($employee->name) !== Spreadsheet::headerKey($row['employee_name'])) {
            $check->warning("OID {$row['employee_oid']} is {$employee->name}, not \"{$row['employee_name']}\". The OID decides.");
        }

        if ($row['employee_mobile'] !== '') {
            $digits = fn (?string $value): string => (string) preg_replace('/\D+/', '', (string) $value);

            if (blank($employee->mobile)) {
                $check->warning("{$employee->name} has no mobile on record, so the handover form prints it blank. Add it on their page.");
            } elseif ($digits($employee->mobile) !== $digits($row['employee_mobile'])) {
                $check->warning("{$employee->name}'s mobile on record is {$employee->mobile}, not \"{$row['employee_mobile']}\".");
            }
        }

        if (($row['emergency_contact_1'] !== '' || $row['emergency_contact_2'] !== '') && $employee->emergencyContacts->isEmpty()) {
            $check->warning("{$employee->name} has no emergency contacts on record, so the handover form prints them blank. Add them on their page.");
        }
    }

    /**
     * The release says what these are; the sheet repeats it per line. A line
     * that says something else is worth seeing before it goes in as the
     * batch's own type, model, site and account.
     */
    protected function checkAgainstTheBatch(array $row, RowCheck $check): void
    {
        $batch = $this->batch;
        $same = fn (?string $mine, string $theirs): bool => Spreadsheet::headerKey((string) $mine) === Spreadsheet::headerKey($theirs);

        if ($row['model'] !== '' && $batch->assetModel && ! $same($batch->assetModel->fullName(), $row['model']) && ! $same($batch->assetModel->name, $row['model'])) {
            $check->warning("This release is a {$batch->assetModel->fullName()}, and the row says \"{$row['model']}\". The release decides.");
        }

        foreach (['account' => $batch->account, 'site' => $batch->site] as $field => $lookup) {
            if ($row[$field] === '' || ! $lookup) {
                continue;
            }

            if (! $same($lookup->name, $row[$field]) && ! $same($lookup->code, $row[$field])) {
                $check->warning(ucfirst($field)." on this release is {$lookup->name}, and the row says \"{$row[$field]}\". The release decides.");
            }
        }

        if ($row['computer_name'] === '' && $batch->assetType?->has_computer_name) {
            $check->warning("No Laptop_Name for a {$batch->assetType->name}.");
        }
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
            $this->memo[$key] = $find() ?? false;
        }

        return $this->memo[$key] ?: null;
    }
}
