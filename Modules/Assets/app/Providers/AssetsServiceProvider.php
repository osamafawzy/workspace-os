<?php

namespace Modules\Assets\Providers;

use App\Models\User;
use App\Support\Dashboard\QuickActions;
use App\Support\Import\Importers;
use App\Support\Permissions;
use App\Support\Reports\Reports;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Modules\Assets\Filament\Admin\Pages\AddHeadsets;
use Modules\Assets\Filament\Admin\Pages\AssignAssets;
use Modules\Assets\Filament\Admin\Pages\ReturnAssets;
use Modules\Assets\Filament\Admin\Pages\UpdateAssets;
use Modules\Assets\Filament\Admin\Resources\Assets\AssetResource;
use Modules\Assets\Filament\Admin\Resources\ReleaseBatches\ReleaseBatchResource;
use Modules\Assets\Filament\Admin\Support\EmployeeAssetsSection;
use Modules\Assets\Imports\AssetImporter;
use Modules\Assets\Imports\HeadsetImporter;
use Modules\Assets\Imports\ReleaseFormImporter;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetAssignment;
use Modules\Assets\Models\AssetModel;
use Modules\Assets\Models\AssetReturn;
use Modules\Assets\Models\AssetType;
use Modules\Assets\Models\HandoverForm;
use Modules\Assets\Models\Manufacturer;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Assets\Models\UpdateReason;
use Modules\Assets\Policies\AssetPolicy;
use Modules\Assets\Policies\AssetReturnPolicy;
use Modules\Assets\Policies\CataloguePolicy;
use Modules\Assets\Policies\HandoverFormPolicy;
use Modules\Assets\Policies\ReleaseBatchPolicy;
use Modules\Assets\Reports\AssetCountsReport;
use Modules\Assets\Reports\AssetInventoryReport;
use Modules\Assets\Reports\AssignedAssetsReport;
use Modules\Assets\Reports\AvailableAssetsReport;
use Modules\Assets\Reports\EmployeeAssetsReport;
use Modules\Assets\Reports\HeadsetReport;
use Modules\Assets\Reports\MovementHistoryReport;
use Modules\Assets\Reports\NonReturnedAssetsReport;
use Modules\Assets\Reports\ReturnedAssetsReport;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Filament\Admin\Resources\Employees\Pages\ViewEmployee;
use Modules\Employees\Filament\Admin\Resources\Employees\Schemas\EmployeeInfolist;
use Modules\Employees\Models\Employee;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Models\Workstation;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AssetsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Assets';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'assets';

    public function boot(): void
    {
        parent::boot();

        $permissions = $this->app->make(Permissions::class);

        $permissions->register('Assets', [
            'assets.view' => 'Search and view assets and their history',
            'assets.create' => 'Add assets',
            'assets.update' => 'Update assets: location, status, condition, serial, tag, notes',
            'assets.delete' => 'Delete assets',
            'assets.import' => 'Import assets from a spreadsheet',
            'assets.export' => 'Export assets to a spreadsheet',
        ]);

        $permissions->register('Assignments', [
            'assignments.view' => 'See handover forms, return receipts and returned assets',
            'assignments.assign' => 'Hand assets to employees',
            'assignments.return' => 'Take assets back from employees',
            'assignments.print' => 'Print and reprint handover forms and return receipts',
        ]);

        $permissions->register('Releases', [
            'releases.view' => 'See release batches of new assets and print their reports',
            'releases.manage' => 'Stage new assets: add, check and quick-print new data',
            'releases.release' => 'Release new data: create the assets, hand them out and archive the batch',
        ]);

        Gate::policy(ReleaseBatch::class, ReleaseBatchPolicy::class);
        $this->app->make(Importers::class)->register(HeadsetImporter::class);
        $this->app->make(Importers::class)->register(ReleaseFormImporter::class);

        Gate::define('assign-assets', fn (User $user): bool => $user->hasPermission('assignments.assign') && $user->hasPermission('assets.view'));
        Gate::define('return-assets', fn (User $user): bool => $user->hasPermission('assignments.return') && $user->hasPermission('assets.view'));
        Gate::policy(HandoverForm::class, HandoverFormPolicy::class);
        Gate::policy(AssetReturn::class, AssetReturnPolicy::class);

        $permissions->register('Asset catalogue', [
            'catalogue.view' => 'View manufacturers, asset types and models',
            'catalogue.create' => 'Add manufacturers, asset types and models',
            'catalogue.update' => 'Edit manufacturers, asset types and models',
            'catalogue.delete' => 'Delete manufacturers, asset types and models',
        ]);

        Gate::policy(Asset::class, AssetPolicy::class);

        foreach ([Manufacturer::class, AssetType::class, AssetModel::class, UpdateReason::class] as $model) {
            Gate::policy($model, CataloguePolicy::class);
        }

        $this->app->make(Importers::class)->register(AssetImporter::class);

        $reports = $this->app->make(Reports::class);

        foreach ([
            AssetInventoryReport::class, AssetCountsReport::class, AssignedAssetsReport::class, AvailableAssetsReport::class,
            ReturnedAssetsReport::class, NonReturnedAssetsReport::class, EmployeeAssetsReport::class,
            HeadsetReport::class, MovementHistoryReport::class,
        ] as $report) {
            $reports->register($report);
        }

        // What an employee holds, for the employee reports. Employees cannot
        // declare it: it does not know assets exist.
        Employee::resolveRelationUsing('assets', fn (Employee $employee) => $employee->hasMany(Asset::class));

        $quick = $this->app->make(QuickActions::class);
        $quick->add('assign-assets', 'Assign assets', 'Hand assets to an employee and print the form.', 'heroicon-o-user-plus', fn (): string => AssignAssets::getUrl(), fn (): bool => AssignAssets::canAccess(), 30);
        $quick->add('return-assets', 'Return assets', 'Take assets back and print the receipt.', 'heroicon-o-arrow-uturn-left', fn (): string => ReturnAssets::getUrl(), fn (): bool => ReturnAssets::canAccess(), 40);
        $quick->add('update-assets', 'Update assets', 'Scan a label and change status, place or condition.', 'heroicon-o-qr-code', fn (): string => UpdateAssets::getUrl(), fn (): bool => UpdateAssets::canAccess(), 50);
        $quick->add('release-new-assets', 'Release new assets', 'Stage a delivery, check it and hand it out.', 'heroicon-o-archive-box-arrow-down', fn (): string => ReleaseBatchResource::getUrl('create'), fn (): bool => ReleaseBatchResource::canCreate(), 60);
        $quick->add('headsets', 'Add headsets', 'Scan headsets in, or upload a spreadsheet of them.', 'heroicon-o-speaker-wave', fn (): string => AddHeadsets::getUrl(), fn (): bool => AddHeadsets::canAccess(), 70);

        // Other modules' records an asset points at stay while it does.
        Site::inUseWhen(fn (Site $site): bool => Asset::query()->where('site_id', $site->getKey())->exists());
        Location::inUseWhen(fn (Location $location): bool => Asset::query()->where('location_id', $location->getKey())->exists());
        Account::inUseWhen(fn (Account $account): bool => Asset::query()->where('account_id', $account->getKey())->exists());
        // An employee who holds assets, or ever signed for any, is kept: their
        // papers and assignment history point at them.
        Employee::inUseWhen(fn (Employee $employee): bool => Asset::query()->where('employee_id', $employee->getKey())->exists()
            || AssetAssignment::query()->where('employee_id', $employee->getKey())->exists());

        // What an employee holds, on their profile, and the way to change it.
        EmployeeInfolist::addSection(fn () => EmployeeAssetsSection::make());

        ViewEmployee::addHeaderAction(fn (Employee $employee): Action => Action::make('assignAssets')
            ->label('Assign assets')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn (): bool => $employee->status !== EmployeeStatus::Left && Gate::allows('assign-assets'))
            ->url(fn (): string => AssignAssets::getUrl(['employee' => $employee->getKey()])));

        ViewEmployee::addHeaderAction(fn (Employee $employee): Action => Action::make('returnAssets')
            ->label('Return assets')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (): bool => Gate::allows('return-assets') && Asset::query()->where('employee_id', $employee->getKey())->exists())
            ->url(fn (): string => ReturnAssets::getUrl(['employee' => $employee->getKey()])));

        // A desk's PC and monitor, found in the register by serial number.
        WorkstationDetailsAction::linkAssetsUsing(function (Workstation $desk): array {
            if (! (auth()->user()?->can('viewAny', Asset::class) ?? false)) {
                return [];
            }

            $links = [];

            foreach (['pc' => $desk->pc_serial, 'monitor' => $desk->monitor_serial] as $key => $serial) {
                $asset = filled($serial) ? Asset::query()->where('serial_number', trim((string) $serial))->first() : null;

                if ($asset) {
                    $links[$key] = AssetResource::getUrl('view', ['record' => $asset]);
                }
            }

            return $links;
        });
    }
}
