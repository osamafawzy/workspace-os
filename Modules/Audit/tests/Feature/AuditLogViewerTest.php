<?php

namespace Modules\Audit\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Audit\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_the_log_needs_its_own_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view', 'users.view')->create())
            ->get('/admin/audit-logs')
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('audit.view')->create())
            ->get('/admin/audit-logs')
            ->assertSuccessful();
    }

    public function test_entries_can_be_filtered_by_module_and_opened(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $desk = Workstation::factory()->create(['computer_name' => 'OLD-PC']);
        $desk->update(['computer_name' => 'NEW-PC']);

        $workspace = AuditLog::query()->where('module', 'Workspace')->get();
        $access = AuditLog::query()->where('module', 'Access')->get();

        $this->assertNotEmpty($workspace);
        $this->assertNotEmpty($access);

        $update = AuditLog::query()
            ->where('action', 'updated')
            ->where('auditable_type', $desk->getMorphClass())
            ->where('auditable_id', $desk->id)
            ->firstOrFail();

        Livewire::test(ListAuditLogs::class)
            ->filterTable('module', 'Workspace')
            ->assertCanSeeTableRecords($workspace)
            ->assertCanNotSeeTableRecords($access)
            ->mountTableAction('view', $update)
            ->assertMountedActionModalSee(['computer name', 'OLD-PC', 'NEW-PC']);
    }

    public function test_nobody_can_edit_or_delete_entries_from_the_panel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        Floor::factory()->create();
        $entry = AuditLog::query()->firstOrFail();

        Livewire::test(ListAuditLogs::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionVisible('view', $entry);
    }
}
