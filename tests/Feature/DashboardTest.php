<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\QuickActionsWidget;
use App\Models\User;
use App\Support\Dashboard\QuickActions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Assets\Actions\AssignAssets;
use Modules\Assets\Actions\ReturnAssets;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Filament\Admin\Widgets\AssetStatsWidget;
use Modules\Assets\Filament\Admin\Widgets\AssetStatusChart;
use Modules\Assets\Filament\Admin\Widgets\AssignmentsReturnsChart;
use Modules\Assets\Filament\Admin\Widgets\RecentAssignmentsWidget;
use Modules\Assets\Filament\Admin\Widgets\RecentReturnsWidget;
use Modules\Assets\Models\Asset;
use Modules\Audit\Filament\Admin\Widgets\RecentActivityWidget;
use Modules\Employees\Filament\Admin\Widgets\EmployeeStatsWidget;
use Modules\Employees\Models\Employee;
use Modules\Workspace\Enums\WorkstationStatus;
use Modules\Workspace\Filament\Admin\Widgets\WorkstationStatsWidget;
use Modules\Workspace\Filament\Admin\Widgets\WorkstationStatusByFloorChart;
use Modules\Workspace\Models\Floor;
use Modules\Workspace\Models\Workstation;
use Tests\TestCase;

/**
 * Phase 10: the dashboard. Every widget is shown only to whoever may see what
 * it counts, and counts what it says.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_an_admin_gets_every_widget(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Dash Admin']));

        $this->get('/admin')->assertSuccessful()->assertSee('Signed in as Dash Admin');

        $widgets = collect(Livewire::test(Dashboard::class)->instance()->getVisibleWidgets())->map(fn ($widget) => $widget->widget ?? $widget)->all();

        foreach ([
            QuickActionsWidget::class, AssetStatsWidget::class, WorkstationStatsWidget::class, EmployeeStatsWidget::class,
            AssetStatusChart::class, AssignmentsReturnsChart::class, WorkstationStatusByFloorChart::class,
            RecentAssignmentsWidget::class, RecentReturnsWidget::class, RecentActivityWidget::class,
        ] as $widget) {
            $this->assertContains($widget, $widgets, class_basename($widget).' should be on the dashboard');
        }
    }

    public function test_widgets_follow_permissions(): void
    {
        $this->actingAs(User::factory()->withPermissions('workstations.view', 'floors.view')->create());

        $this->assertTrue(WorkstationStatsWidget::canView());
        $this->assertTrue(WorkstationStatusByFloorChart::canView());
        $this->assertFalse(AssetStatsWidget::canView());
        $this->assertFalse(EmployeeStatsWidget::canView());
        $this->assertFalse(RecentActivityWidget::canView());
        $this->assertFalse(RecentAssignmentsWidget::canView());

        // Quick actions only offer what this user can open.
        $actions = collect(app(QuickActions::class)->visible())->pluck('key')->all();
        $this->assertContains('floor-mapping', $actions);
        $this->assertNotContains('assign-assets', $actions);
        $this->assertNotContains('reports', $actions);

        // Somebody with nothing sees no quick actions at all.
        $this->actingAs(User::factory()->withPermissions('settings.manage')->create());
        $this->assertFalse(QuickActionsWidget::canView());
        $this->get('/admin')->assertSuccessful();
    }

    public function test_the_numbers_are_right(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $floor = Floor::factory()->create(['name' => 'First Floor']);
        Workstation::factory()->for($floor)->placed()->create(['status' => WorkstationStatus::Active]);
        Workstation::factory()->for($floor)->create(['status' => WorkstationStatus::Faulty]);
        Workstation::factory()->for($floor)->create(['status' => WorkstationStatus::Offline]);

        $employee = Employee::factory()->create();
        $leaver = Employee::factory()->create();
        $held = Asset::factory()->create();
        $kept = Asset::factory()->create();
        $back = Asset::factory()->create();
        Asset::factory()->create(['status' => AssetStatus::InRepair]);
        Asset::factory()->create(['warranty_expires_at' => today()->addDays(5)]);

        app(AssignAssets::class)->handle($employee, [$held->id, $back->id], $admin);
        app(AssignAssets::class)->handle($leaver, [$kept->id], $admin);
        app(ReturnAssets::class)->handle([$back->id => 'good'], [], $admin);
        $leaver->update(['status' => 'left', 'left_at' => today()]);

        Livewire::test(WorkstationStatsWidget::class)
            ->assertSeeInOrder(['Workstations', '3', 'Active', '1', 'Need attention', '2', 'On the map', '33%']);

        Livewire::test(AssetStatsWidget::class)
            ->assertSeeInOrder(['Assets', '5', 'Assigned', '2', 'Ready to hand out', '2', 'In repair or lost', '1', 'Held by leavers', '1', 'Warranty ending', '1']);

        Livewire::test(EmployeeStatsWidget::class)
            ->assertSeeInOrder(['Active', '1', 'On leave', '0']);

        $floorChart = Livewire::test(WorkstationStatusByFloorChart::class)->instance();
        $data = (fn () => $this->getData())->call($floorChart);
        $this->assertSame(['First Floor'], array_map(fn ($label) => str_contains($label, 'First Floor') ? 'First Floor' : $label, $data['labels']));
        $this->assertSame(['Active', 'Offline', 'Faulty'], array_column($data['datasets'], 'label'));

        $trend = Livewire::test(AssignmentsReturnsChart::class)->instance();
        $weeks = (fn () => $this->getData())->call($trend);
        $this->assertSame(3, array_sum($weeks['datasets'][0]['data']));
        $this->assertSame(1, array_sum($weeks['datasets'][1]['data']));
        $this->assertCount(12, $weeks['labels']);

        Livewire::test(RecentReturnsWidget::class)->assertSee($back->serial_number);
        Livewire::test(RecentAssignmentsWidget::class)->assertSee($kept->serial_number);
        Livewire::test(RecentActivityWidget::class)->assertSee('assets returned');
    }
}
