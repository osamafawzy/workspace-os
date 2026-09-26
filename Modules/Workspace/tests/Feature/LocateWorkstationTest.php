<?php

namespace Modules\Workspace\Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Audit\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Modules\Workspace\Filament\Admin\Actions\WorkstationDetailsAction;
use Modules\Workspace\Filament\Admin\Pages\SearchWorkstation;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Filament\Admin\Resources\Floors\Pages\FloorPlan;
use Modules\Workspace\Filament\Admin\Resources\Workstations\Pages\ListWorkstations;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 3: find a workstation, see its record, and be taken to it on the map.
 */
class LocateWorkstationTest extends TestCase
{
    use RefreshDatabase;

    protected Floor $first;

    protected Floor $second;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->first = Floor::factory()->create(['name' => 'First Floor']);
        $this->second = Floor::factory()->create(['name' => 'Second Floor', 'building_id' => $this->first->building_id]);
    }

    protected function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ---- the search page ---------------------------------------------------

    public function test_the_search_page_needs_permission_to_see_workstations(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get('/admin/search-workstation')
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions('workstations.view')->create())
            ->get('/admin/search-workstation')
            ->assertSuccessful()
            ->assertSee('Search Workstation');
    }

    public function test_the_sidebar_entry_is_the_real_page_now(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/coming-soon?item=search-workstation')
            ->assertNotFound();
    }

    public function test_results_come_from_the_address_bar_and_explain_themselves(): void
    {
        $this->actingAs($this->admin());

        Workstation::factory()->for($this->first)->placed(50, 50)->create(['name' => 'A-03', 'mac_address' => '00:1A:2B:3C:4D:5E']);
        Workstation::factory()->for($this->second)->create(['name' => 'B-14']);

        $this->get('/admin/search-workstation?q=001a.2b3c')
            ->assertSuccessful()
            ->assertSee('A-03')
            ->assertSee('MAC Address: 00:1A:2B:3C:4D:5E')
            ->assertSee('/admin/floors/'.$this->first->id.'/plan?locate=A-03', escape: false)
            ->assertDontSee('B-14');

        Livewire::test(SearchWorkstation::class)
            ->set('search', 'B-14')
            ->assertSee('B-14')
            ->assertSee('Not on the map')
            ->set('search', 'nothing-like-this')
            ->assertSee('No workstation matches');
    }

    public function test_the_details_panel_shows_the_record_and_the_ways_on(): void
    {
        $this->actingAs($this->admin());

        $desk = Workstation::factory()->for($this->first)->wired(['ip_address' => '10.20.1.33'])->placed(50, 50)->create(['name' => 'A-03']);

        Livewire::test(SearchWorkstation::class)
            ->mountAction('details', ['workstation' => $desk->id])
            ->assertMountedActionModalSee(['A-03', 'First Floor', 'IP Address', '10.20.1.33', 'Recent history', 'Created']);

        $details = TestAction::make('details')->arguments(['workstation' => $desk->id]);

        Livewire::test(SearchWorkstation::class)
            ->assertActionVisible([$details, 'edit'])
            ->assertActionVisible([$details, 'history'])
            ->assertActionHasUrl([$details, 'locate'], FloorResource::getUrl('plan', ['record' => $this->first, 'locate' => 'A-03']))
            ->assertActionHasUrl([$details, 'edit'], WorkstationResource::getUrl('edit', ['record' => $desk]))
            ->assertActionEnabled([$details, 'locate'])
            ->assertActionDisabled([$details, 'asset']);
    }

    public function test_the_details_panel_refuses_a_desk_the_user_may_not_see(): void
    {
        $desk = Workstation::factory()->for($this->first)->create();

        // The id comes from the browser: without the permission it is refused,
        // whatever page mounted the panel.
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        $this->expectException(HttpException::class);

        WorkstationDetailsAction::desk(null, ['workstation' => $desk->id]);
    }

    public function test_the_details_panel_offers_only_what_the_user_may_do(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view')->create());

        $desk = Workstation::factory()->for($this->first)->create(['name' => 'A-03']);

        Livewire::test(SearchWorkstation::class)
            ->mountAction('details', ['workstation' => $desk->id])
            ->assertMountedActionModalSee('Not on the floor map yet')
            ->assertMountedActionModalDontSee('Recent history');

        $details = TestAction::make('details')->arguments(['workstation' => $desk->id]);

        // No floors.view, so no map to go to; no update, no audit.view.
        Livewire::test(SearchWorkstation::class)
            ->assertActionHidden([$details, 'edit'])
            ->assertActionHidden([$details, 'history'])
            ->assertActionHidden([$details, 'locate']);
    }

    public function test_the_workstation_list_has_the_details_panel_too(): void
    {
        $this->actingAs($this->admin());

        $desk = Workstation::factory()->for($this->first)->create(['name' => 'A-03', 'computer_name' => 'HQ-PC-1']);

        Livewire::test(ListWorkstations::class)
            ->mountTableAction('details', $desk)
            ->assertMountedActionModalSee(['A-03', 'HQ-PC-1']);
    }

    public function test_the_history_link_opens_the_audit_log_on_that_desk(): void
    {
        $this->actingAs($this->admin());

        $desk = Workstation::factory()->for($this->first)->create(['name' => 'A-03']);
        $other = Workstation::factory()->for($this->first)->create(['name' => 'A-04']);
        $desk->update(['computer_name' => 'RENAMED']);

        $url = WorkstationDetailsAction::historyUrl($desk);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($desk->getMorphClass(), $query['filters']['record']['type']);

        $mine = AuditLog::query()->where('auditable_type', $desk->getMorphClass())->where('auditable_id', $desk->id)->get();
        $theirs = AuditLog::query()->where('auditable_type', $other->getMorphClass())->where('auditable_id', $other->id)->get();

        $this->assertCount(2, $mine);

        Livewire::withQueryParams($query)
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords($mine)
            ->assertCanNotSeeTableRecords($theirs);
    }

    // ---- locate links ------------------------------------------------------

    public function test_a_locate_link_goes_to_the_desk_on_its_floor_map(): void
    {
        $this->actingAs($this->admin());

        Workstation::factory()->for($this->second)->placed(20, 30)->create(['name' => 'B-14', 'computer_name' => 'HQ-L2-WS114']);

        $this->get('/admin/locate?q=hq-l2-ws114')
            ->assertRedirect('/admin/floors/'.$this->second->id.'/plan?locate=B-14');
    }

    public function test_a_locate_link_that_does_not_settle_on_a_mapped_desk_shows_the_search(): void
    {
        $this->actingAs($this->admin());

        Workstation::factory()->for($this->first)->placed()->create(['name' => 'A-03']);
        Workstation::factory()->for($this->second)->placed()->create(['name' => 'A-03']);
        Workstation::factory()->for($this->second)->create(['name' => 'B-99']);

        // Two desks called A-03: the person chooses.
        $this->get('/admin/locate?q=A-03')->assertRedirect('/admin/search-workstation?q=A-03');

        // One desk, not on a map yet.
        $this->get('/admin/locate?q=B-99')->assertRedirect('/admin/search-workstation?q=B-99');
    }

    public function test_the_map_is_told_which_desk_to_light_up(): void
    {
        $this->actingAs($this->admin());

        $desk = Workstation::factory()->for($this->first)->placed(50, 50)->create(['name' => 'A-03', 'computer_name' => 'HQ-PC-3']);

        $config = Livewire::withQueryParams(['locate' => 'a-03'])
            ->test(FloorPlan::class, ['record' => $this->first->id])
            ->instance()
            ->mapConfig();

        $this->assertSame(['term' => 'a-03', 'desk' => $desk->id], $config['locate']);
        $this->assertSame('HQ-PC-3', $config['desks'][$desk->id]['computer']);
        $this->assertStringContainsString('__DESK__', $config['historyUrl']);

        // By PC name too; and a name not on this floor says so.
        $byPc = Livewire::withQueryParams(['locate' => 'HQ-PC-3'])->test(FloorPlan::class, ['record' => $this->first->id])->instance()->mapConfig();
        $this->assertSame($desk->id, $byPc['locate']['desk']);

        $miss = Livewire::withQueryParams(['locate' => 'Z-99'])->test(FloorPlan::class, ['record' => $this->first->id])->instance()->mapConfig();
        $this->assertSame(['term' => 'Z-99', 'desk' => null], $miss['locate']);

        // A desk on another floor is not this map's to light up.
        Workstation::factory()->for($this->second)->placed()->create(['name' => 'B-14']);
        $elsewhere = Livewire::withQueryParams(['locate' => 'B-14'])->test(FloorPlan::class, ['record' => $this->first->id])->instance()->mapConfig();
        $this->assertNull($elsewhere['locate']['desk']);
    }

    public function test_the_maps_find_box_finds_desks_on_other_floors(): void
    {
        $this->actingAs($this->admin());

        Workstation::factory()->for($this->second)->placed()->create(['name' => 'B-14', 'ip_address' => '10.20.9.14']);
        Workstation::factory()->for($this->second)->create(['name' => 'B-15']);
        Workstation::factory()->for($this->first)->create(['name' => 'A-01']);

        $map = Livewire::test(FloorPlan::class, ['record' => $this->first->id])->instance();

        $found = $map->locateElsewhere('10.20.9.14');
        $this->assertTrue($found['found']);
        $this->assertStringEndsWith('/admin/floors/'.$this->second->id.'/plan?locate=B-14', $found['url']);

        $this->assertFalse($map->locateElsewhere('B-15')['found']);
        $this->assertStringContainsString('not on its map yet', $map->locateElsewhere('B-15')['message']);
        $this->assertStringContainsString('on this floor', $map->locateElsewhere('A-01')['message']);
        $this->assertStringContainsString('No workstation matches', $map->locateElsewhere('nothing')['message']);

        $several = $map->locateElsewhere('B-1');
        $this->assertFalse($several['found']);
        $this->assertStringContainsString('search-workstation?q=B-1', $several['url']);
    }

    public function test_finding_desks_elsewhere_from_the_map_needs_permission_to_see_workstations(): void
    {
        $this->actingAs(User::factory()->withPermissions('floors.view')->create());

        Livewire::test(FloorPlan::class, ['record' => $this->first->id])
            ->call('locateElsewhere', 'B-14')
            ->assertForbidden();
    }

    // ---- Ctrl+K --------------------------------------------------------------

    public function test_global_search_takes_a_mapped_desk_to_the_map(): void
    {
        $this->actingAs($this->admin());

        Workstation::factory()->for($this->second)->placed()->create(['name' => 'B-14', 'mac_address' => 'AA:BB:CC:DD:EE:FF', 'computer_name' => 'HQ-L2']);
        Workstation::factory()->for($this->first)->create(['name' => 'A-01', 'mac_address' => 'AA:BB:CC:00:00:01']);

        $results = WorkstationResource::getGlobalSearchResults('aabb.cc')->keyBy(fn ($result) => (string) $result->title);

        $this->assertStringEndsWith('/admin/floors/'.$this->second->id.'/plan?locate=B-14', $results['Second Floor · B-14']->url);
        $this->assertSame('HQ-L2', $results['Second Floor · B-14']->details['PC']);
        $this->assertStringEndsWith('/admin/search-workstation?q=A-01', $results['First Floor · A-01']->url);
    }
}
