<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\CreateWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\EditWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

class WorkstationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_list_renders_every_desk_in_the_building(): void
    {
        $desks = Workstation::factory()->count(3)->create();

        Livewire::test(ListWorkstations::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($desks);
    }

    public function test_the_list_can_be_filtered_to_one_floor(): void
    {
        $first = Floor::factory()->create();
        $second = Floor::factory()->create();
        $onFirst = Workstation::factory()->count(2)->for($first)->create();
        $onSecond = Workstation::factory()->for($second)->create();

        Livewire::test(ListWorkstations::class)
            ->filterTable('floor_id', $first->getKey())
            ->assertCanSeeTableRecords($onFirst)
            ->assertCanNotSeeTableRecords([$onSecond]);
    }

    public function test_a_workstation_can_be_created_against_a_floor(): void
    {
        $floor = Floor::factory()->create();

        Livewire::test(CreateWorkstation::class)
            ->fillForm(['floor_id' => $floor->getKey(), 'name' => 'A-01'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('workstations', [
            'floor_id' => $floor->id,
            'name' => 'A-01',
        ]);
    }

    public function test_a_workstation_requires_a_floor_and_a_name(): void
    {
        Livewire::test(CreateWorkstation::class)
            ->fillForm(['floor_id' => null, 'name' => null])
            ->call('create')
            ->assertHasFormErrors(['floor_id' => 'required', 'name' => 'required']);
    }

    public function test_the_name_must_be_free_on_the_chosen_floor(): void
    {
        $floor = Floor::factory()->create();
        $floor->workstations()->create(['name' => 'A-01']);

        Livewire::test(CreateWorkstation::class)
            ->fillForm(['floor_id' => $floor->getKey(), 'name' => 'A-01'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    /**
     * The uniqueness rule is scoped to whichever floor is selected in the
     * form, so switching the floor has to re-scope it rather than keeping the
     * check pinned to the first choice.
     */
    public function test_the_uniqueness_check_follows_the_selected_floor(): void
    {
        $taken = Floor::factory()->create();
        $free = Floor::factory()->create();
        $taken->workstations()->create(['name' => 'A-01']);

        Livewire::test(CreateWorkstation::class)
            ->fillForm(['floor_id' => $taken->getKey(), 'name' => 'A-01'])
            ->assertHasNoFormErrors()
            ->fillForm(['floor_id' => $free->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('workstations', [
            'floor_id' => $free->id,
            'name' => 'A-01',
        ]);
    }

    public function test_a_desk_can_be_moved_to_another_floor(): void
    {
        $from = Floor::factory()->create();
        $to = Floor::factory()->create();
        $desk = Workstation::factory()->for($from)->create(['name' => 'A-01']);

        Livewire::test(EditWorkstation::class, ['record' => $desk->getKey()])
            ->fillForm(['floor_id' => $to->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($to->id, $desk->refresh()->floor_id);
    }

    public function test_a_desk_can_be_deleted(): void
    {
        $desk = Workstation::factory()->create();

        Livewire::test(ListWorkstations::class)
            ->callTableAction('delete', $desk);

        $this->assertDatabaseMissing('workstations', ['id' => $desk->id]);
    }
}
