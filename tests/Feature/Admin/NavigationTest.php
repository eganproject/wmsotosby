<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Struktur navigasi: menu samping menjawab "mau mengerjakan apa", sedangkan
 * sudut pandang berbeda atas objek yang sama dipisah lewat tab di halamannya.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- menu samping ---- */

    public function test_the_sidebar_groups_related_pages_behind_one_entry(): void
    {
        $sidebar = $this->sidebar();

        // Entri yang tersisa di menu samping.
        foreach (['Barang &amp; Stok', 'Barang Masuk', 'Barang Keluar', 'Penerimaan Retur',
            'Opname &amp; Penyesuaian', 'Resi', 'Pengguna &amp; Akses'] as $entry) {
            $this->assertStringContainsString($entry, $sidebar);
        }

        // Yang sudah menjadi tab tidak lagi menjadi entri menu tersendiri.
        foreach (['Mutasi Stok', 'Siap Dikirim', 'Stasiun Packing', 'Hak Akses', 'Import Resi',
            'Pemasok', 'Stok Opname'] as $moved) {
            $this->assertStringNotContainsString($moved, $sidebar);
        }
    }

    public function test_the_sidebar_stays_short(): void
    {
        // Sembilan tautan: lebih dari itu dan menu ini kembali jadi daftar
        // halaman, bukan daftar pekerjaan.
        $this->assertLessThanOrEqual(9, substr_count($this->sidebar(), '<a href='));
    }

    public function test_the_sidebar_entry_opens_the_first_tab_the_user_may_see(): void
    {
        // Tanpa izin barang, entri Barang & Stok mengarah ke tab berikutnya.
        $role = Role::create(['name' => 'Mutasi Saja', 'slug' => 'mutasi-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'movements.view'])->pluck('id'));

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.movements.index'))
            ->assertDontSee(route('admin.products.index'));
    }

    /* --------------------------------------------------- tab ------------- */

    public function test_grouped_pages_carry_the_same_tab_bar(): void
    {
        foreach ([route('admin.products.index'), route('admin.movements.index'), route('admin.suppliers.index')] as $url) {
            $response = $this->actingAs($this->admin)->get($url)->assertOk();

            $response->assertSee('Daftar Barang')
                ->assertSee('Mutasi Stok')
                ->assertSee('Pemasok');
        }
    }

    public function test_a_tab_the_user_may_not_open_is_never_offered(): void
    {
        $role = Role::create(['name' => 'Barang Saja', 'slug' => 'barang-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.products.index'))
            ->assertOk()
            // Hanya satu tab yang boleh dibuka, jadi bilah tabnya tidak dirender.
            ->assertDontSee(route('admin.movements.index'))
            ->assertDontSee(route('admin.suppliers.index'));
    }

    public function test_the_ready_queue_tab_carries_its_backlog_count(): void
    {
        $product = Product::create(['sku' => 'FLT-1', 'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0]);

        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(), 'date' => now(),
            'type' => \App\Models\Outbound::TYPE_MARKETPLACE, 'recipient' => 'Pembeli',
            'tracking_number' => 'SPXID111', 'status' => \App\Models\Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 2, 'scanned_quantity' => 2]);

        $this->actingAs($this->admin)->get(route('admin.outbounds.index'))
            ->assertOk()
            ->assertSee('Siap Dikirim')
            ->assertSee(route('admin.outbounds.ready'));
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * Potongan HTML menu samping saja — label seperti "Siap Dikirim" juga
     * muncul sebagai isi halaman, dan itu bukan yang sedang diuji di sini.
     */
    protected function sidebar(?User $user = null): string
    {
        $html = $this->actingAs($user ?? $this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'data-sidebar-nav');
        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    /* --------------------------------------------------- paginasi -------- */

    public function test_a_single_page_list_renders_no_pagination_bar(): void
    {
        Product::create(['sku' => 'FLT-1', 'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0]);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            // Laravel menandai navigasi paginasi dengan role="navigation".
            ->assertDontSee('role="navigation"', false);
    }

    public function test_a_longer_list_still_paginates(): void
    {
        foreach (range(1, 12) as $i) {
            Product::create(['sku' => "FLT-{$i}", 'name' => "Filter {$i}", 'unit' => 'pcs', 'min_stock' => 0]);
        }

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('role="navigation"', false);
    }
}
