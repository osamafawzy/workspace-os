<?php

namespace Modules\Access\Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Models\Role;
use Modules\Workspace\Models\Floor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who gets into the panel, and what a role lets them reach once inside.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_with_no_role_cannot_enter_the_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_any_role_lets_an_account_in(): void
    {
        $this->actingAs(User::factory()->withPermissions()->create())
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_a_super_admin_holds_permissions_nobody_has_registered_yet(): void
    {
        $admin = User::factory()->superAdmin()->create();

        app(Permissions::class)->register('Later', ['later.do' => 'Something added next year']);

        $this->assertTrue($admin->hasPermission('later.do'));
        $this->assertFalse(User::factory()->withPermissions('floors.view')->create()->hasPermission('later.do'));
    }

    public function test_permissions_from_several_roles_add_up(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach([
            Role::factory()->granting('floors.view')->create()->getKey(),
            Role::factory()->granting('workstations.view')->create()->getKey(),
        ]);

        $this->assertTrue($user->hasPermission('floors.view'));
        $this->assertTrue($user->hasPermission('workstations.view'));
        $this->assertFalse($user->hasPermission('floors.delete'));
    }

    public static function screens(): array
    {
        return [
            'floors' => ['/admin/floors', 'floors.view'],
            'new floor' => ['/admin/floors/create', 'floors.create'],
            'workstations' => ['/admin/workstations', 'workstations.view'],
            'new workstation' => ['/admin/workstations/create', 'workstations.create'],
            'users' => ['/admin/users', 'users.view'],
            'new user' => ['/admin/users/create', 'users.create'],
            'roles' => ['/admin/roles', 'roles.view'],
            'new role' => ['/admin/roles/create', 'roles.create'],
        ];
    }

    #[DataProvider('screens')]
    public function test_each_screen_needs_its_permission(string $url, string $permission): void
    {
        // The list permission alongside, because a create screen sits inside
        // its resource and the resource itself needs "view".
        $view = str($permission)->before('.')->append('.view')->toString();

        $this->actingAs(User::factory()->withPermissions('dashboard.none')->create())
            ->get($url)
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions($view, $permission)->create())
            ->get($url)
            ->assertSuccessful();
    }

    public function test_viewing_floors_does_not_open_the_edit_screen(): void
    {
        $floor = Floor::factory()->create();

        $this->actingAs(User::factory()->withPermissions('floors.view')->create())
            ->get("/admin/floors/{$floor->getKey()}/edit")
            ->assertForbidden();
    }

    public function test_the_access_screens_are_in_the_panel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin/users')->assertSuccessful()->assertSee('Users');
        $this->get('/admin/roles')->assertSuccessful()->assertSee('Super Admin');
    }
}
