<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentOrder;
use App\Models\StockOpname;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjagaan atas cacat yang ditemukan lewat penelusuran, bukan lewat laporan.
 *
 * Tiap tes di sini pernah gagal pada kode yang sudah berjalan.
 */
class BugHuntTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- otorisasi ------- */

    /**
     * Dashboard memuat saldo stok, barang menipis, dan mutasi terakhir — semua
     * angka operasional. Izinnya ada di matriks hak akses, tetapi tidak pernah
     * benar-benar diperiksa: siapa pun yang bisa masuk melihat isinya.
     */
    public function test_the_dashboard_checks_its_own_permission(): void
    {
        $viewer = $this->userWithout('dashboard.view');

        $this->actingAs($viewer)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
    }

    /* --------------------------------------------------- hapus barang ---- */

    /**
     * Barang yang masih dipakai dokumen draft tidak boleh dihapus, dan
     * penolakannya harus berupa pesan — bukan galat basis data.
     *
     * Kunci asing pada baris dokumen memakai RESTRICT, sedangkan penghapusan
     * hanya memeriksa mutasi stok. Dokumen draft belum menghasilkan mutasi apa
     * pun, jadi pemeriksaannya lolos dan penghapusannya menabrak kunci asing.
     */
    public function test_a_product_used_by_a_draft_document_is_refused_politely(): void
    {
        $product = $this->makeProduct();

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * Sesi opname bercakupan "semua" memasukkan seluruh barang ke barisnya.
     * Sejak satu sesi pernah dibuka, setiap barang punya baris opname — dan
     * inilah yang membuat cacat di atas gampang sekali tersentuh.
     */
    public function test_a_product_listed_in_an_opname_session_is_refused_politely(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => StockOpname::SCOPE_ALL,
        ])->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /** Barang yang benar-benar belum dipakai tetap boleh dihapus. */
    public function test_an_unused_product_can_still_be_deleted(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /* --------------------------------------------------- alur dokumen ---- */

    /**
     * Kotak masuk persetujuan hanya mengurus dokumen yang benar-benar diajukan.
     *
     * Tanpa penjagaan ini, satu permintaan langsung ke alamat persetujuan bisa
     * memposting dokumen yang masih draft — stoknya bergerak sementara kolom
     * "diajukan oleh" tetap kosong, dan jejak auditnya menjadi bohong. Jalur
     * sah untuk menyetujui langsung sudah ada, yaitu tombol simpan & setujui,
     * dan jalur itu mencatat pengajunya.
     */
    public function test_the_inbox_refuses_a_document_that_was_never_submitted(): void
    {
        $product = $this->makeProduct();

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($this->admin)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]))
            ->assertSessionHas('error');

        $inbound->refresh();

        $this->assertTrue($inbound->isDraft());
        $this->assertSame(0, $product->refresh()->stock, 'Stok tidak boleh bergerak dari dokumen yang belum diajukan.');
    }

    /**
     * Dokumen yang ditolak harus kembali lewat penyusunnya: diperbaiki, lalu
     * diajukan ulang. Menyetujuinya langsung melompati perbaikan itu.
     */
    public function test_the_inbox_refuses_a_rejected_document(): void
    {
        $product = $this->makeProduct();

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_REJECTED, 'rejection_reason' => 'Jumlah tidak sesuai.',
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($this->admin)
            ->post(route('admin.approvals.approve', ['inbound', $inbound->id]))
            ->assertSessionHas('error');

        $this->assertTrue($inbound->refresh()->isRejected());
        $this->assertSame(0, $product->refresh()->stock);
    }

    /* --------------------------------------------------- tampilan -------- */

    /**
     * Kotak centang paket batal dimatikan lewat atribut Blade sekaligus diikat
     * Alpine pada atribut yang sama. Alpine menang, dan ikatannya bernilai
     * salah — sehingga kotaknya hidup kembali begitu halaman selesai dimuat.
     */
    public function test_a_cancelled_package_stays_unselectable(): void
    {
        $order = $this->makeCancelledOrder();
        $outbound = $this->makePackage($order);

        $html = $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'disabled="disabled" :disabled=',
            $html,
            'Atribut statis dan ikatan Alpine pada atribut yang sama: ikatannya menang dan mematikan penjagaannya.',
        );

        // Ikatannya sendiri yang harus memuat kedua sebab.
        $this->assertMatchesRegularExpression(
            '/:disabled="isSent\('.$outbound->id.'\) \|\| true"/',
            $html,
        );
    }

    /* --------------------------------------------------- helpers --------- */

    protected function userWithout(string $permission): User
    {
        $role = Role::create(['name' => 'Terbatas', 'slug' => 'terbatas']);

        $role->permissions()->sync(
            Permission::where('slug', '!=', $permission)->pluck('id'),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    protected function makeProduct(): Product
    {
        return Product::create([
            'sku' => 'FLT-1', 'name' => 'Filter Oli', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    protected function makeCancelledOrder(): ShipmentOrder
    {
        $import = \App\Models\ShipmentImport::create([
            'filename' => 'ginee.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        return $import->orders()->create([
            'tracking_number' => 'SPXID111',
            'marketplace' => 'Shopee',
            'cancelled_at' => now(),
        ]);
    }

    protected function makePackage(ShipmentOrder $order): Outbound
    {
        $product = $this->makeProduct();

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'marketplace' => 'Shopee',
            'recipient' => 'Pembeli', 'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT, 'resi_verified_at' => now(),
        ]);

        $outbound->items()->create([
            'product_id' => $product->id, 'quantity' => 2, 'scanned_quantity' => 2,
        ]);

        return $outbound;
    }
}
