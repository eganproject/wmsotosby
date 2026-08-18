<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cocokkan ulang SKU pesanan dari halaman Import Resi.
 *
 * Pencocokan hanya terjadi saat berkas diunggah, jadi resi yang masuk sebelum
 * barangnya didaftarkan menggantung selamanya. Berkas tes ini menjaga satu
 * janji: mengulanginya boleh mengisi yang kosong, dan tidak boleh menyentuh
 * apa pun selain itu.
 */
class ShipmentSkuRematchTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- mencocokkan ----- */

    public function test_a_waybill_is_rematched_after_the_product_is_registered(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD']);

        $this->assertFalse($order->isFullyMatched());

        $product = $this->makeProduct('FLT-OLI-STD');

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.rematch', $order))
            ->assertRedirect(route('admin.imports.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 baris'));

        $this->assertSame($product->id, $order->items()->first()->product_id);
        $this->assertTrue($order->refresh()->isFullyMatched());
    }

    public function test_the_bulk_button_matches_every_waybill_at_once(): void
    {
        $this->importOrder('SPXID111', ['FLT-OLI-STD']);
        $this->importOrder('SPXID222', ['FLT-OLI-STD', 'OLI-MTC-1L']);

        $this->makeProduct('FLT-OLI-STD');
        $this->makeProduct('OLI-MTC-1L');

        $this->actingAs($this->admin)
            ->post(route('admin.imports.rematch'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '3 baris'));

        $this->assertSame(0, \App\Models\ShipmentOrderItem::whereNull('product_id')->count());
    }

    public function test_matching_ignores_case_and_stray_spaces(): void
    {
        // Persis bentuk yang lazim datang dari berkas ekspor marketplace.
        $order = $this->importOrder('SPXID111', ['  flt-oli-std ']);
        $product = $this->makeProduct('FLT-OLI-STD');

        $this->actingAs($this->admin)->post(route('admin.imports.orders.rematch', $order));

        $this->assertSame($product->id, $order->items()->first()->product_id);
    }

    /* --------------------------------------------------- penjagaan ------- */

    public function test_a_row_that_already_points_at_a_product_is_never_touched(): void
    {
        $filter = $this->makeProduct('FLT-OLI-STD');
        $oli = $this->makeProduct('OLI-MTC-1L');

        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD', 'OLI-MTC-1L']);

        /*
            Baris pertama sengaja ditautkan ke barang yang "salah" — persis
            keadaan yang bisa terjadi bila SKU-nya pernah dipakai barang lain,
            atau bila seseorang memperbaikinya dengan tangan.

            Mencocokkan ulang tidak boleh menimpanya: dokumen gudang mungkin
            sudah dibentuk dari tautan itu, dan mengubahnya diam-diam berarti
            dokumen yang sudah ada berganti arti.
        */
        $order->items()->where('sku', 'FLT-OLI-STD')->update(['product_id' => $oli->id]);
        $order->items()->where('sku', 'OLI-MTC-1L')->update(['product_id' => null]);

        $this->actingAs($this->admin)->post(route('admin.imports.orders.rematch', $order));

        $this->assertSame($oli->id, $order->items()->where('sku', 'FLT-OLI-STD')->value('product_id'));
        $this->assertSame($oli->id, $order->items()->where('sku', 'OLI-MTC-1L')->value('product_id'));
        $this->assertNotSame($filter->id, $order->items()->where('sku', 'FLT-OLI-STD')->value('product_id'));
    }

    public function test_an_ambiguous_sku_is_reported_instead_of_guessed(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD']);

        $this->makeProduct('FLT-OLI-STD');

        /*
            Dua barang yang SKU-nya sama bagi manusia tetapi berbeda bagi
            indeks unik.

            Apakah ini mungkin sepenuhnya bergantung pada collation basis
            datanya: MySQL bawaan membandingkan tanpa membedakan huruf besar
            dan mengabaikan spasi di ujung, jadi baris kedua ditolak indeksnya
            sendiri — di production keadaan ini tidak bisa terjadi. SQLite
            membandingkan apa adanya, dan di sanalah penjagaannya diuji.

            Tesnya dilewati, bukan dihapus: penjagaannya tetap berguna bila
            basis datanya kelak memakai collation yang membedakan huruf besar,
            dan menghapus tesnya berarti tidak ada lagi yang membuktikannya
            bekerja.
        */
        try {
            $this->makeProduct('flt-oli-std ');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->markTestSkipped('Collation basis data ini sudah mencegah SKU kembar di tingkat indeks.');
        }

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.rematch', $order))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'FLT-OLI-STD'))
            ->assertSessionHas('status');

        // Ditinggalkan kosong, bukan ditebak: tautan yang keliru kelak menjadi
        // dokumen yang menurunkan barang dari rak.
        $this->assertNull($order->items()->first()->product_id);
    }

    public function test_pressing_it_with_nothing_to_match_says_so_plainly(): void
    {
        $order = $this->importOrder('SPXID111', ['BELUM-ADA']);

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.rematch', $order))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'BELUM-ADA'))
            ->assertSessionMissing('success');
    }

    public function test_it_is_safe_to_press_twice(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD']);
        $product = $this->makeProduct('FLT-OLI-STD');

        $this->actingAs($this->admin)->post(route('admin.imports.orders.rematch', $order));
        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.rematch', $order))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'sudah menunjuk barang'));

        $this->assertSame($product->id, $order->items()->first()->product_id);
    }

    public function test_rematching_never_moves_stock(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD']);
        $product = $this->makeProduct('FLT-OLI-STD');
        $product->forceFill(['stock' => 12])->save();

        $this->actingAs($this->admin)->post(route('admin.imports.orders.rematch', $order));

        $this->assertSame(12, $product->refresh()->stock);
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    /* --------------------------------------------------- angka ringkasan - */

    public function test_the_unmatched_counter_on_the_import_batch_is_recomputed(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD', 'BELUM-ADA']);
        $import = ShipmentImport::firstOrFail();

        // Angka ini didenormalisasi dan dibaca kartu ringkasan serta halaman
        // riwayat berkas. Bila tidak ikut dihitung ulang, dua halaman lain
        // tetap melaporkan masalah yang sudah selesai.
        $this->assertSame(2, (int) $import->refresh()->unmatched_sku_count);

        $this->makeProduct('FLT-OLI-STD');

        $this->actingAs($this->admin)->post(route('admin.imports.orders.rematch', $order));

        $this->assertSame(1, (int) $import->refresh()->unmatched_sku_count);
    }

    /* --------------------------------------------------- hak akses ------- */

    public function test_the_button_needs_the_import_permission(): void
    {
        $order = $this->importOrder('SPXID111', ['FLT-OLI-STD']);
        $this->makeProduct('FLT-OLI-STD');

        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->post(route('admin.imports.orders.rematch', $order))
            ->assertForbidden();

        $this->assertNull($order->items()->first()->product_id);
    }

    public function test_the_page_offers_the_button_only_where_something_is_pending(): void
    {
        $this->importOrder('SPXID111', ['BELUM-ADA']);

        $this->actingAs($this->admin)->get(route('admin.imports.index'))
            ->assertOk()
            ->assertSee('Cocokkan Ulang Semua')
            ->assertSee('Sudah mendaftarkan barangnya?');

        // Resi kedua yang sudah cocok tidak menawarkan apa pun.
        \App\Models\ShipmentOrderItem::query()->update(['product_id' => $this->makeProduct('BELUM-ADA')->id]);
        ShipmentImport::query()->update(['unmatched_sku_count' => 0]);

        $this->actingAs($this->admin)->get(route('admin.imports.index'))
            ->assertOk()
            ->assertDontSee('Cocokkan Ulang');
    }

    /* --------------------------------------------------- pembantu -------- */

    protected function makeProduct(string $sku): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Barang '.trim($sku),
            'unit' => 'pcs',
        ]);
    }

    /**
     * @param  array<int, string>  $skus
     */
    protected function importOrder(string $tracking, array $skus): ShipmentOrder
    {
        $import = ShipmentImport::firstOrCreate(['filename' => 'ginee.xlsx'], ['source' => 'ginee']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
        ]);

        foreach ($skus as $sku) {
            // Persis seperti baris yang masuk sebelum barangnya didaftarkan.
            $order->items()->create([
                'sku' => $sku,
                'quantity' => 1,
                'product_id' => Product::whereRaw('UPPER(TRIM(sku)) = ?', [strtoupper(trim($sku))])->value('id'),
            ]);
        }

        $import->update([
            'unmatched_sku_count' => \App\Models\ShipmentOrderItem::whereNull('product_id')->count(),
        ]);

        return $order->load('items');
    }
}
