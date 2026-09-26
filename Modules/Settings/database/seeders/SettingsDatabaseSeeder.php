<?php

namespace Modules\Settings\Database\Seeders;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Modules\Settings\Models\Account;
use Modules\Settings\Models\Department;
use Modules\Settings\Models\Location;
use Modules\Settings\Models\Site;

/**
 * The lists everything else picks from: two sites with their locations, the
 * client accounts, and the departments.
 *
 * Idempotent: rows are found by name, so re-seeding adds nothing twice and
 * never renames a row somebody changed.
 */
class SettingsDatabaseSeeder extends Seeder
{
    public const SITES = [
        'Alexandria Site' => ['code' => 'ALX', 'city' => 'Alexandria'],
        'Cairo Site' => ['code' => 'CAI', 'city' => 'Cairo'],
    ];

    public const LOCATIONS = [
        'Alexandria Site' => ['IT Store Room', 'Ground Floor', 'First Floor', 'Second Floor', 'Third Floor', 'Training Room', 'Repair Bench'],
        'Cairo Site' => ['IT Store Room', 'Operations Floor', 'Training Room'],
    ];

    public const ACCOUNTS = [
        'Telecom Client A' => 'TEL-A',
        'Banking Client B' => 'BNK-B',
        'Retail Client C' => 'RET-C',
        'Travel Client D' => 'TRV-D',
        'Internal IT' => 'INT-IT',
    ];

    public const DEPARTMENTS = [
        'Operations' => 'OPS',
        'Quality Assurance' => 'QA',
        'Workforce Management' => 'WFM',
        'Training' => 'TRN',
        'Information Technology' => 'IT',
        'Human Resources' => 'HR',
        'Finance' => 'FIN',
    ];

    public function run(): void
    {
        // Company branding, where nobody has set any yet.
        $settings = app(Settings::class);

        foreach ([
            'branding.company_name' => Branding::DEFAULT_COMPANY,
            'branding.app_name' => Branding::DEFAULT_APP,
            'branding.primary_color' => Branding::DEFAULT_COLOR,
        ] as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }

        foreach (self::SITES as $name => $attributes) {
            // The Workspace seeder may already have made the first site under
            // this name; either way there is one of each.
            $site = Site::query()->firstOrCreate(['name' => $name], [...$attributes, 'is_active' => true]);

            foreach (self::LOCATIONS[$name] as $location) {
                Location::query()->firstOrCreate(['site_id' => $site->getKey(), 'name' => $location], ['is_active' => true]);
            }
        }

        foreach (self::ACCOUNTS as $name => $code) {
            Account::query()->firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true]);
        }

        foreach (self::DEPARTMENTS as $name => $code) {
            Department::query()->firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true]);
        }

        // One retired row of each kind, so the "active" filters and the
        // "retired but still in use" dropdown rule have something to show.
        Account::query()->firstOrCreate(['name' => 'Legacy Client Z'], ['code' => 'OLD-Z', 'is_active' => false]);
    }
}
