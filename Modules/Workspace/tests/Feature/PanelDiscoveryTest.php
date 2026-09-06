<?php

namespace Modules\Workspace\Tests\Feature;

use App\Support\ModuleComponents;
use Filament\Facades\Filament;
use Modules\Workspace\Filament\Admin\Resources\Floors\FloorResource;
use Modules\Workspace\Filament\Admin\Resources\Workstations\WorkstationResource;
use Tests\TestCase;

/**
 * The panel finds a module's screens without the panel provider naming them.
 * If this breaks, every resource in every module silently vanishes from the
 * navigation with no error anywhere — so it is worth a test of its own.
 */
class PanelDiscoveryTest extends TestCase
{
    public function test_the_workspace_module_is_enabled(): void
    {
        $this->assertContains('Workspace', ModuleComponents::enabledModules());
    }

    public function test_the_admin_panel_discovers_module_resources(): void
    {
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(FloorResource::class, $resources);
        $this->assertContains(WorkstationResource::class, $resources);
    }
}
