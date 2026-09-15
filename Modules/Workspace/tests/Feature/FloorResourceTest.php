<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\CreateFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\EditFloor;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\ListFloors;
use Modules\Workspace\Filament\Admin\Resources\Floors\RelationManagers\WorkstationsRelationManager;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class FloorResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_panel_requires_a_login(): void
    {
        auth()->logout();

        $this->get('/admin/floors')->assertRedirect('/admin/login');
    }

    public function test_the_floor_list_renders_every_floor(): void
    {
        $floors = Floor::factory()->count(3)->create();

        Livewire::test(ListFloors::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($floors);
    }

    public function test_the_list_counts_the_workstations_on_each_floor(): void
    {
        $floor = Floor::factory()->create();
        Workstation::factory()->count(4)->for($floor)->create();

        Livewire::test(ListFloors::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('workstations_count', 4, $floor);
    }

    public function test_a_floor_can_be_created(): void
    {
        Livewire::test(CreateFloor::class)
            ->fillForm([
                'name' => 'Third Floor',
                'level' => 3,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('floors', ['name' => 'Third Floor', 'level' => 3]);
    }

    public function test_a_floor_requires_a_name_and_a_level(): void
    {
        Livewire::test(CreateFloor::class)
            ->fillForm(['name' => null, 'level' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'level' => 'required']);
    }

    /**
     * The database enforces this too, but a unique-key violation reaches the
     * user as a 500. The form rule is what turns it into a field error.
     */
    public function test_the_form_rejects_a_level_that_is_already_taken(): void
    {
        Floor::factory()->create(['name' => 'Ground', 'level' => 0]);

        Livewire::test(CreateFloor::class)
            ->fillForm(['name' => 'Ground Again', 'level' => 0])
            ->call('create')
            ->assertHasFormErrors(['level' => 'unique']);
    }

    public function test_editing_a_floor_does_not_trip_its_own_unique_rules(): void
    {
        $floor = Floor::factory()->create(['name' => 'Ground', 'level' => 0]);

        Livewire::test(EditFloor::class, ['record' => $floor->getKey()])
            ->fillForm(['name' => 'Ground Floor'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Ground Floor', $floor->refresh()->name);
    }

    public function test_workstations_can_be_added_from_the_floor_screen(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(WorkstationsRelationManager::class, [
            'ownerRecord' => $floor,
            'pageClass' => EditFloor::class,
        ])
            ->assertSuccessful()
            ->callTableAction('create', data: ['name' => 'A-01'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('workstations', [
            'floor_id' => $floor->id,
            'name' => 'A-01',
        ]);
    }

    public function test_the_floor_screen_rejects_a_duplicate_desk_name_on_that_floor(): void
    {
        $floor = Floor::factory()->create();
        $floor->workstations()->create(['name' => 'A-01']);

        Livewire::test(WorkstationsRelationManager::class, [
            'ownerRecord' => $floor,
            'pageClass' => EditFloor::class,
        ])
            ->callTableAction('create', data: ['name' => 'A-01'])
            ->assertHasTableActionErrors(['name' => 'unique']);
    }

    /**
     * The same name on another floor is a different desk, and the scoped rule
     * has to let it through — this is the case a plain `unique` would break.
     */
    public function test_the_floor_screen_allows_a_name_used_on_another_floor(): void
    {
        $first = Floor::factory()->create();
        $second = Floor::factory()->create();
        $first->workstations()->create(['name' => 'A-01']);

        Livewire::test(WorkstationsRelationManager::class, [
            'ownerRecord' => $second,
            'pageClass' => EditFloor::class,
        ])
            ->callTableAction('create', data: ['name' => 'A-01'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('workstations', [
            'floor_id' => $second->id,
            'name' => 'A-01',
        ]);
    }
}
