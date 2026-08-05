<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Beberapa akun mengerjakan input bersama-sama.
 *
 * Yang diuji di sini bukan sekadar "bisa membuka halaman", melainkan bahwa
 * seorang petugas gudang bisa menuntaskan pekerjaannya tanpa harus menunggu
 * admin — termasuk saat menemukan SKU yang belum terdaftar.
 */
class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->staff = User::factory()->create([
            'name' => 'Budi Gudang',
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);
    }

    /* --------------------------------------------------- input harian ---- */

    public function test_warehouse_staff_can_register_a_new_product(): void
    {
        $this->actingAs($this->staff)->post(route('admin.products.store'), [
            'sku' => 'KAB-BARU-1',
            'name' => 'Kabel Baru',
            'unit' => 'pcs',
            'min_stock' => 0,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['sku' => 'KAB-BARU-1']);
    }

    public function test_warehouse_staff_can_register_a_new_supplier(): void
    {
        $this->actingAs($this->staff)->post(route('admin.suppliers.store'), [
            'name' => 'Agen Malang',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['name' => 'Agen Malang']);
    }

    public function test_warehouse_staff_can_open_every_input_page(): void
    {
        Product::create(['sku' => 'FLT-1', 'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0]);
        Supplier::create(['code' => 'SUP-1', 'name' => 'Agen Surabaya']);

        foreach ([
            'admin.products.create',
            'admin.inbounds.create',
            'admin.outbounds.create',
            'admin.outbounds.marketplace',
            'admin.returns.create',
            'admin.returns.marketplace',
            'admin.adjustments.create',
            'admin.opnames.create',
            'admin.imports.create',
        ] as $route) {
            $this->actingAs($this->staff)->get(route($route))->assertOk();
        }
    }

    /**
     * Batasnya tetap ada: staf mengajukan, bukan menyetujui.
     */
    public function test_warehouse_staff_still_may_not_approve_or_manage_accounts(): void
    {
        $this->actingAs($this->staff)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('admin.permissions.index'))->assertForbidden();
        $this->actingAs($this->staff)->delete(route('admin.products.destroy', Product::create([
            'sku' => 'FLT-2', 'name' => 'Filter 2', 'unit' => 'pcs', 'min_stock' => 0,
        ])))->assertForbidden();
    }

    /* --------------------------------------------------- buat akun ------- */

    public function test_the_new_account_form_explains_what_each_role_may_do(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Staff Gudang')
            ->assertSee('Input data')
            ->assertSee('Menyetujui')
            ->assertSee('Buatkan kata sandi')
            // Role dipilih lewat kartu, bukan dropdown.
            ->assertSee('type="radio" name="role_id"', false);
    }

    public function test_an_admin_can_create_a_working_account_in_one_step(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Sari Gudang',
            'email' => 'sari@wmsotosby.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
            'is_active' => true,
        ])->assertRedirect(route('admin.users.index'));

        $sari = User::where('email', 'sari@wmsotosby.test')->firstOrFail();

        // Akun barunya langsung bisa bekerja, bukan sekadar bisa masuk.
        $this->actingAs($sari)->get(route('admin.inbounds.create'))->assertOk();
        $this->assertTrue($sari->can('inbounds.create'));
        $this->assertFalse($sari->can('inbounds.approve'));
    }

    /* --------------------------------------------------- tampilan ponsel - */

    public function test_the_phone_gets_a_bottom_navigation_bar(): void
    {
        $response = $this->actingAs($this->staff)->get(route('admin.dashboard'))->assertOk();

        $response->assertSee('data-bottom-nav', false)
            ->assertSee('Packing')
            ->assertSee('Retur')
            // Slot terakhir selalu membuka menu penuh.
            ->assertSee('Menu');
    }

    public function test_the_bottom_bar_only_offers_what_the_account_may_open(): void
    {
        $role = Role::create(['name' => 'Pengamat', 'slug' => 'pengamat']);
        $role->permissions()->sync(\App\Models\Permission::where('slug', 'dashboard.view')->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-bottom-nav', false)
            ->assertDontSee('Packing')
            ->assertDontSee('Retur');
    }

    public function test_the_bottom_bar_is_swapped_along_with_the_page(): void
    {
        // Navigasi AJAX harus ikut memperbarui bilah bawah, kalau tidak
        // tab aktifnya menunjuk halaman yang sudah ditinggalkan.
        $this->actingAs($this->staff)
            ->withHeader('X-Page-Fragment', '1')
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('data-fragment="bottom-nav"', false);
    }
}
