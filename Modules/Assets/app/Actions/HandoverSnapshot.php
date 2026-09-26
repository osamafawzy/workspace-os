<?php

namespace Modules\Assets\Actions;

use App\Support\Branding;
use Modules\Assets\Models\Asset;
use Modules\Employees\Models\Employee;

/**
 * What a printed form says, taken at the moment it is made.
 *
 * A form is evidence of what was handed over and to whom, so it must not
 * change when the employee later moves department or an asset is relabelled.
 * The Assign and Return actions freeze these arrays onto the form.
 */
class HandoverSnapshot
{
    /** @return array<string, mixed> */
    public static function company(): array
    {
        $branding = app(Branding::class);

        return [
            'name' => $branding->companyName(),
            'app' => $branding->appName(),
        ];
    }

    /** @return array<string, mixed> */
    public static function employee(Employee $employee): array
    {
        $employee->loadMissing(['department', 'account', 'site', 'location', 'emergencyContacts']);

        return [
            'id' => $employee->getKey(),
            'name' => $employee->name,
            'oid' => $employee->oid,
            'employee_number' => $employee->employee_number,
            'job_title' => $employee->job_title,
            'department' => $employee->department?->name,
            'account' => $employee->account?->name,
            'site' => $employee->site?->name,
            'location' => $employee->location?->name,
            'mobile' => $employee->mobile,
            'email' => $employee->email,
        ];
    }

    /**
     * Kept apart from the employee so the form can leave them out for somebody
     * not allowed to see personal data.
     *
     * @return list<array<string, mixed>>
     */
    public static function contacts(Employee $employee): array
    {
        return $employee->emergencyContacts
            ->map(fn ($contact): array => $contact->only(['slot', 'name', 'relationship', 'phone']))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function asset(Asset $asset): array
    {
        $asset->loadMissing(['assetType', 'assetModel.manufacturer']);

        return [
            'id' => $asset->getKey(),
            'type' => $asset->assetType?->name,
            'manufacturer' => $asset->assetModel?->manufacturer?->name,
            'model' => $asset->assetModel?->name,
            'serial_number' => $asset->serial_number,
            'asset_tag' => $asset->asset_tag,
            'computer_name' => $asset->computer_name,
            'condition' => $asset->condition?->getLabel(),
        ];
    }
}
