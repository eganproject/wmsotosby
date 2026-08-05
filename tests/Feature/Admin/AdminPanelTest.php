<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_pages_are_rendered(): void
    {
        $role = Role::where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);

        $pages = [
            route('admin.dashboard'),
            route('admin.users.index'),
            route('admin.users.create'),
            route('admin.users.edit', $user),
            route('admin.users.show', $user),
            route('admin.roles.index'),
            route('admin.roles.create'),
            route('admin.roles.edit', $role),
            route('admin.permissions.index'),
            route('profile.edit'),
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    public function test_user_can_be_created_updated_and_deleted(): void
    {
        $role = Role::where('slug', 'staff-gudang')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Budi Santoso',
            'email' => 'budi@wmsotosby.test',
            'phone' => '081234567890',
            'role_id' => $role->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => '1',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'budi@wmsotosby.test')->firstOrFail();
        $this->assertTrue($user->is_active);

        $this->actingAs($this->admin)->put(route('admin.users.update', $user), [
            'name' => 'Budi Updated',
            'email' => 'budi@wmsotosby.test',
            'role_id' => $role->id,
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertSame('Budi Updated', $user->refresh()->name);
        $this->assertFalse($user->is_active);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'));

        $this->assertNull($user->fresh());
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->admin))
            ->assertSessionHas('error');

        $this->assertNotNull($this->admin->fresh());
    }

    public function test_role_can_be_created_with_permissions_and_deleted(): void
    {
        $permissions = Permission::whereIn('slug', ['dashboard.view', 'users.view'])->pluck('id')->all();

        $this->actingAs($this->admin)->post(route('admin.roles.store'), [
            'name' => 'Supervisor Gudang',
            'slug' => 'supervisor-gudang',
            'description' => 'Mengawasi operasional gudang.',
            'permissions' => $permissions,
        ])->assertRedirect(route('admin.roles.index'));

        $role = Role::where('slug', 'supervisor-gudang')->firstOrFail();
        $this->assertCount(2, $role->permissions);

        $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))
            ->assertRedirect(route('admin.roles.index'));

        $this->assertNull($role->fresh());
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::where('slug', 'admin')->firstOrFail();
        User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))
            ->assertSessionHas('error');

        $this->assertNotNull($role->fresh());
    }

    public function test_super_admin_role_cannot_be_deleted(): void
    {
        $role = Role::where('slug', 'super-admin')->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.roles.destroy', $role))
            ->assertSessionHas('error');

        $this->assertNotNull($role->fresh());
    }

    public function test_permission_matrix_can_be_saved(): void
    {
        $role = Role::where('slug', 'staff-gudang')->firstOrFail();
        $permissions = Permission::whereIn('slug', ['dashboard.view', 'users.view', 'users.create'])->pluck('id')->all();

        $this->actingAs($this->admin)->put(route('admin.permissions.update'), [
            'matrix' => [$role->id => $permissions],
        ])->assertRedirect(route('admin.permissions.index'));

        $this->assertCount(3, $role->refresh()->permissions);
    }

    public function test_user_without_permission_cannot_access_user_management(): void
    {
        $role = Role::where('slug', 'staff-gudang')->firstOrFail();
        $staff = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();
    }
}
