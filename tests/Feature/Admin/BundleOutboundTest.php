<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\StockApiSyncRecord;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Paket bundling pada alur barang keluar.
 *
 * Marketplace menjual paket sebagai satu SKU dan berkas Ginee menuliskannya
 * apa adanya, sedangkan yang ada di rak hanyalah barang isinya. Berkas tes
 * ini menjaga satu janji: sejak baris dokumen dibentuk, tidak ada satu pun
 * bagian sistem selain master barang yang perlu tahu bahwa paket itu ada.
 */
class BundleOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $oli;

    protected Product $filterOli;

    protected Product $filterUdara;

    protected Product $paket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->oli = $this->makeProduct('OLI-MTC-1L', '8991234500011', 'Oli Matic 1L');
        $this->filterOli = $this->makeProduct('FLT-OLI-STD', '8991234500035', 'Filter Oli Standar');
        $this->filterUdara = $this->makeProduct('FLT-UDR-STD', '8991234500042', 'Filter Udara Standar');

        // Paket tidak punya barcode: tidak ada wujud yang bisa ditempeli label.
        $this->paket = Product::create([
            'sku' => 'PKT-SERVIS-10K',
            'name' => 'Paket Servis 10.000 KM',
            'unit' => 'paket',
            'type' => Product::TYPE_BUNDLE,
        ]);

        foreach ([$this->oli, $this->filterOli, $this->filterUdara] as $component) {
            $this->paket->bundleComponents()->create([
                'component_id' => $component->id,
                'quantity' => 1,
            ]);
        }
    }

    /* ------------------------------------------------- import & matching -- */

    public function test_a_bundle_sku_in_the_ginee_export_is_matched_to_the_master(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'Nomor Pesanan', 'SKU', 'Nama Produk', 'Jumlah'],
            ['SPXID111', 'INV-1', 'PKT-SERVIS-10K', 'Paket Servis', '2'],
        ])->assertRedirect();

        $order = ShipmentOrder::with('items')->firstOrFail();

        // Pencocokan import murni bertumpu pada SKU, jadi paket ikut terbaca
        // tanpa importer perlu tahu apa pun soal bundling.
        $this->assertSame($this->paket->id, $order->items->first()->product_id);
        $this->assertTrue($order->isFullyMatched());
        $this->assertSame(0, (int) ShipmentImport::firstOrFail()->unmatched_sku_count);
    }

    /* ------------------------------------------------- pemecahan --------- */

    public function test_scanning_the_waybill_explodes_the_bundle_into_its_components(): void
    {
        $this->stockUp();
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 2]]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $outbound = Outbound::latest('id')->firstOrFail()->load('items.product', 'bundles');

        // Yang harus discan adalah barang isinya, masing-masing dua unit.
        $this->assertSame(
            ['FLT-OLI-STD' => 2, 'FLT-UDR-STD' => 2, 'OLI-MTC-1L' => 2],
            $outbound->items->mapWithKeys(fn ($item) => [$item->product->sku => $item->quantity])->sortKeys()->all(),
        );

        // Paketnya sendiri tidak pernah menjadi baris barang.
        $this->assertFalse($outbound->items->contains('product_id', $this->paket->id));

        // Tetapi asal-usulnya tersimpan, supaya dokumennya bisa menjelaskan diri.
        $this->assertSame(1, $outbound->bundles->count());
        $this->assertSame($this->paket->id, $outbound->bundles->first()->bundle_id);
        $this->assertSame(2, $outbound->bundles->first()->quantity);

        $response->assertOk()
            ->assertJsonPath('progress.total', 6)
            ->assertJsonPath('bundles.0.sku', 'PKT-SERVIS-10K')
            ->assertJsonPath('bundles.0.quantity', 2);
    }

    public function test_the_composition_is_snapshotted_so_a_later_recipe_change_can_not_rewrite_history(): void
    {
        $this->stockUp();
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 1]]);
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111']);

        $bundle = Outbound::latest('id')->firstOrFail()->bundles()->firstOrFail();

        // Resep berubah setelah dokumennya dibentuk.
        $this->paket->bundleComponents()->where('component_id', $this->filterUdara->id)->delete();

        $this->assertSame(
            ['FLT-OLI-STD', 'FLT-UDR-STD', 'OLI-MTC-1L'],
            collect($bundle->refresh()->composition)->pluck('sku')->sort()->values()->all(),
        );
    }

    public function test_a_component_ordered_loose_and_inside_a_bundle_becomes_one_line(): void
    {
        $this->stockUp();
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 2], ['OLI-MTC-1L', 1]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $outbound = Outbound::latest('id')->firstOrFail()->load('items');

        /*
            Satu baris saja untuk oli, berisi 2 dari paket + 1 satuan.

            Bukan soal kerapian: stasiun scan mencari barisnya dengan mengambil
            yang pertama cocok, jadi dua baris untuk barang yang sama membuat
            scan menolak unit ketiga dengan "sudah lengkap".
        */
        $lines = $outbound->items->where('product_id', $this->oli->id);

        $this->assertCount(1, $lines);
        $this->assertSame(3, $lines->first()->quantity);
        $this->assertSame(7, $outbound->totalQuantity());
    }

    public function test_a_bundle_ordered_twice_on_one_waybill_is_summed(): void
    {
        $this->stockUp();
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 1]]);

        // Baris kedua dengan SKU yang sama, seperti yang terjadi bila
        // pesanannya digabung dari dua item marketplace.
        ShipmentOrder::firstOrFail()->items()->create([
            'sku' => 'PKT-SERVIS-10K',
            'quantity' => 2,
            'product_id' => $this->paket->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $outbound = Outbound::latest('id')->firstOrFail()->load('items', 'bundles');

        $this->assertSame(1, $outbound->bundles->count());
        $this->assertSame(3, $outbound->bundles->first()->quantity);
        $this->assertSame(9, $outbound->totalQuantity());
    }

    /* ------------------------------------------------- scan -------------- */

    public function test_the_components_are_scanned_by_their_own_barcodes(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 1]]);

        foreach (['8991234500011', '8991234500035', '8991234500042'] as $barcode) {
            $this->scanItem($outbound, $barcode)->assertOk();
        }

        $this->assertTrue($outbound->refresh()->load('items')->isFullyScanned());
    }

    public function test_the_bundle_sku_itself_can_not_be_scanned(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 1]]);

        // Kalimat baku "tidak termasuk dalam pesanan ini" akan menyesatkan di
        // sini: paketnya memang dipesan, hanya sudah dipecah.
        $this->scanItem($outbound, 'PKT-SERVIS-10K')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'PKT-SERVIS-10K adalah paket · scan barang isinya satu per satu');
    }

    public function test_a_package_is_refused_before_it_is_opened_when_a_component_is_short(): void
    {
        // Oli hanya satu, sedangkan dua paket membutuhkan dua.
        $this->give($this->oli, 1);
        $this->give($this->filterOli, 10);
        $this->give($this->filterUdara, 10);
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 2]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            // Yang disebut komponennya, karena hanya itu yang bisa ditindaklanjuti.
            ->assertJsonPath('errors.code.0', 'Stok OLI-MTC-1L kurang · butuh 2, ada 1 pcs');

        $this->assertSame(0, Outbound::count());
    }

    public function test_rescanning_a_finished_package_does_not_wipe_its_progress(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 1]]);

        foreach (['8991234500011', '8991234500035', '8991234500042'] as $barcode) {
            $this->scanItem($outbound, $barcode)->assertOk();
        }

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422);

        $this->assertSame(3, $outbound->refresh()->items()->sum('scanned_quantity'));
    }

    /* ------------------------------------------------- halaman ----------- */

    public function test_the_document_pages_explain_where_the_items_came_from(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2]]);

        // Detail: paketnya disebut, berikut perhitungan menjadi barang.
        $this->actingAs($this->admin)->get(route('admin.outbounds.show', $outbound))
            ->assertOk()
            ->assertSee('Paket yang Dipesan')
            ->assertSee('PKT-SERVIS-10K')
            ->assertSee('&times;2', false);

        // Panel scan: operator memegang satu label paket, layar meminta enam
        // barang — hubungan keduanya harus terbaca.
        $this->actingAs($this->admin)->get(route('admin.outbounds.scan', $outbound))
            ->assertOk()
            ->assertSee('Isi paket yang dirakit')
            ->assertSee('PKT-SERVIS-10K');

        // Ubah dokumen: paket punya barisnya sendiri, jadi menyuntingnya tidak
        // berubah menjadi menyunting isinya satu per satu.
        $this->actingAs($this->admin)->get(route('admin.outbounds.edit', $outbound))
            ->assertOk()
            ->assertSee('Paket Bundling')
            ->assertSee('PKT-SERVIS-10K');
    }

    public function test_editing_a_document_keeps_its_packages_intact(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2], ['OLI-MTC-1L', 1]]);

        // Disimpan ulang persis seperti yang ditawarkan form: baris paket, dan
        // baris barang yang benar-benar dipesan lepas.
        $this->actingAs($this->admin)->put(route('admin.outbounds.update', $outbound), [
            'date' => $outbound->date->format('Y-m-d'),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => $outbound->recipient,
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID111',
            'bundles' => [['bundle_id' => $this->paket->id, 'quantity' => 2]],
            'items' => [['product_id' => $this->oli->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $outbound->refresh()->load('items', 'bundles');

        // Keterangan paketnya bertahan, dan barangnya tetap persis sama.
        $this->assertSame(1, $outbound->bundles->count());
        $this->assertSame(2, $outbound->bundles->first()->quantity);
        $this->assertSame(7, $outbound->totalQuantity());
        $this->assertSame(3, $outbound->items->firstWhere('product_id', $this->oli->id)->quantity);
    }

    public function test_the_form_offers_only_the_items_ordered_loose(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2], ['OLI-MTC-1L', 1]]);

        /*
            Baris barang pada form hanya berisi sisa setelah dikurangi apa yang
            dijanjikan paket. Tanpa pemisahan ini, form menampilkan tiga botol
            oli di samping baris paket yang juga menjanjikan dua — dan
            menyimpannya akan menghitung barangnya dua kali.
        */
        $loose = $outbound->looseItems();

        $this->assertCount(1, $loose);
        $this->assertSame($this->oli->id, $loose->first()->product_id);
        $this->assertSame(1, $loose->first()->quantity);
    }

    /* ------------------------------------------------- posting ----------- */

    public function test_posting_moves_component_stock_only(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2], ['OLI-MTC-1L', 1]]);

        foreach ([['8991234500011', 3], ['8991234500035', 2], ['8991234500042', 2]] as [$barcode, $quantity]) {
            $this->scanItem($outbound, $barcode, $quantity)->assertOk();
        }

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        $this->assertTrue($outbound->refresh()->isPosted());

        // 20 di rak, 3 oli keluar; filter masing-masing 2.
        $this->assertSame(17, $this->oli->refresh()->stock);
        $this->assertSame(18, $this->filterOli->refresh()->stock);
        $this->assertSame(18, $this->filterUdara->refresh()->stock);

        // Paket tidak punya saldo dan tidak pernah muncul di kartu stok.
        $this->assertSame(0, $this->paket->refresh()->stock);
        $this->assertSame(0, StockMovement::where('product_id', $this->paket->id)->count());
        $this->assertSame(3, StockMovement::where('type', 'out')->count());
    }

    public function test_a_bundle_order_travels_through_the_dispatch_queue(): void
    {
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2]]);

        foreach ([['8991234500011', 2], ['8991234500035', 2], ['8991234500042', 2]] as [$barcode, $quantity]) {
            $this->scanItem($outbound, $barcode, $quantity)->assertOk();
        }

        // Antrean Siap Dikirim menilai kelengkapan dari baris barang, yang
        // sudah berupa komponen — paketnya tidak menghalangi.
        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->assertSee($outbound->code);

        // Serah ke kurir: resi discan, dokumennya diproses.
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('shipped', true)
            ->assertJsonPath('outbound.units', 6);

        $this->assertTrue($outbound->refresh()->isPosted());
        $this->assertSame(18, $this->oli->refresh()->stock);
    }

    public function test_stock_can_never_be_moved_against_a_bundle(): void
    {
        /*
            Penjagaan terakhir, diuji langsung pada pintunya.

            Seluruh jalur di atas memang sudah memecah paket lebih dulu, tetapi
            justru itu sebabnya penjagaan ini ada: ia yang menjamin dokumen
            jenis baru — yang belum ditulis hari ini — tidak bisa menghitung
            satu barang dua kali.
        */
        $inbound = Inbound::create(['code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT]);
        $inbound->items()->create(['product_id' => $this->paket->id, 'quantity' => 5]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PKT-SERVIS-10K adalah paket bundling dan tidak punya stok sendiri.');

        app(StockService::class)->postInbound($inbound);
    }

    public function test_the_ledger_still_reconciles_after_a_full_bundle_cycle(): void
    {
        // Satu siklus penuh: barang masuk, paket dikirim, lalu diretur.
        $outbound = $this->openPackage([['PKT-SERVIS-10K', 2], ['OLI-MTC-1L', 1]]);

        foreach ([['8991234500011', 3], ['8991234500035', 2], ['8991234500042', 2]] as [$barcode, $quantity]) {
            $this->scanItem($outbound, $barcode, $quantity)->assertOk();
        }

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $return = \App\Models\ReturnReceipt::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return));

        /*
            Saldo tiap barang harus sama persis dengan jumlah mutasinya.

            Inilah pemeriksaan yang paling menentukan: paket dipecah di banyak
            tempat — resolver, form, stasiun packing, retur — dan bila salah
            satu di antaranya menghitung dua kali, selisihnya muncul di sini
            dan tidak di tempat lain mana pun.
        */
        foreach (Product::all() as $product) {
            $ledger = StockMovement::where('product_id', $product->id)
                ->where('bucket', StockMovement::BUCKET_GOOD)
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END), 0) as saldo")
                ->value('saldo');

            $this->assertSame(
                (int) $ledger,
                (int) $product->stock,
                "Saldo {$product->sku} tidak cocok dengan kartu stoknya.",
            );
        }

        // Paket tidak pernah menyentuh buku besar, dan tidak pernah menjadi
        // baris dokumen mana pun.
        $this->assertSame(0, StockMovement::where('product_id', $this->paket->id)->count());
        $this->assertDatabaseMissing('outbound_items', ['product_id' => $this->paket->id]);
        $this->assertDatabaseMissing('return_receipt_items', ['product_id' => $this->paket->id]);
        $this->assertSame(0, $this->paket->refresh()->stock);
    }

    /* ------------------------------------------------- ketersediaan ------ */

    public function test_availability_is_the_smallest_number_of_complete_sets(): void
    {
        $this->give($this->oli, 10);
        $this->give($this->filterOli, 4);
        $this->give($this->filterUdara, 7);

        $this->assertSame(4, $this->paket->refresh()->bundleAvailability());

        $this->assertSame(4, (int) Product::withBundleAvailability()
            ->whereKey($this->paket->id)
            ->firstOrFail()
            ->bundle_availability);
    }

    public function test_a_bundle_that_needs_two_of_one_component_halves_its_availability(): void
    {
        $this->paket->bundleComponents()->where('component_id', $this->oli->id)->update(['quantity' => 2]);

        $this->give($this->oli, 7);
        $this->give($this->filterOli, 10);
        $this->give($this->filterUdara, 10);

        // Tujuh botol hanya cukup untuk tiga paket, sisanya tidak lengkap.
        $this->assertSame(3, $this->paket->refresh()->bundleAvailability());
    }

    public function test_an_inactive_or_missing_component_makes_the_bundle_unavailable(): void
    {
        $this->stockUp();

        $this->assertSame(20, $this->paket->refresh()->bundleAvailability());

        $this->filterOli->update(['is_active' => false]);

        $this->assertSame(0, $this->paket->refresh()->bundleAvailability());
        $this->assertSame(0, (int) Product::withBundleAvailability()
            ->whereKey($this->paket->id)
            ->firstOrFail()
            ->bundle_availability);
    }

    public function test_a_bundle_without_a_recipe_is_never_available(): void
    {
        $this->paket->bundleComponents()->delete();

        $this->assertSame(0, $this->paket->refresh()->bundleAvailability());
    }

    /* ------------------------------------------------- penjagaan lain ---- */

    public function test_a_bundle_is_not_reported_to_the_central_stock_api(): void
    {
        $this->stockUp();

        // Komponennya dilaporkan seperti biasa, paketnya sama sekali tidak —
        // pusat melihat kumpulan SKU yang persis sama seperti sebelum fitur ini.
        $this->assertTrue(StockApiSyncRecord::where('sku', 'OLI-MTC-1L')->exists());
        $this->assertFalse(StockApiSyncRecord::where('sku', 'PKT-SERVIS-10K')->exists());
    }

    public function test_a_bundle_can_not_be_received_counted_or_adjusted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.inbounds.store'), [
                'date' => now()->toDateString(),
                'items' => [['product_id' => $this->paket->id, 'quantity' => 5]],
            ])
            ->assertSessionHasErrors(['items.0.product_id']);

        $this->actingAs($this->admin)
            ->post(route('admin.adjustments.store'), [
                'date' => now()->toDateString(),
                'reason' => \App\Models\StockAdjustment::reasons()[0],
                'items' => [['product_id' => $this->paket->id, 'actual_quantity' => 5]],
            ])
            ->assertSessionHasErrors(['items.0.product_id']);

        // Opname memotret cakupannya sendiri, jadi paketnya disaring di sana.
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => 'all',
        ]);

        $opname = \App\Models\StockOpname::latest('id')->firstOrFail();

        $this->assertFalse($opname->items()->where('product_id', $this->paket->id)->exists());
        $this->assertSame(3, $opname->items()->count());
    }

    public function test_a_component_still_used_by_a_recipe_can_not_be_deleted(): void
    {
        $this->assertContains('isi paket bundling', $this->oli->blockingDocuments());

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $this->oli))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $this->oli->id]);
    }

    /* ------------------------------------------------- retur ------------- */

    public function test_a_returned_bundle_restocks_its_components(): void
    {
        $this->stockUp();
        $this->importOrder('SPXID111', [['PKT-SERVIS-10K', 1]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        $return = \App\Models\ReturnReceipt::latest('id')->firstOrFail()->load('items');

        // Kondisinya dinilai per barang: paket yang isinya sebagian penyok
        // memang tidak bisa digambarkan sebagai satu baris.
        $this->assertSame(3, $return->items->count());
        $this->assertFalse($return->items->contains('product_id', $this->paket->id));
        $this->assertSame(
            ['FLT-OLI-STD', 'FLT-UDR-STD', 'OLI-MTC-1L'],
            $return->items->map(fn ($item) => $item->product->sku)->sort()->values()->all(),
        );
    }

    /* ------------------------------------------------- pembantu ---------- */

    protected function makeProduct(string $sku, string $barcode, string $name): Product
    {
        return Product::create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'unit' => 'pcs',
            'min_stock' => 5,
        ]);
    }

    /**
     * Buka paket di stasiun packing dan kembalikan dokumennya.
     *
     * @param  array<int, array{0: string, 1: int}>  $items
     */
    protected function openPackage(array $items): Outbound
    {
        $this->stockUp();
        $this->importOrder('SPXID111', $items);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();

        return Outbound::latest('id')->firstOrFail();
    }

    protected function scanItem(Outbound $outbound, string $code, int $quantity = 1)
    {
        return $this->actingAs($this->admin)->postJson(
            route('admin.outbounds.scan.item', $outbound),
            ['code' => $code, 'quantity' => $quantity],
        );
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $items
     */
    protected function importOrder(string $tracking, array $items): ShipmentOrder
    {
        $import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'buyer_name' => 'Andi Pratama',
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

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function uploadCsv(array $rows)
    {
        $csv = collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n");

        $path = tempnam(sys_get_temp_dir(), 'ginee').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'ginee-orders.csv', 'text/csv', null, true),
        ]);
    }

    protected function stockUp(int $quantity = 20): void
    {
        foreach ([$this->oli, $this->filterOli, $this->filterUdara] as $product) {
            $this->give($product, $quantity);
        }
    }

    protected function give(Product $product, int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
