<?php

namespace Modules\Access\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\CreateRole;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\EditRole;
use Modules\Access\Filament\Admin\Resources\Roles\Pages\ListRoles;
use Modules\Access\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use Modules\Access\Models\Role;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    }

    public function test_a_role_is_saved_with_the_permissions_ticked_across_groups(): void
    {
        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Facilities',
                'permission_groups' => [
                    'floors' => ['floors.view', 'floors.arrange'],
                    'workstations' => ['workstations.view', 'workstations.update'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('name', 'Facilities')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['floors.view', 'floors.arrange', 'workstations.view', 'workstations.update'],
            $role->permissions,
        );
        $this->assertFalse($role->is_super_admin);
    }

    public function test_the_edit_screen_ticks_what_the_role_already_has(): void
    {
        $role = Role::factory()->granting('floors.view', 'users.view')->create();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->assertSchemaStateSet([
                'permission_groups.floors' => ['floors.view'],
                'permission_groups.users' => ['users.view'],
                'permission_groups.roles' => [],
            ]);
    }

    public function test_a_permission_that_does_not_exist_is_refused(): void
    {
        $role = Role::factory()->create();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.permission_groups.floors', ['floors.view', 'everything.forever'])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame([], $role->refresh()->permissions);
    }

    public function test_folding_the_groups_drops_anything_unregistered(): void
    {
        $this->assertSame(
            ['floors.view'],
            RoleForm::fromGroups(['floors' => ['floors.view', 'everything.forever'], 'junk' => 'x']),
        );
    }

    public function test_a_super_admin_can_create_a_super_admin_role(): void
    {
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Owners', 'is_super_admin' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Role::query()->where('name', 'Owners')->value('is_super_admin'));
    }

    public function test_the_only_super_admin_role_keeps_its_flag(): void
    {
        $role = Role::ensureSuperAdmin();

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['is_super_admin' => false])
            ->call('save')
            ->assertHasFormErrors(['is_super_admin']);

        $this->assertTrue($role->refresh()->is_super_admin);
    }

    public function test_the_last_super_admin_role_cannot_be_deleted(): void
    {
        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('delete', Role::ensureSuperAdmin());
    }

    public function test_an_ordinary_role_can_be_deleted_and_its_holders_lose_it(): void
    {
        $role = Role::factory()->create();
        $holder = User::factory()->create();
        $holder->roles()->attach($role);

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertModelMissing($role);
        $this->assertTrue($holder->refresh()->roles->isEmpty());
    }

    public function test_managing_roles_is_not_a_way_to_become_super_admin(): void
    {
        $manager = User::factory()->withPermissions('roles.view', 'roles.create', 'roles.update')->create();
        $this->actingAs($manager);

        // The switch is there but cannot be turned on...
        Livewire::test(CreateRole::class)
            ->assertFormFieldDisabled('is_super_admin');

        // ...setting it anyway does not stick...
        Livewire::test(CreateRole::class)
            ->set('data.name', 'Sneaky')
            ->set('data.is_super_admin', true)
            ->call('create');

        $this->assertFalse((bool) Role::query()->where('name', 'Sneaky')->value('is_super_admin'));

        // ...and the existing super admin role cannot be opened for editing.
        $this->get('/admin/roles/'.Role::ensureSuperAdmin()->getKey().'/edit')->assertForbidden();
    }
}
