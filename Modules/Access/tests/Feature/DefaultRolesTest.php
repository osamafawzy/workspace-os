<?php

namespace Modules\Access\Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\Role;
use Modules\Access\Support\DefaultRoles;
use Tests\TestCase;

class DefaultRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function role(string $name): Role
    {
        return Role::query()->where('name', $name)->firstOrFail();
    }

    public function test_the_five_roles_exist_after_migrating(): void
    {
        foreach (['Super Admin', 'IT Admin', 'IT Engineer', 'IT Technician', 'Viewer'] as $name) {
            $this->assertDatabaseHas('roles', ['name' => $name]);
        }

        $this->assertTrue($this->role('Super Admin')->is_super_admin);
        $this->assertSame(1, Role::query()->where('is_super_admin', true)->count());
    }

    public function test_it_admin_gets_everything_but_is_not_a_super_admin(): void
    {
        $admin = $this->role('IT Admin');

        $this->assertFalse($admin->is_super_admin);
        $this->assertEqualsCanonicalizing(app(Permissions::class)->keys(), $admin->permissions);
    }

    public function test_the_viewer_can_look_but_not_touch(): void
    {
        $viewer = $this->role('Viewer');

        $this->assertTrue($viewer->grants('floors.view'));
        $this->assertTrue($viewer->grants('workstations.view'));

        foreach ($viewer->permissions as $permission) {
            $this->assertStringEndsWith('.view', $permission);
        }

        $this->assertFalse($viewer->grants('users.view'));
        $this->assertFalse($viewer->grants('audit.view'));
    }

    public function test_technicians_update_desks_and_arrange_plans_but_delete_nothing(): void
    {
        $technician = $this->role('IT Technician');

        $this->assertTrue($technician->grants('workstations.update'));
        $this->assertTrue($technician->grants('floors.arrange'));
        $this->assertFalse($technician->grants('workstations.delete'));
        $this->assertFalse($technician->grants('floors.update'));
        $this->assertFalse($technician->grants('users.view'));
    }

    public function test_engineers_build_but_do_not_manage_people_or_settings(): void
    {
        $engineer = $this->role('IT Engineer');

        $this->assertTrue($engineer->grants('floors.create'));
        $this->assertTrue($engineer->grants('workstations.update'));
        $this->assertTrue($engineer->grants('audit.view'));
        $this->assertFalse($engineer->grants('floors.delete'));
        $this->assertFalse($engineer->grants('users.update'));
        $this->assertFalse($engineer->grants('roles.view'));
        $this->assertFalse($engineer->grants('settings.manage'));
    }

    public function test_ensure_leaves_an_edited_role_alone(): void
    {
        $this->role('Viewer')->update(['permissions' => ['floors.view']]);

        DefaultRoles::ensure();

        $this->assertSame(['floors.view'], $this->role('Viewer')->refresh()->permissions);
    }

    public function test_sync_adds_a_new_modules_permissions_and_removes_nothing(): void
    {
        $this->role('Viewer')->update(['permissions' => ['floors.view', 'custom.kept']]);

        app(Permissions::class)->register('Assets', ['assets.view' => 'View assets', 'assets.delete' => 'Delete assets']);

        $this->artisan('access:sync-default-roles')->assertSuccessful();

        $viewer = $this->role('Viewer')->refresh();

        $this->assertContains('assets.view', $viewer->permissions);
        $this->assertNotContains('assets.delete', $viewer->permissions);
        $this->assertContains('custom.kept', $viewer->permissions);
        $this->assertContains('assets.delete', $this->role('IT Admin')->refresh()->permissions);
    }
}
