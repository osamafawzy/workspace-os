<?php

namespace Modules\Audit\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Modules\Access\Filament\Admin\Resources\Users\Pages\EditUser;
use Modules\Access\Models\Role;
use Modules\Workspace\Actions\ArrangeWorkstations;
use Modules\Workspace\Actions\CreateWorkstationBatch;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * What ends up in the audit log, and what does not.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create(['name' => 'Mona']);
        $this->actingAs($this->admin);
    }

    protected function latestFor(object $record, ?string $action = null): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey())
            ->when($action, fn ($query) => $query->where('action', $action))
            ->latest('id')
            ->firstOrFail();
    }

    public function test_an_edit_records_only_what_changed_before_and_after(): void
    {
        $desk = Workstation::factory()->create(['name' => 'A-01', 'computer_name' => 'ALX-PC-001']);

        $desk->update(['computer_name' => 'ALX-PC-099']);

        $entry = $this->latestFor($desk, 'updated');

        $this->assertSame(['computer_name' => 'ALX-PC-001'], $entry->old_values);
        $this->assertSame(['computer_name' => 'ALX-PC-099'], $entry->new_values);
        $this->assertSame('Workspace', $entry->module);
        $this->assertSame('Mona', $entry->user_name);
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }

    public function test_saving_the_map_is_recorded_naming_the_desks_moved(): void
    {
        $desk = Workstation::factory()->placed(10, 10)->create(['name' => 'WS-024']);
        $floor = $desk->floor;
        $object = $desk->mapObject;

        Livewire::test(FloorPlan::class, ['record' => $floor->getKey()])
            ->call('saveMap', 0, [[
                'id' => $object->id, 'type' => 'workstation', 'workstation_id' => $desk->id,
                'x' => $object->x + 2, 'y' => $object->y, 'z' => 0, 'width' => 1.4, 'depth' => 1.5, 'height' => 0.75, 'rotation' => 0,
            ]]);

        $entry = $this->latestFor($floor, 'map saved');

        $this->assertSame('WS-024', $entry->new_values['desks moved']);
        $this->assertSame('Mona', $entry->user_name);
    }

    public function test_creating_and_deleting_are_recorded_with_the_record_named(): void
    {
        $floor = Floor::factory()->create(['name' => 'Floor 2']);
        $this->assertSame('Floor 2', $this->latestFor($floor, 'created')->record_label);

        $floor->delete();
        $deleted = $this->latestFor($floor, 'deleted');

        // Still recognisable after the row is gone.
        $this->assertSame('Floor 2', $deleted->record_label);
        $this->assertSame('Floor 2', $deleted->old_values['name']);
    }

    public function test_a_password_change_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => 'a-new-secret-password']);

        $entry = $this->latestFor($user, 'updated');

        $this->assertSame('(hidden)', $entry->new_values['password']);
        $this->assertStringNotContainsString('a-new-secret-password', json_encode($entry->toArray()));
        $this->assertStringNotContainsString($user->password, json_encode($entry->toArray()));
    }

    public function test_bulk_plan_actions_are_recorded_even_though_they_skip_model_events(): void
    {
        $floor = Floor::factory()->create();

        app(CreateWorkstationBatch::class)->handle($floor, 'A-', 5);
        $added = $this->latestFor($floor, 'workstations added');
        $this->assertSame(['count' => 5, 'first' => 'A-001', 'last' => 'A-005'], $added->new_values);

        app(ArrangeWorkstations::class)->handle($floor);
        $this->assertSame(5, $this->latestFor($floor, 'arranged')->new_values['desks placed']);
    }

    public function test_changing_someones_roles_is_recorded(): void
    {
        $user = User::factory()->withPermissions('floors.view')->create();
        $before = $user->roles->first()->name;
        $viewer = Role::query()->where('name', 'Viewer')->firstOrFail();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['roles' => [$viewer->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry = $this->latestFor($user, 'roles changed');

        $this->assertSame([$before], $entry->old_values['roles']);
        $this->assertSame(['Viewer'], $entry->new_values['roles']);
    }

    public function test_an_entry_cannot_be_rewritten(): void
    {
        $entry = $this->latestFor(Floor::factory()->create(), 'created');

        $this->expectException(LogicException::class);

        $entry->update(['user_name' => 'Somebody else']);
    }
}
