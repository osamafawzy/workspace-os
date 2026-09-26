<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetAssignment;
use Modules\Assets\Models\HandoverForm;
use Modules\Assets\Models\ReleaseBatch;
use Modules\Employees\Enums\EmployeeStatus;
use Modules\Employees\Models\Employee;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * The demo seeder fills every table the application has, through the same
 * actions the screens use, and can be run again without doubling anything.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Laravel's own plumbing, which a demo has no reason to fill. */
    private const FRAMEWORK_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed();
    }

    public function test_every_application_table_has_rows(): void
    {
        $tables = collect(Schema::getTableListing())
            ->map(fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->reject(fn (string $table): bool => in_array($table, self::FRAMEWORK_TABLES, true) || str_starts_with($table, 'sqlite_'));

        foreach ($tables as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "The seeder leaves {$table} empty.");
        }
    }

    public function test_the_demo_tells_a_consistent_story(): void
    {
        // Logins for every role.
        foreach (['admin', 'itadmin', 'engineer', 'technician', 'viewer'] as $login) {
            $this->assertDatabaseHas('users', ['email' => "{$login}@workspace.test"]);
        }

        // People in every state, and some who left holding assets.
        foreach (EmployeeStatus::cases() as $status) {
            $this->assertTrue(Employee::query()->where('status', $status)->exists(), "No {$status->value} employees");
        }
        $this->assertTrue(Asset::query()->whereHas('employee', fn ($query) => $query->where('status', EmployeeStatus::Left))->exists());

        // Every held asset has its open assignment; the ledger and the assets agree.
        $this->assertSame(
            Asset::query()->whereNotNull('employee_id')->count(),
            AssetAssignment::query()->whereNull('returned_at')->count(),
        );

        // Assets in every status, handovers and receipts, a released and a draft batch.
        foreach (AssetStatus::cases() as $status) {
            $this->assertTrue(Asset::query()->where('status', $status)->exists(), "No {$status->value} assets");
        }
        $this->assertTrue(HandoverForm::query()->where('kind', HandoverForm::RETURN)->exists());
        $this->assertSame([ReleaseBatch::ARCHIVED, ReleaseBatch::DRAFT], ReleaseBatch::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, ImportBatch::query()->count());

        // A desk's PC is in the register under the desk's own serial.
        $desk = Workstation::query()->whereNotNull('pc_serial')->whereHas('floor', fn ($query) => $query->where('level', 0))->firstOrFail();
        $this->assertTrue(Asset::query()->where('serial_number', $desk->pc_serial)->exists());

        // A year of activity, not everything stamped today.
        $this->assertTrue(AssetAssignment::query()->where('assigned_at', '<', now()->subMonths(6))->exists());
        $this->assertSame('Admin', AuditLog::query()->where('action', 'assets assigned')->value('user_name'));
    }

    public function test_seeding_again_adds_nothing(): void
    {
        $tables = ['users', 'sites', 'locations', 'employees', 'emergency_contacts', 'assets', 'asset_assignments', 'asset_returns', 'handover_forms', 'release_batches', 'import_batches', 'workstations', 'floors'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

        $this->seed();

        $after = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $this->assertSame($before, $after);
    }
}
