<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\StockApiAllowedIp;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockApiAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        config([
            'stock_api.enabled' => true,
            'stock_api.token' => 'test-secret',
        ]);
    }

    public function test_super_admin_can_open_the_ip_management_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.stock-api-access.index'))
            ->assertOk()
            ->assertSee('Akses API Stok')
            ->assertSee('Izinkan IP baru');
    }

    public function test_allowed_ip_can_be_created_updated_and_deleted(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.stock-api-access.store'), [
            'ip_address' => '127.0.0.1',
            'label' => 'Server pusat',
        ])->assertRedirect();

        $allowedIp = StockApiAllowedIp::firstOrFail();
        $this->assertTrue($allowedIp->is_active);

        $this->actingAs($this->superAdmin)->put(route('admin.stock-api-access.update', $allowedIp), [
            'ip_address' => '127.0.0.1',
            'label' => 'Dihentikan sementara',
            'is_active' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('stock_api_allowed_ips', [
            'id' => $allowedIp->id,
            'label' => 'Dihentikan sementara',
            'is_active' => false,
        ]);

        $this->withToken('test-secret')->getJson('/api/v1/health')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'IP_NOT_ALLOWED');

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.stock-api-access.destroy', $allowedIp))
            ->assertRedirect();

        $this->assertDatabaseMissing('stock_api_allowed_ips', ['id' => $allowedIp->id]);
    }

    public function test_invalid_or_duplicate_ip_is_rejected(): void
    {
        StockApiAllowedIp::create(['ip_address' => '127.0.0.1', 'is_active' => true]);

        $this->actingAs($this->superAdmin)->post(route('admin.stock-api-access.store'), [
            'ip_address' => '127.0.0.1',
        ])->assertSessionHasErrors('ip_address');

        $this->actingAs($this->superAdmin)->post(route('admin.stock-api-access.store'), [
            'ip_address' => 'bukan-ip',
        ])->assertSessionHasErrors('ip_address');
    }

    public function test_warehouse_staff_cannot_manage_api_access(): void
    {
        $staff = User::factory()->create([
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);

        $this->actingAs($staff)
            ->get(route('admin.stock-api-access.index'))
            ->assertForbidden();
    }
}
