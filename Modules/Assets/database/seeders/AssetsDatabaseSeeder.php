<?php

namespace Modules\Assets\Database\Seeders;

use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Import\ImportRunner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Assets\Actions\AssignAssets;
use Modules\Assets\Actions\CheckReleaseBatch;
use Modules\Assets\Actions\ReleaseBatchAssets;
use Modules\Assets\Actions\ReturnAssets;
use Modules\Assets\Enums\AssetCondition;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Imports\AssetImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\HandoverForm;
use Modules\Assets\Models\Manufacturer;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\UpdateReason;
use Modules\Employees\Database\Seeders\EmployeesDatabaseSeeder;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Models\Workstation;

/**
 * The asset register, and a year of it being used.
 *
 * The catalogue; a PC and monitor for the traced desks on the lower floors
 * (their serials are the desks' own, so a workstation's "View asset" finds
 * them); laptops, headsets, docks, webcams and keyboards in the stores; and
 * then the work done with them — handed out, some handed back, a delivery
 * released, some moved, repaired, lost or retired, a spreadsheet imported —
 * all through the same actions the screens use, dated across the past year
 * so the history, the reports and the dashboard charts have a shape.
 *
 * Idempotent: assets are found by serial, and each step that records activity
 * runs only if that activity is not there yet.
 */
class AssetsDatabaseSeeder extends Seeder
{
    /** type => [has computer name, is headset] */
    protected const TYPES = [
        'Laptop' => [true, false],
        'Desktop' => [true, false],
        'Monitor' => [false, false],
        'Headset' => [false, true],
        'Docking Station' => [false, false],
        'Webcam' => [false, false],
        'Keyboard & Mouse Set' => [false, false],
    ];

    /** [manufacturer, model, type] */
    protected const MODELS = [
        ['Dell', 'Latitude 5440', 'Laptop'],
        ['Lenovo', 'ThinkPad E14 Gen 5', 'Laptop'],
        ['Dell', 'OptiPlex 7010', 'Desktop'],
        ['HP', 'EliteDesk 800 G6', 'Desktop'],
        ['Dell', 'P2422H', 'Monitor'],
        ['HP', 'E24 G5', 'Monitor'],
        ['Jabra', 'Evolve2 40', 'Headset'],
        ['Poly', 'Blackwire 3320', 'Headset'],
        ['Dell', 'WD19S', 'Docking Station'],
        ['Logitech', 'C920', 'Webcam'],
        ['Logitech', 'MK270', 'Keyboard & Mouse Set'],
    ];

    /**
     * The reasons an asset gets changed, as a starting list. They are managed
     * from Settings, so the company can add its own and retire these.
     *
     * [name, code, description]
     */
    protected const UPDATE_REASONS = [
        ['Replacement — faulty hardware', 'FAULTY', 'The asset stopped working and is being swapped out.'],
        ['Replacement — accidental damage', 'DAMAGE', 'Dropped, spilt on or otherwise damaged in use.'],
        ['Upgrade or refresh', 'UPGRADE', 'Working, but replaced by newer hardware.'],
        ['Lost or stolen', 'LOST', 'The asset cannot be found, or was reported stolen.'],
        ['End of life', 'EOL', 'Too old to keep in service; retired from the register.'],
        ['Employee request', 'REQUEST', 'Changed at the employee\'s request.'],
        ['Moved site or location', 'MOVE', 'The asset went to another desk, room or site.'],
        ['Back from repair', 'REPAIRED', 'Returned from the workshop and back in service.'],
        ['Stock take correction', 'STOCKTAKE', 'The record did not match what was on the shelf.'],
        ['Data entry correction', 'CORRECTION', 'A serial, tag or name was recorded wrongly.'],
    ];

    /** @var array<string, AssetModel> "Manufacturer Model" => model */
    protected array $models = [];

    public function run(): void
    {
        $this->call(EmployeesDatabaseSeeder::class);

        $user = auth()->user() ?? User::query()->orderBy('id')->first();

        if ($user) {
            auth()->setUser($user);
        }

        $this->catalogue();
        $this->deskAssets();
        $this->stock();

        try {
            $this->assignmentsAndReturns($user);
            $this->releases($user);
            $this->dayToDay();
        } finally {
            Carbon::setTestNow();
        }

        $this->assetImport($user);
    }

    protected function catalogue(): void
    {
        foreach (self::TYPES as $name => [$computer, $headset]) {
            AssetType::query()->firstOrCreate(['name' => $name], ['has_computer_name' => $computer, 'is_headset' => $headset, 'is_active' => true]);
        }

        foreach (self::MODELS as [$manufacturer, $model, $type]) {
            $maker = Manufacturer::query()->firstOrCreate(['name' => $manufacturer], ['is_active' => true]);

            $this->models["{$manufacturer} {$model}"] = AssetModel::query()->firstOrCreate(
                ['manufacturer_id' => $maker->getKey(), 'name' => $model],
                ['asset_type_id' => AssetType::query()->where('name', $type)->value('id'), 'is_active' => true],
            );
        }

        // A model nobody buys any more, kept for its old assets.
        $hp = Manufacturer::query()->where('name', 'HP')->first();
        AssetModel::query()->firstOrCreate(
            ['manufacturer_id' => $hp->getKey(), 'name' => 'ProDesk 400 G4'],
            ['asset_type_id' => AssetType::query()->where('name', 'Desktop')->value('id'), 'is_active' => false],
        );

        foreach (self::UPDATE_REASONS as [$name, $code, $description]) {
            UpdateReason::query()->firstOrCreate(
                ['name' => $name],
                ['code' => $code, 'description' => $description, 'is_active' => true],
            );
        }
    }

    /**
     * A PC and a monitor for every traced desk on the ground, first and second
     * floors, under the serials the desk's record already carries.
     */
    protected function deskAssets(): void
    {
        $site = Site::query()->where('name', 'Alexandria Site')->first();
        $internal = Account::query()->where('name', 'Internal IT')->first();

        $desks = Workstation::query()
            ->with('floor')
            ->whereHas('floor', fn ($query) => $query->where('level', '<=', 2))
            ->whereNotNull('pc_serial')
            ->orderBy('id')
            ->get();

        foreach ($desks as $index => $desk) {
            $location = $site ? Location::query()->where('site_id', $site->getKey())->where('name', $desk->floor?->name)->value('id') : null;
            $purchased = now()->subDays(200 + ($index * 13) % 1100)->startOfDay();
            $common = [
                'site_id' => $site?->getKey(),
                'location_id' => $location,
                'account_id' => $internal?->getKey(),
                'purchase_date' => $purchased,
                // Three years' cover: some ended, some about to.
                'warranty_expires_at' => $purchased->copy()->addYears(3),
                'condition' => $index % 9 === 8 ? AssetCondition::Fair : AssetCondition::Good,
            ];

            $pcModel = $this->models[$index % 3 === 2 ? 'HP EliteDesk 800 G6' : 'Dell OptiPlex 7010'];
            $this->asset($desk->pc_serial, [
                ...$common,
                'asset_type_id' => $pcModel->asset_type_id,
                'asset_model_id' => $pcModel->getKey(),
                'asset_tag' => sprintf('AT-%06d', 100000 + $desk->getKey()),
                'computer_name' => $desk->computer_name,
                'status' => $desk->status === WorkstationStatus::Faulty ? AssetStatus::InRepair : AssetStatus::Available,
                'notes' => "At desk {$desk->displayLabel()}.",
            ]);

            if ($desk->monitor_serial) {
                $monitorModel = $this->models[$index % 2 ? 'HP E24 G5' : 'Dell P2422H'];
                $this->asset($desk->monitor_serial, [
                    ...$common,
                    'asset_type_id' => $monitorModel->asset_type_id,
                    'asset_model_id' => $monitorModel->getKey(),
                    'asset_tag' => sprintf('AT-%06d', 200000 + $desk->getKey()),
                    'status' => AssetStatus::Available,
                ]);
            }
        }
    }

    /** The stores: what gets handed to people. */
    protected function stock(): void
    {
        $alexandria = Site::query()->where('name', 'Alexandria Site')->first();
        $cairo = Site::query()->where('name', 'Cairo Site')->first();
        $store = fn (?Site $site): ?int => $site ? Location::query()->where('site_id', $site->getKey())->where('name', 'IT Store Room')->value('id') : null;

        $runs = [
            // prefix, count, models, first tag
            ['LT-ALX-', 60, ['Dell Latitude 5440', 'Lenovo ThinkPad E14 Gen 5'], 300000],
            ['HS-ALX-', 100, ['Jabra Evolve2 40', 'Poly Blackwire 3320'], 400000],
            ['DK-ALX-', 20, ['Dell WD19S'], 500000],
            ['WC-ALX-', 12, ['Logitech C920'], 600000],
            ['KM-ALX-', 30, ['Logitech MK270'], 700000],
            ['LT-CAI-', 15, ['Lenovo ThinkPad E14 Gen 5'], 800000],
            ['HS-CAI-', 25, ['Poly Blackwire 3320'], 900000],
        ];

        foreach ($runs as [$prefix, $count, $modelNames, $firstTag]) {
            $site = str_contains($prefix, 'CAI') ? $cairo : $alexandria;

            for ($i = 1; $i <= $count; $i++) {
                $model = $this->models[$modelNames[$i % count($modelNames)]];
                $purchased = now()->subDays(30 + ($i * 19) % 900)->startOfDay();

                // Headsets come with a QD cord of their own; a few came without.
                $cord = str_starts_with($prefix, 'HS-') && $i % 7 !== 0;

                $this->asset(sprintf('%s%04d', $prefix, $i), [
                    'asset_type_id' => $model->asset_type_id,
                    'asset_model_id' => $model->getKey(),
                    'asset_tag' => sprintf('AT-%06d', $firstTag + $i),
                    'cord_model' => $cord ? $model->manufacturer?->name.' QD to USB-A' : null,
                    'cord_serial' => $cord ? sprintf('CD-%s%04d', substr($prefix, 3, 4), $i) : null,
                    'cord_condition' => $cord ? ($i % 13 === 0 ? AssetCondition::Damaged : AssetCondition::New) : null,
                    'computer_name' => str_starts_with($prefix, 'LT-') ? sprintf('%sLT-%04d', substr($prefix, 3, 3), $i) : null,
                    'status' => AssetStatus::Available,
                    'condition' => $i % 11 === 0 ? AssetCondition::Fair : AssetCondition::New,
                    'site_id' => $site?->getKey(),
                    'location_id' => $store($site),
                    'purchase_date' => $purchased,
                    'warranty_expires_at' => str_starts_with($prefix, 'LT-')
                        // A few laptops whose cover runs out this month.
                        ? ($i % 12 === 0 ? now()->addDays(5 + $i % 20) : $purchased->copy()->addYears(3))
                        : $purchased->copy()->addYears(2),
                ]);
            }
        }
    }

    /**
     * A year of handovers and returns: laptops and headsets out to most active
     * employees, some headsets and laptops back, and a few people who left
     * without handing theirs in.
     */
    protected function assignmentsAndReturns(?User $user): void
    {
        if (HandoverForm::query()->where('kind', HandoverForm::HANDOVER)->exists()) {
            return;
        }

        $people = Employee::query()->where('status', EmployeeStatus::Active)->orderBy('oid')->limit(48)->get();
        $laptops = $this->free('LT-ALX-');
        $headsets = $this->free('HS-ALX-');
        $docks = $this->free('DK-ALX-');

        foreach ($people as $index => $employee) {
            // Spread across the past eleven months, oldest first.
            Carbon::setTestNow(now()->subDays(330 - $index * 6)->setTime(9 + $index % 8, ($index * 7) % 60));

            $items = array_filter([$laptops->shift()?->getKey(), $headsets->shift()?->getKey(), $index % 3 === 0 ? $docks->shift()?->getKey() : null]);

            if ($items !== []) {
                $form = app(AssignAssets::class)->handle($employee, array_values($items), $user, $index % 4 === 0 ? 'Charger and bag included.' : null);

                // Most forms were printed at handover; a few were printed twice.
                $form->forceFill(['print_count' => $index % 5 === 0 ? 0 : 1 + ($index % 7 === 0 ? 1 : 0), 'last_printed_at' => $index % 5 === 0 ? null : now()])->saveQuietly();
            }

            Carbon::setTestNow();
        }

        // Some come back: worn-out headsets, a laptop from somebody moving
        // team, and one laptop handed back damaged by a team leader.
        foreach ($people->slice(2, 10)->values() as $index => $employee) {
            Carbon::setTestNow(now()->subDays(40 - $index * 3)->setTime(15, 30));

            $held = Asset::query()->where('employee_id', $employee->getKey())->with('assetType')->get();
            $back = $index % 3 === 0 ? $held : $held->filter(fn (Asset $asset) => $asset->assetType?->is_headset);

            if ($back->isNotEmpty()) {
                app(ReturnAssets::class)->handle(
                    $back->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => match (true) {
                        $index === 4 => AssetCondition::Damaged,
                        $asset->assetType?->is_headset => AssetCondition::Fair,
                        default => AssetCondition::Good,
                    }])->all(),
                    [
                        'returned_at' => now()->toDateTimeString(),
                        'returned_by_name' => $index === 4 ? 'Team leader on their behalf' : null,
                        'site_id' => Site::query()->where('name', 'Alexandria Site')->value('id'),
                        'location_id' => Location::query()->where('name', $index === 4 ? 'Repair Bench' : 'IT Store Room')->value('id'),
                        'notes' => $index === 4 ? 'Screen cracked.' : null,
                    ],
                    $user,
                );
            }

            Carbon::setTestNow();
        }

        // And three people left still holding theirs: the Non-Returned report.
        $people->slice(40, 3)->each(fn (Employee $employee, int $index) => $employee->update([
            'status' => EmployeeStatus::Left,
            'left_at' => now()->subDays(12 + $index * 9)->startOfDay(),
        ]));
    }

    /**
     * A delivery released a month ago, and one staged today that the checks
     * are not happy with.
     */
    protected function releases(?User $user): void
    {
        if (ReleaseBatch::query()->exists()) {
            return;
        }

        $laptop = $this->models['Lenovo ThinkPad E14 Gen 5'];
        $site = Site::query()->where('name', 'Alexandria Site')->first();
        $store = Location::query()->where('site_id', $site?->getKey())->where('name', 'IT Store Room')->first();
        $account = Account::query()->where('name', 'Telecom Client A')->first();
        $newStarters = Employee::query()->where('status', EmployeeStatus::Active)->whereDoesntHave('assets')->orderByDesc('oid')->limit(4)->pluck('oid')->all();

        Carbon::setTestNow(now()->subDays(28)->setTime(11, 0));

        $released = ReleaseBatch::query()->create([
            'asset_type_id' => $laptop->asset_type_id,
            'asset_model_id' => $laptop->getKey(),
            'site_id' => $site?->getKey(),
            'location_id' => $store?->getKey(),
            'account_id' => $account?->getKey(),
            'purchase_date' => now()->subDays(3)->toDateString(),
            'warranty_expires_at' => now()->addYears(3)->toDateString(),
            'notes' => 'Delivery note DN-44817.',
            'created_by' => $user?->getKey(),
            'created_by_name' => $user?->name,
        ]);

        foreach (range(1, 6) as $i) {
            $released->items()->create([
                'serial_number' => sprintf('PF-4E%05d', 1200 + $i),
                'asset_tag' => sprintf('AT-%06d', 950000 + $i),
                'computer_name' => sprintf('ALX-LT-N%03d', $i),
                'employee_oid' => $newStarters[$i - 1] ?? null,
            ]);
        }

        app(ReleaseBatchAssets::class)->handle($released, $user);
        $released->forceFill(['print_count' => 1])->saveQuietly();

        Carbon::setTestNow();

        $draft = ReleaseBatch::query()->create([
            'asset_type_id' => $laptop->asset_type_id,
            'asset_model_id' => $laptop->getKey(),
            'site_id' => $site?->getKey(),
            'location_id' => $store?->getKey(),
            'notes' => 'Second half of the delivery. Two rows still to sort out.',
            'created_by' => $user?->getKey(),
            'created_by_name' => $user?->name,
        ]);

        foreach ([
            ['serial_number' => 'PF-4E01301', 'computer_name' => 'ALX-LT-N101', 'employee_oid' => (string) (EmployeesDatabaseSeeder::FIRST_OID + 5)],
            ['serial_number' => 'PF-4E01302', 'computer_name' => 'ALX-LT-N102'],
            ['serial_number' => 'PF-4E01302', 'computer_name' => 'ALX-LT-N103', 'employee_oid' => '9999999'],
            ['serial_number' => 'PF-4E01201', 'computer_name' => 'ALX-LT-N104'],
        ] as $item) {
            $draft->items()->create($item);
        }

        app(CheckReleaseBatch::class)->handle($draft, null, $user);
    }

    /** Things that happen to assets between handovers. */
    protected function dayToDay(): void
    {
        if (Asset::query()->whereIn('status', [AssetStatus::Lost, AssetStatus::Retired])->exists()) {
            return;
        }

        $repairBench = Location::query()->where('name', 'Repair Bench')->value('id');
        $training = Location::query()->where('name', 'Training Room')->value('id');

        // [serial, days ago, values, the reason given for the change]
        $changes = [
            ['WC-ALX-0003', 60, ['location_id' => $training], 'MOVE'],
            ['WC-ALX-0004', 60, ['location_id' => $training], 'MOVE'],
            ['KM-ALX-0007', 45, ['status' => AssetStatus::InRepair, 'location_id' => $repairBench, 'condition' => AssetCondition::Damaged, 'notes' => 'Keys sticking.'], 'FAULTY'],
            ['HS-ALX-0090', 20, ['status' => AssetStatus::Lost, 'notes' => 'Not found at stock take.'], 'LOST'],
            ['DK-ALX-0020', 15, ['status' => AssetStatus::Retired, 'condition' => AssetCondition::Damaged, 'notes' => 'Power board failed; written off.'], 'EOL'],
            ['LT-ALX-0060', 10, ['asset_tag' => 'AT-399999', 'notes' => 'Tag replaced, old one unreadable.'], 'CORRECTION'],
            ['LT-CAI-0015', 5, ['status' => AssetStatus::InRepair, 'location_id' => $repairBench], 'FAULTY'],
        ];

        $reasons = UpdateReason::query()->whereNotNull('code')->get()->keyBy('code');

        foreach ($changes as [$serial, $daysAgo, $values, $code]) {
            $asset = Asset::query()->where('serial_number', $serial)->first();

            if (! $asset || $asset->employee_id) {
                continue;
            }

            Carbon::setTestNow(now()->subDays($daysAgo)->setTime(14, 0));
            $asset->withReason($reasons->get($code))->update($values);
            Carbon::setTestNow();
        }
    }

    /** A stock-take spreadsheet of webcams, through the asset import. */
    protected function assetImport(?User $user): void
    {
        if (! $user || ImportBatch::query()->where('importer', AssetImporter::key())->exists()) {
            return;
        }

        $rows = [['Type', 'Make', 'Model', 'S/N', 'Tag', 'Status', 'Condition', 'Site', 'Location', 'Purchased', 'Warranty End']];

        foreach (range(13, 18) as $i) {
            $rows[] = ['Webcam', 'Logitech', 'C920', sprintf('WC-ALX-%04d', $i), sprintf('AT-%06d', 600000 + $i), 'In stock', 'New', 'Alexandria Site', 'IT Store Room', now()->subDays(20)->format('d/m/Y'), now()->addYears(2)->format('Y-m-d')];
        }

        // One already in the register and one with a status nobody uses.
        $rows[] = ['Webcam', 'Logitech', 'C920', 'WC-ALX-0001', '', '', '', '', '', '', ''];
        $rows[] = ['Webcam', 'Logitech', 'C920', 'WC-ALX-0099', '', 'Shiny', '', '', '', '', ''];

        $path = tempnam(sys_get_temp_dir(), 'assets').'.csv';
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $runner = app(ImportRunner::class);
        $importer = app(AssetImporter::class);
        $runner->import($runner->check($importer, $path, 'stock-take-webcams.csv', [], $user), $importer);

        @unlink($path);
    }

    /** @param  array<string, mixed>  $values */
    protected function asset(string $serial, array $values): Asset
    {
        $asset = Asset::query()->where('serial_number', $serial)->first();

        if (! $asset) {
            return Asset::query()->create(['serial_number' => $serial, ...$values]);
        }

        // Fields the register gained after this asset was seeded — the cord a
        // headset came with — are filled in where they are still empty, so an
        // older demo database catches up. Nothing already set is touched.
        $missing = collect($values)
            ->only(['cord_model', 'cord_serial', 'cord_condition'])
            ->filter(fn ($value, string $field): bool => filled($value) && blank($asset->{$field}))
            ->all();

        if ($missing !== []) {
            $asset->update($missing);
        }

        return $asset;
    }

    /** @return Collection<int, Asset> free assets whose serial starts with this, in order */
    protected function free(string $prefix): Collection
    {
        return Asset::query()
            ->where('serial_number', 'like', $prefix.'%')
            ->whereNull('employee_id')
            ->where('status', AssetStatus::Available)
            ->orderBy('serial_number')
            ->get();
    }
}
