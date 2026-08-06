<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stasiun packing: scan resi, scan barang, paket masuk antrean siap kirim,
 * lalu layar langsung siap untuk resi berikutnya — semuanya tanpa berpindah
 * halaman dan tanpa satu pun klik di antara paket.
 */
class MarketplaceIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 5,
        ]);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_station_page_has_no_document_form(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'));

        $response->assertOk()
            ->assertSee('Stasiun Packing')
            // Rail langkah selalu terlihat supaya alurnya jelas.
            ->assertSee('Barang')
            ->assertSee('Selesai')
            ->assertSee('Belum ada paket aktif')
            // Tidak ada satu pun field dokumen di halaman ini.
            ->assertDontSee('name="recipient"', false)
            ->assertDontSee('name="marketplace"', false)
            ->assertDontSee('name="items[0][product_id]"', false);
    }

    public function test_the_station_offers_continuing_to_the_next_waybill(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'))
            ->assertOk()
            ->assertSee('Lanjut otomatis ke resi berikutnya')
            ->assertSee('unit discan')
            // Pengiriman dikerjakan di antrean, bukan di stasiun ini.
            ->assertSee('Siap Dikirim')
            ->assertDontSee('Proses &amp; Kirim', false);
    }

    /* --------------------------------------------------- scan resi ------- */

    public function test_scanning_a_waybill_returns_the_items_and_next_endpoint(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 3]], buyer: 'Andi Pratama');

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::latest('id')->firstOrFail();

        $response->assertOk()
            ->assertJsonPath('outbound.code', $outbound->code)
            ->assertJsonPath('outbound.recipient', 'Andi Pratama')
            ->assertJsonPath('items.0.sku', 'FLT-OLI-STD')
            ->assertJsonPath('items.0.barcode', '8991234500035')
            ->assertJsonPath('items.0.quantity', 3)
            ->assertJsonPath('progress.total', 3)
            ->assertJsonPath('progress.resi_verified', true)
            // Klien tidak perlu menyusun URL sendiri.
            ->assertJsonPath('urls.item', route('admin.outbounds.scan.item', $outbound))
            // Stasiun tidak lagi memproses pengiriman.
            ->assertJsonMissingPath('urls.finish');

        $this->assertTrue($outbound->isResiVerified());
    }

    public function test_the_item_list_carries_the_available_stock(): void
    {
        $this->giveStock(9);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 3]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('items.0.stock', 9)
            ->assertJsonPath('items.0.unit', 'pcs')
            ->assertJsonPath('items.0.quantity', 3);
    }

    public function test_an_unknown_waybill_is_rejected_with_guidance(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'TIDAKADA'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Resi tidak ditemukan di data import. Import dulu berkas pesanan dari Ginee, atau buat dokumen manual.');

        $this->assertDatabaseCount('outbounds', 0);
    }

    public function test_unmatched_skus_stop_the_station(): void
    {
        $this->importOrder('SPXID222', [['SKU-ASING', 2]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID222'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'SKU berikut belum terdaftar di master barang: SKU-ASING. Tambahkan barangnya terlebih dahulu.');

        $this->assertDatabaseCount('outbounds', 0);
    }

    /* --------------------------------------------------- stok kosong ----- */

    /**
     * Barang tanpa stok tercatat tidak boleh keluar. Paketnya ditolak sebelum
     * dibuka, jadi tidak ada paket setengah discan yang tersangkut.
     */
    public function test_a_waybill_is_refused_when_the_stock_is_empty(): void
    {
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 1]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                'Stok Filter Oli Standar (SKU FLT-OLI-STD) kosong. Catat barang masuknya dulu sebelum barang ini bisa discan.',
            );

        $this->assertDatabaseCount('outbounds', 0);
    }

    public function test_a_waybill_is_refused_when_the_stock_is_not_enough(): void
    {
        $this->giveStock(2);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 5]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                'Stok Filter Oli Standar (SKU FLT-OLI-STD) tidak mencukupi: butuh 5, tersedia 2 pcs.',
            );

        $this->assertDatabaseCount('outbounds', 0);
    }

    /**
     * Stok bisa habis oleh paket lain setelah resi ini dibuka, jadi scan
     * barangnya diperiksa ulang.
     */
    public function test_an_item_can_not_be_scanned_once_its_stock_is_gone(): void
    {
        $this->giveStock(3);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 1]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $outbound = Outbound::latest('id')->firstOrFail();

        // Paket lain menghabiskan stoknya di tengah proses.
        $this->product->forceFill(['stock' => 0])->save();

        $this->scanItem($outbound, 'FLT-OLI-STD')
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                'Stok Filter Oli Standar (SKU FLT-OLI-STD) kosong. Catat barang masuknya dulu sebelum barang ini bisa discan.',
            );

        $this->assertSame(0, $outbound->refresh()->totalScanned());
    }

    /* --------------------------------------------------- alur berantai --- */

    /**
     * Paket yang lengkap discan berhenti sebagai draft yang siap dikirim —
     * stok belum bergerak, karena pengirimannya diputuskan di antrean.
     */
    public function test_a_fully_scanned_package_waits_in_the_ready_queue(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::latest('id')->firstOrFail();

        $this->scanItem($outbound, 'FLT-OLI-STD')->assertOk();

        // Unit terakhir menutup paket tanpa klik apa pun.
        $this->scanItem($outbound, '8991234500035')
            ->assertOk()
            ->assertJsonPath('progress.ready', true);

        $outbound->refresh();

        $this->assertTrue($outbound->isDraft());
        $this->assertSame(10, $this->product->refresh()->stock);
        $this->assertTrue(Outbound::readyToShip()->whereKey($outbound->id)->exists());
    }

    public function test_two_waybills_can_be_scanned_back_to_back(): void
    {
        $this->giveStock(20);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);
        $this->importOrder('SPXID222', [['FLT-OLI-STD', 3]]);

        foreach ([['SPXID111', 2], ['SPXID222', 3]] as [$tracking, $quantity]) {
            $this->actingAs($this->admin)
                ->postJson(route('admin.outbounds.marketplace.store'), ['code' => $tracking])
                ->assertOk();

            $outbound = Outbound::where('tracking_number', $tracking)->firstOrFail();

            for ($i = 0; $i < $quantity; $i++) {
                $this->scanItem($outbound, 'FLT-OLI-STD')->assertOk();
            }
        }

        // Keduanya menunggu di antrean, stok belum tersentuh.
        $this->assertSame(2, Outbound::readyToShip()->count());
        $this->assertSame(20, $this->product->refresh()->stock);
    }

    /**
     * Paket yang sudah lengkap discan pindah ke antrean, jadi tidak lagi
     * muncul sebagai pekerjaan yang tertunda di stasiun.
     */
    public function test_the_station_only_lists_packages_still_being_scanned(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);
        $this->importOrder('SPXID222', [['FLT-OLI-STD', 1]]);

        // Paket pertama tuntas discan.
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);
        $done = Outbound::where('tracking_number', 'SPXID111')->firstOrFail();
        $this->scanItem($done, 'FLT-OLI-STD');
        $this->scanItem($done, 'FLT-OLI-STD');

        // Paket kedua baru dibuka.
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID222']);
        $open = Outbound::where('tracking_number', 'SPXID222')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'))
            ->assertOk()
            ->assertSee($open->tracking_number)
            ->assertDontSee($done->tracking_number);
    }

    /**
     * Paket yang scannya sudah tuntas tidak boleh dibuka ulang.
     *
     * Dokumennya masih draft karena menunggu diproses di antrean, dan tanpa
     * penjagaan ini scan resi kedua menghapus seluruh hasil QC lalu memulai
     * dari nol — tanpa pemberitahuan apa pun.
     */
    public function test_a_finished_package_is_not_reopened_by_scanning_its_waybill_again(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::latest('id')->firstOrFail();
        $this->scanItem($outbound, 'FLT-OLI-STD');
        $this->scanItem($outbound, 'FLT-OLI-STD');

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                "Resi ini sudah selesai discan pada {$outbound->code} dan menunggu diproses di antrean Siap Dikirim.",
            );

        // Hasil scannya utuh, bukan direset diam-diam.
        $outbound->refresh()->load('items');

        $this->assertSame(2, $outbound->totalScanned());
        $this->assertSame(1, Outbound::where('tracking_number', 'SPXID111')->count());
        $this->assertTrue(Outbound::readyToShip()->whereKey($outbound->id)->exists());
    }

    /**
     * Paket yang scannya belum tuntas tetap boleh dilanjutkan — itu justru
     * cara melanjutkan pekerjaan yang tertunda.
     */
    public function test_a_half_scanned_package_can_still_be_resumed(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 3]]);

        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::latest('id')->firstOrFail();
        $this->scanItem($outbound, 'FLT-OLI-STD');

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $this->assertSame(1, Outbound::where('tracking_number', 'SPXID111')->count());
    }

    public function test_scanning_the_same_waybill_twice_reuses_the_draft(): void
    {
        $this->giveStock(5);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $this->assertSame(1, Outbound::where('type', Outbound::TYPE_MARKETPLACE)->count());
        $this->assertSame(2, Outbound::where('tracking_number', 'SPXID111')->firstOrFail()->items->sum('quantity'));
    }

    public function test_an_already_shipped_waybill_is_refused(): void
    {
        $this->giveStock(10);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 1]]);

        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::where('tracking_number', 'SPXID111')->firstOrFail();
        $this->scanItem($outbound, 'FLT-OLI-STD');

        // Diproses lewat antrean siap kirim.
        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', "Resi ini sudah diproses pada dokumen {$outbound->code}.");
    }

    public function test_the_standalone_scan_page_still_works_for_a_saved_draft(): void
    {
        $this->giveStock(5);
        $this->importOrder('SPXID111', [['FLT-OLI-STD', 2]]);
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::where('tracking_number', 'SPXID111')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.outbounds.scan', $outbound))
            ->assertOk()
            ->assertSee('Barang yang Harus Discan')
            ->assertSee('FLT-OLI-STD');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function scanItem(Outbound $outbound, string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => $code]);
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $items
     */
    protected function importOrder(string $tracking, array $items, string $buyer = 'Pembeli'): ShipmentOrder
    {
        $import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'buyer_name' => $buyer,
        ]);

        foreach ($items as [$sku, $quantity]) {
            $order->items()->create([
                'sku' => $sku,
                'quantity' => $quantity,
                'product_id' => Product::where('sku', $sku)->value('id'),
            ]);
        }

        return $order->load('items.product');
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = \App\Models\Inbound::create([
            'code' => \App\Models\Inbound::nextCode(),
            'date' => now(),
            'status' => \App\Models\Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
