<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrderItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Paket bundling di master barang, laporan, dan import.
 *
 * Paket tidak punya saldo. Berkas tes ini menjaga akibat-akibat dari kalimat
 * itu di luar alur barang keluar: ia tidak boleh terhitung sebagai stok habis,
 * tidak boleh mendesak pemesanan, dan tidak boleh menggagalkan import hanya
 * karena ada angka stok di barisnya.
 */
class BundleMasterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $oli;

    protected Product $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->oli = Product::create(['sku' => 'OLI-MTC-1L', 'name' => 'Oli Matic 1L', 'unit' => 'pcs', 'min_stock' => 5]);
        $this->filter = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli', 'unit' => 'pcs', 'min_stock' => 5]);
    }

    /* ------------------------------------------------- menyusun paket ---- */

    public function test_a_bundle_is_created_with_its_recipe(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'pkt-servis-10k',
            'name' => 'Paket Servis 10.000 KM',
            'unit' => 'paket',
            'min_stock' => 0,
            'is_active' => 1,
            'type' => 'bundle',
            'components' => [
                ['component_id' => $this->oli->id, 'quantity' => 2],
                ['component_id' => $this->filter->id, 'quantity' => 1],
            ],
        ])->assertRedirect();

        $bundle = Product::where('sku', 'PKT-SERVIS-10K')->firstOrFail();

        $this->assertTrue($bundle->isBundle());
        $this->assertSame(0, $bundle->stock);
        $this->assertSame(
            [$this->oli->id => 2, $this->filter->id => 1],
            $bundle->bundleComponents->mapWithKeys(fn ($item) => [$item->component_id => $item->quantity])->sortKeys()->all(),
        );
    }

    public function test_a_bundle_needs_at_least_one_component(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'PKT-KOSONG',
            'name' => 'Paket Kosong',
            'unit' => 'paket',
            'min_stock' => 0,
            'type' => 'bundle',
            'components' => [],
        ])->assertSessionHasErrors('components');

        $this->assertSame(0, Product::bundles()->count());
    }

    public function test_a_bundle_can_not_contain_another_bundle(): void
    {
        $inner = $this->makeBundle('PKT-DALAM', [[$this->oli, 1]]);

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'PKT-LUAR',
            'name' => 'Paket Luar',
            'unit' => 'paket',
            'min_stock' => 0,
            'type' => 'bundle',
            'components' => [['component_id' => $inner->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('components.0.component_id');
    }

    public function test_a_bundle_can_not_contain_itself(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $bundle), [
            'sku' => 'PKT-SERVIS',
            'name' => 'Paket Servis',
            'unit' => 'paket',
            'min_stock' => 0,
            'type' => 'bundle',
            'components' => [['component_id' => $bundle->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('components.0.component_id');
    }

    public function test_the_same_component_can_not_be_listed_twice(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'PKT-DOBEL',
            'name' => 'Paket Dobel',
            'unit' => 'paket',
            'min_stock' => 0,
            'type' => 'bundle',
            'components' => [
                ['component_id' => $this->oli->id, 'quantity' => 1],
                ['component_id' => $this->oli->id, 'quantity' => 2],
            ],
        ])->assertSessionHasErrors('components.0.component_id');
    }

    public function test_turning_a_bundle_back_into_a_product_clears_its_recipe(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1], [$this->filter, 1]]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $bundle), [
            'sku' => 'PKT-SERVIS',
            'name' => 'Bukan Paket Lagi',
            'unit' => 'pcs',
            'min_stock' => 0,
            'type' => 'single',
        ])->assertRedirect();

        // Resep lama harus ikut pergi, bukan menunggu diam-diam untuk hidup
        // kembali bila jenisnya diubah lagi.
        $this->assertFalse($bundle->refresh()->isBundle());
        $this->assertSame(0, $bundle->bundleComponents()->count());
    }

    /* ------------------------------------------------- halaman ---------- */

    public function test_the_bundle_pages_render(): void
    {
        $this->give($this->oli, 9);
        $this->give($this->filter, 40);
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 2], [$this->filter, 1]]);

        $this->actingAs($this->admin)->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('Paket Bundling')
            ->assertSee('Isi Paket');

        $this->actingAs($this->admin)->get(route('admin.products.edit', $bundle))
            ->assertOk()
            ->assertSee('Isi Paket')
            ->assertSee('OLI-MTC-1L');

        // Detail paket menggantikan kartu stok, dan menyebut barang mana yang
        // membatasi: min(⌊9/2⌋, ⌊40/1⌋) = 4.
        $this->actingAs($this->admin)->get(route('admin.products.show', $bundle))
            ->assertOk()
            ->assertSee('Isi Paket')
            ->assertSee('Masih bisa dijanjikan')
            ->assertSee('Dijanjikan')
            ->assertSee('4 paket')
            ->assertDontSee('Kartu Stok');

        // Sebaliknya: dari barang biasa, paket yang memakainya ikut terlihat.
        $this->actingAs($this->admin)->get(route('admin.products.show', $this->oli))
            ->assertOk()
            ->assertSee('Kartu Stok')
            ->assertSee('Dipakai di Paket')
            ->assertSee('PKT-SERVIS');
    }

    public function test_a_product_that_can_not_become_a_bundle_is_told_so_on_the_form(): void
    {
        $this->give($this->oli, 10);

        $this->actingAs($this->admin)->get(route('admin.products.edit', $this->oli))
            ->assertOk()
            ->assertSee('tidak bisa dijadikan paket')
            // Pilihan jenisnya tidak ditawarkan sama sekali, bukan ditawarkan
            // lalu ditolak setelah formulirnya dikirim.
            ->assertDontSee('Paket Bundling');
    }

    /* ------------------------------------------------- penjagaan konversi */

    public function test_a_product_with_stock_can_not_become_a_bundle(): void
    {
        $this->give($this->oli, 10);

        $this->actingAs($this->admin)->put(route('admin.products.update', $this->oli), [
            'sku' => 'OLI-MTC-1L',
            'name' => 'Oli Matic 1L',
            'unit' => 'pcs',
            'min_stock' => 5,
            'type' => 'bundle',
            'components' => [['component_id' => $this->filter->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('type');

        $this->assertFalse($this->oli->refresh()->isBundle());
        $this->assertSame(10, $this->oli->stock);
    }

    public function test_a_product_with_movement_history_can_not_become_a_bundle(): void
    {
        // Stoknya kosong lagi, tetapi kartu stoknya sudah terisi. Membiarkannya
        // menjadi paket akan meninggalkan kartu yang tidak punya saldo apa pun
        // untuk menjelaskannya.
        $this->give($this->oli, 4);
        $this->takeOut($this->oli, 4);

        $this->assertSame(0, $this->oli->refresh()->stock);

        $this->actingAs($this->admin)->put(route('admin.products.update', $this->oli), [
            'sku' => 'OLI-MTC-1L',
            'name' => 'Oli Matic 1L',
            'unit' => 'pcs',
            'min_stock' => 5,
            'type' => 'bundle',
            'components' => [['component_id' => $this->filter->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('type');

        $this->assertFalse($this->oli->refresh()->isBundle());
    }

    public function test_an_untouched_product_may_still_become_a_bundle(): void
    {
        $this->actingAs($this->admin)->put(route('admin.products.update', $this->oli), [
            'sku' => 'OLI-MTC-1L',
            'name' => 'Paket Oli',
            'unit' => 'paket',
            'min_stock' => 0,
            'type' => 'bundle',
            'components' => [['component_id' => $this->filter->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->oli->refresh()->isBundle());
    }

    /* ------------------------------------------------- daftar & laporan -- */

    public function test_a_bundle_never_counts_as_thin_or_empty_stock(): void
    {
        $this->give($this->oli, 50);
        $this->give($this->filter, 50);
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 1], [$this->filter, 1]]);

        // Stok paket nol dan batas minimumnya nol, jadi 0 <= 0 akan membuatnya
        // terbaca menipis selamanya bila tidak disaring.
        $this->assertSame(0, Product::lowStock()->count());

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['low_stock'] === 0 && $stats['products'] === 2);
    }

    public function test_a_bundle_is_never_suggested_for_restock(): void
    {
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $this->actingAs($this->admin)->get(route('admin.reports.restock'))
            ->assertOk()
            ->assertDontSee('PKT-SERVIS');
    }

    public function test_a_bundle_is_left_out_of_the_stock_report(): void
    {
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $this->actingAs($this->admin)->get(route('admin.reports.stock'))
            ->assertOk()
            ->assertDontSee('PKT-SERVIS');
    }

    public function test_the_product_list_shows_a_bundle_by_its_availability(): void
    {
        $this->give($this->oli, 9);
        $this->give($this->filter, 40);
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 2], [$this->filter, 1]]);

        $response = $this->actingAs($this->admin)->get(route('admin.products.index'));

        $response->assertOk()
            ->assertSee('PKT-SERVIS')
            // Paket punya kartu ringkasannya sendiri, supaya kartu dan tabel
            // tidak bercerita beda.
            ->assertSee('Paket Bundling')
            // min(⌊9/2⌋, ⌊40/1⌋) = 4, bukan stok kolomnya yang nol.
            ->assertSee('bisa dijanjikan');

        $response->assertViewHas('summary', fn (array $summary) => $summary['total'] === 2
            && $summary['bundles'] === 1
            && $summary['out'] === 0);
    }

    public function test_the_stock_filter_never_returns_bundles(): void
    {
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        // Stok paket nol, tetapi "Habis" adalah pertanyaan tentang rak.
        $this->actingAs($this->admin)->get(route('admin.products.index', ['stock' => 'out']))
            ->assertOk()
            ->assertDontSee('PKT-SERVIS');

        $this->actingAs($this->admin)->get(route('admin.products.index', ['type' => 'bundle']))
            ->assertOk()
            ->assertSee('PKT-SERVIS')
            ->assertDontSee('OLI-MTC-1L');
    }

    public function test_bulk_min_stock_skips_bundles(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $this->actingAs($this->admin)->patch(route('admin.products.bulk.min-stock'), [
            'min_stock' => 7,
            'scope' => 'selected',
            'ids' => [$this->oli->id, $bundle->id],
        ])->assertRedirect();

        $this->assertSame(7, $this->oli->refresh()->min_stock);
        $this->assertSame(0, $bundle->refresh()->min_stock);
    }

    /* ------------------------------------------------- import & export --- */

    public function test_a_stock_value_on_a_bundle_row_is_skipped_instead_of_failing_the_file(): void
    {
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $response = $this->uploadCsv([
            ['SKU', 'Nama Barang', 'Satuan', 'Stok'],
            ['OLI-MTC-1L', 'Oli Matic 1L', 'pcs', '25'],
            ['PKT-SERVIS', 'Paket Servis', 'paket', '99'],
            ['FLT-OLI-STD', 'Filter Oli', 'pcs', '10'],
        ]);

        $response->assertRedirect(route('admin.products.index'));

        // Yang penting: baris lain tetap terproses. Sebelum penjagaan ini,
        // satu angka pada baris paket menggagalkan seluruh berkas.
        $this->assertSame(25, $this->oli->refresh()->stock);
        $this->assertSame(10, $this->filter->refresh()->stock);
        $this->assertSame(0, Product::where('sku', 'PKT-SERVIS')->value('stock'));

        $import = ProductImport::latest('id')->firstOrFail();
        $this->assertSame(1, $import->bundle_skipped_count);

        $response->assertSessionHas('success', fn (string $message) => str_contains($message, '1 baris paket bundling diabaikan'));
    }

    public function test_the_export_marks_bundles_and_reports_their_availability(): void
    {
        $this->give($this->oli, 6);
        $this->makeBundle('PKT-SERVIS', [[$this->oli, 2]]);

        $rows = $this->readExport();

        $header = $rows[3];
        $this->assertSame('Stok', $header[6]);
        $this->assertSame('Jenis', $header[10]);

        $bundleRow = collect($rows)->slice(4)->firstWhere(0, 'PKT-SERVIS');

        $this->assertSame('Paket', $bundleRow[10]);
        // Kolom stok berisi ketersediaan turunannya, bukan nol yang menyesatkan.
        $this->assertSame('3', (string) $bundleRow[6]);
    }

    public function test_an_import_never_puts_a_barcode_or_a_rack_on_a_bundle(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        /*
            Berkas hasil export menulis '-' pada kolom yang kosong, jadi
            mengimportnya balik memang mengirimkan nilai untuk kolom-kolom ini.
            Satu barcode yang menempel pada paket membuat scan di stasiun
            packing menunjuk baris yang tidak pernah ada di dokumen.
        */
        $this->uploadCsv([
            ['SKU', 'Nama Barang', 'Satuan', 'Barcode', 'Lokasi Rak', 'Stok Minimum'],
            ['PKT-SERVIS', 'Paket Servis', 'paket', '8991234599999', 'A-01-01', '5'],
        ])->assertRedirect();

        $bundle->refresh();

        $this->assertNull($bundle->barcode);
        $this->assertNull($bundle->location);
        $this->assertSame(0, $bundle->min_stock);
        // Kolom yang memang berlaku bagi paket tetap diperbarui.
        $this->assertSame('Paket Servis', $bundle->name);
    }

    public function test_a_bundle_still_listed_on_a_document_can_not_be_deleted(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);
        $this->give($this->oli, 10);

        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(),
            'date' => now(),
            'recipient' => 'Pelanggan',
            'status' => \App\Models\Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->oli->id, 'quantity' => 1]);
        $outbound->bundles()->create([
            'bundle_id' => $bundle->id,
            'quantity' => 1,
            'composition' => [['product_id' => $this->oli->id, 'sku' => 'OLI-MTC-1L', 'name' => 'Oli', 'quantity' => 1]],
        ]);

        // Kunci asingnya RESTRICT. Tanpa pemeriksaan yang bisa dibaca, ini
        // berakhir sebagai galat basis data alih-alih kalimat yang berguna.
        $this->assertContains('barang keluar (sebagai paket)', $bundle->blockingDocuments());

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $bundle))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $bundle->id]);
    }

    public function test_a_dash_from_our_own_export_is_read_as_empty(): void
    {
        /*
            Berkas ekspor menulis "-" pada kolom yang memang kosong. Importer
            dulu membacanya sebagai nilai sungguhan, jadi alur paling lazim —
            unduh, sunting beberapa baris, unggah lagi — mengubah setiap barang
            tanpa kategori menjadi berkategori "-", dan memberi barcode "-"
            kepada barang yang tidak punya barcode.
        */
        $this->uploadCsv([
            ['SKU', 'Nama Barang', 'Satuan', 'Barcode', 'Kategori', 'Lokasi Rak'],
            ['OLI-MTC-1L', 'Oli Matic 1L', 'pcs', '-', '-', '-'],
            ['FLT-OLI-STD', 'Filter Oli', 'pcs', '-', '-', '-'],
        ])->assertRedirect(route('admin.products.index'));

        foreach ([$this->oli, $this->filter] as $product) {
            $product->refresh();

            $this->assertNull($product->barcode, "{$product->sku} mendapat barcode dari tanda hubung.");
            $this->assertNull($product->category);
            $this->assertNull($product->location);
        }
    }

    public function test_the_placeholder_repair_command_reports_before_it_writes(): void
    {
        // Persis bentuk yang ditinggalkan import lama.
        $this->oli->forceFill(['category' => '-', 'location' => '-'])->save();
        $this->filter->forceFill(['barcode' => '—'])->save();

        $this->artisan('products:clear-placeholders')
            ->expectsOutputToContain('OLI-MTC-1L')
            ->expectsOutputToContain('Belum ada yang diubah')
            ->assertSuccessful();

        $this->assertSame('-', $this->oli->refresh()->category);

        $this->artisan('products:clear-placeholders', ['--apply' => true])->assertSuccessful();

        $this->assertNull($this->oli->refresh()->category);
        $this->assertNull($this->oli->location);
        $this->assertNull($this->filter->refresh()->barcode);
    }

    public function test_the_repair_command_leaves_real_values_alone(): void
    {
        // Tanda hubung sebagai bagian dari kalimat jelas ditulis orang.
        $this->oli->forceFill(['category' => 'Filter - Oli'])->save();

        $this->artisan('products:clear-placeholders', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Filter - Oli', $this->oli->refresh()->category);
    }

    /* ------------------------------------------------- ketersediaan ------ */

    public function test_availability_subtracts_what_is_already_promised(): void
    {
        $this->give($this->oli, 10);
        $this->give($this->filter, 10);
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 2], [$this->filter, 1]]);

        $this->assertSame(5, $bundle->refresh()->bundleAvailability());

        // Empat botol oli dijanjikan lewat dokumen yang belum diproses.
        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(),
            'date' => now(),
            'recipient' => 'Pelanggan',
            'status' => \App\Models\Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->oli->id, 'quantity' => 4]);

        // Sisa 6 botol → cukup untuk 3 paket, bukan 5.
        $this->assertSame(3, $bundle->refresh()->bundleAvailability());

        $this->assertSame(3, (int) Product::withBundleAvailability()
            ->whereKey($bundle->id)
            ->firstOrFail()
            ->bundle_availability);
    }

    public function test_a_deactivated_bundle_is_not_available(): void
    {
        $this->give($this->oli, 10);
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);

        $this->assertSame(10, $bundle->refresh()->bundleAvailability());

        // Aturannya sama dengan komponen yang dinonaktifkan, supaya keduanya
        // tidak berbeda pendapat.
        $bundle->update(['is_active' => false]);

        $this->assertSame(0, $bundle->refresh()->bundleAvailability());
    }

    /* ------------------------------------------------- backfill ---------- */

    public function test_the_backfill_command_reports_before_it_writes(): void
    {
        $bundle = $this->makeBundle('PKT-SERVIS', [[$this->oli, 1]]);
        $item = $this->orphanOrderItem('PKT-SERVIS');

        $this->artisan('bundles:match-skus')
            ->expectsOutputToContain('PKT-SERVIS')
            ->expectsOutputToContain('Belum ada yang diubah')
            ->assertSuccessful();

        $this->assertNull($item->refresh()->product_id);

        $this->artisan('bundles:match-skus', ['--apply' => true])->assertSuccessful();

        $this->assertSame($bundle->id, $item->refresh()->product_id);
    }

    public function test_the_backfill_command_leaves_plain_products_alone_by_default(): void
    {
        $item = $this->orphanOrderItem('OLI-MTC-1L');

        $this->artisan('bundles:match-skus', ['--apply' => true])->assertSuccessful();

        $this->assertNull($item->refresh()->product_id);

        $this->artisan('bundles:match-skus', ['--apply' => true, '--all' => true])->assertSuccessful();

        $this->assertSame($this->oli->id, $item->refresh()->product_id);
    }

    /* ------------------------------------------------- pembantu ---------- */

    /**
     * @param  array<int, array{0: Product, 1: int}>  $components
     */
    protected function makeBundle(string $sku, array $components): Product
    {
        $bundle = Product::create([
            'sku' => $sku,
            'name' => 'Paket '.$sku,
            'unit' => 'paket',
            'type' => Product::TYPE_BUNDLE,
        ]);

        foreach ($components as [$component, $quantity]) {
            $bundle->bundleComponents()->create([
                'component_id' => $component->id,
                'quantity' => $quantity,
            ]);
        }

        return $bundle->load('bundleComponents.component');
    }

    protected function orphanOrderItem(string $sku): ShipmentOrderItem
    {
        $import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);

        $order = $import->orders()->create([
            'tracking_number' => 'SPXID'.$sku,
            'order_number' => 'INV-1',
            'marketplace' => 'Shopee',
        ]);

        // Persis seperti baris yang masuk sebelum barangnya didaftarkan.
        return $order->items()->create(['sku' => $sku, 'quantity' => 1, 'product_id' => null]);
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

    protected function takeOut(Product $product, int $quantity): void
    {
        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(),
            'date' => now(),
            'recipient' => 'Pelanggan',
            'status' => \App\Models\Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function uploadCsv(array $rows)
    {
        $csv = collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n");

        $path = tempnam(sys_get_temp_dir(), 'produk').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'produk.csv', 'text/csv', null, true),
        ]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function readExport(): array
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.export'));

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return \PhpOffice\PhpSpreadsheet\IOFactory::load($path)
            ->getActiveSheet()
            ->toArray(null, true, false, false);
    }
}
