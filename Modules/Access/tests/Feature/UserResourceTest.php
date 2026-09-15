<?php

namespace Modules\Access\Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Modules\Access\Filament\Admin\Resources\Users\Pages\CreateUser;
use Modules\Access\Filament\Admin\Resources\Users\Pages\EditUser;
use Modules\Access\Filament\Admin\Resources\Users\Pages\ListUsers;
use Modules\Access\Models\Role;
use Tests\TestCase;

class UserResourceTest extends TestCase
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

    public function test_a_user_can_be_created_with_a_role(): void
    {
        $role = Role::factory()->create(['name' => 'Facilities']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sara',
                'email' => 'sara@workspace.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
                'roles' => [$role->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sara = User::query()->where('email', 'sara@workspace.test')->firstOrFail();

        $this->assertTrue(Hash::check('correct-horse', $sara->password));
        $this->assertTrue($sara->roles->contains($role));
    }

    public function test_a_new_user_needs_a_confirmed_password(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sara',
                'email' => 'sara@workspace.test',
                'password' => 'correct-horse',
                'password_confirmation' => 'something-else',
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'confirmed']);
    }

    public function test_an_empty_password_on_edit_keeps_the_old_one(): void
    {
        $user = User::factory()->withPermissions('floors.view')->create();
        $before = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Renamed', 'password' => '', 'password_confirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $user->refresh()->name);
        $this->assertSame($before, $user->password);
    }

    public function test_the_password_hash_never_leaves_the_model(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_the_seeded_admin_holds_super_admin(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@workspace.test')->firstOrFail();

        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_nobody_deletes_their_own_account(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete', $this->admin);
    }

    public function test_another_user_can_be_deleted(): void
    {
        $other = User::factory()->withPermissions('floors.view')->create();

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $other);

        $this->assertModelMissing($other);
    }

    public function test_the_last_super_admin_cannot_lose_the_role(): void
    {
        $plain = Role::factory()->create();

        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->fillForm(['roles' => [$plain->getKey()]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertTrue($this->admin->refresh()->isSuperAdmin());
    }

    public function test_super_admin_can_move_once_somebody_else_has_it(): void
    {
        User::factory()->superAdmin()->create();
        $plain = Role::factory()->create();

        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->fillForm(['roles' => [$plain->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->admin->refresh()->isSuperAdmin());
    }

    public function test_managing_users_is_not_a_way_to_become_super_admin(): void
    {
        $manager = User::factory()->withPermissions('users.view', 'users.update')->create();
        $superRole = Role::ensureSuperAdmin();
        $target = User::factory()->withPermissions('floors.view')->create();

        $this->actingAs($manager);

        // The super admin role is not on offer...
        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->assertFormFieldExists('roles')
            ->assertDontSee($superRole->name.' — full access');

        // ...picking it anyway does not stick...
        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['roles' => [$superRole->getKey()]])
            ->call('save');

        $this->assertFalse($target->refresh()->isSuperAdmin());

        // ...and an existing super admin is out of reach entirely.
        $this->get("/admin/users/{$this->admin->getKey()}/edit")->assertForbidden();
    }
}
