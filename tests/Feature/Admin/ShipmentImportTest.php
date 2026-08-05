<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $filter;

    protected Product $busi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->filter = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 5,
        ]);

        $this->busi = Product::create([
            'sku' => 'BSI-IRIDIUM', 'barcode' => '8991234500073',
            'name' => 'Busi Iridium', 'unit' => 'pcs', 'min_stock' => 10,
        ]);
    }

    /* ------------------------------------------------ proses import ------ */

    public function test_a_ginee_csv_export_is_imported_and_grouped_by_waybill(): void
    {
        $this->uploadCsv([
            ['Nomor Pesanan', 'Nomor Resi', 'Channel', 'Toko', 'Nama Pembeli', 'SKU', 'Nama Produk', 'Jumlah'],
            ['INV-001', 'SPXID111', 'Shopee', 'Otosby Store', 'Andi', 'FLT-OLI-STD', 'Filter Oli', '2'],
            ['INV-001', 'SPXID111', 'Shopee', 'Otosby Store', 'Andi', 'BSI-IRIDIUM', 'Busi Iridium', '4'],
            ['INV-002', 'JX222', 'Tokopedia', 'Otosby Store', 'Siti', 'FLT-OLI-STD', 'Filter Oli', '1'],
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('shipment_orders', 2);

        $order = ShipmentOrder::where('tracking_number', 'SPXID111')->firstOrFail();

        $this->assertSame('INV-001', $order->order_number);
        $this->assertSame('Shopee', $order->marketplace);
        $this->assertSame('Andi', $order->buyer_name);
        $this->assertCount(2, $order->items);
        $this->assertSame(6, $order->totalQuantity());
    }

    public function test_skus_are_matched_against_the_product_master(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '1'],
            ['SPXID111', 'SKU-TIDAK-ADA', '3'],
        ]);

        $order = ShipmentOrder::where('tracking_number', 'SPXID111')->firstOrFail();

        $this->assertTrue($order->items->firstWhere('sku', 'FLT-OLI-STD')->isMatched());
        $this->assertFalse($order->items->firstWhere('sku', 'SKU-TIDAK-ADA')->isMatched());
        $this->assertFalse($order->isFullyMatched());
        $this->assertDatabaseHas('shipment_imports', ['unmatched_sku_count' => 1]);
    }

    public function test_english_column_names_are_recognised_too(): void
    {
        $this->uploadCsv([
            ['Order ID', 'Tracking Number', 'Channel', 'Seller SKU', 'Product Name', 'Quantity'],
            ['INV-009', 'TIKTOK999', 'TikTok Shop', 'BSI-IRIDIUM', 'Busi Iridium', '3'],
        ])->assertSessionHas('success');

        $order = ShipmentOrder::where('tracking_number', 'TIKTOK999')->firstOrFail();

        $this->assertSame('INV-009', $order->order_number);
        $this->assertSame('TikTok Shop', $order->marketplace);
        $this->assertSame(3, $order->totalQuantity());
    }

    public function test_a_file_without_a_waybill_column_is_rejected(): void
    {
        $this->uploadCsv([
            ['Nomor Pesanan', 'SKU', 'Jumlah'],
            ['INV-001', 'FLT-OLI-STD', '2'],
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipment_orders', 0);
    }

    public function test_re_importing_the_same_waybill_replaces_the_previous_data(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '2'],
        ]);

        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'BSI-IRIDIUM', '5'],
        ]);

        $this->assertDatabaseCount('shipment_orders', 1);

        $order = ShipmentOrder::where('tracking_number', 'SPXID111')->firstOrFail();
        $this->assertSame('BSI-IRIDIUM', $order->items->first()->sku);
        $this->assertSame(5, $order->totalQuantity());
    }

    public function test_import_pages_are_rendered(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '2'],
        ]);

        $import = \App\Models\ShipmentImport::firstOrFail();

        foreach ([
            route('admin.imports.index'),
            route('admin.imports.create'),
            route('admin.imports.batches'),
            route('admin.imports.show', $import),
        ] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    public function test_lookup_endpoint_returns_the_order_for_a_waybill(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '2'],
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.imports.lookup', ['resi' => 'spxid 111']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('matched', true)
            ->assertJsonPath('items.0.sku', 'FLT-OLI-STD');

        $this->actingAs($this->admin)
            ->getJson(route('admin.imports.lookup', ['resi' => 'TIDAKADA']))
            ->assertStatus(404);
    }

    /* ------------------------------------------------ scan outbound ------ */

    public function test_outbound_scan_pulls_the_item_list_from_the_import(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '2'],
            ['SPXID111', 'BSI-IRIDIUM', '1'],
        ]);

        // Dokumen sengaja dibuat tanpa baris barang.
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Andi',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID111',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.resi', $outbound), ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('progress.resi_verified', true)
            ->assertJsonPath('progress.total', 3);

        $outbound->refresh()->load('items');

        $this->assertCount(2, $outbound->items);
        $this->assertSame(3, $outbound->totalQuantity());
        $this->assertNotNull($outbound->shipment_order_id);
    }

    public function test_items_pulled_from_the_import_can_then_be_scanned_and_shipped(): void
    {
        $this->giveStock($this->filter, 10);

        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'FLT-OLI-STD', '2'],
        ]);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID111',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)->postJson(route('admin.outbounds.scan.resi', $outbound), ['code' => 'SPXID111']);

        // Barang discan memakai barcode maupun SKU.
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => '8991234500035'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('admin.outbounds.scan.item', $outbound), ['code' => 'FLT-OLI-STD'])->assertOk();

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound))->assertSessionHas('success');

        $this->assertSame(8, $this->filter->refresh()->stock);
    }

    public function test_unmatched_skus_block_pulling_items_from_the_import(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID111', 'SKU-TIDAK-ADA', '2'],
        ]);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID111',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.resi', $outbound), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'SKU berikut belum terdaftar di master barang: SKU-TIDAK-ADA. Tambahkan barangnya terlebih dahulu.');

        $this->assertFalse($outbound->refresh()->isResiVerified());
    }

    /* ------------------------------------------------ scan retur --------- */

    public function test_return_scan_takes_items_from_the_import_automatically(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXRET1', 'FLT-OLI-STD', '2'],
            ['SPXRET1', 'BSI-IRIDIUM', '1'],
        ]);

        // Dokumen retur dibuat tanpa baris barang.
        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(), 'date' => now(),
            'type' => ReturnReceipt::TYPE_MARKETPLACE, 'sender' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXRET1',
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.scan.resi', $return), ['code' => 'SPXRET1'])
            ->assertOk()
            ->assertJsonPath('progress.resi_verified', true);

        $return->refresh()->load('items');

        $this->assertCount(2, $return->items);
        $this->assertSame(3, $return->totalQuantity());
        $this->assertNotNull($return->shipment_order_id);

        // Setelah item terisi, retur bisa langsung diterima.
        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return))->assertSessionHas('success');
        $this->assertSame(2, $this->filter->refresh()->stock);
    }

    public function test_manually_entered_return_items_are_kept_instead_of_import_data(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXRET1', 'FLT-OLI-STD', '5'],
        ]);

        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(), 'date' => now(),
            'type' => ReturnReceipt::TYPE_MARKETPLACE, 'sender' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXRET1',
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);

        // Retur sering hanya sebagian, jadi input operator lebih diutamakan.
        $return->items()->create(['product_id' => $this->filter->id, 'quantity' => 1, 'good_quantity' => 1]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.scan.resi', $return), ['code' => 'SPXRET1'])
            ->assertOk();

        $return->refresh()->load('items');

        $this->assertCount(1, $return->items);
        $this->assertSame(1, $return->totalQuantity());
    }

    public function test_return_without_imported_waybill_still_uses_manual_items(): void
    {
        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(), 'date' => now(),
            'type' => ReturnReceipt::TYPE_MARKETPLACE, 'sender' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'TIDAKDIIMPORT',
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);
        $return->items()->create(['product_id' => $this->filter->id, 'quantity' => 3, 'good_quantity' => 3]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.scan.resi', $return), ['code' => 'TIDAKDIIMPORT'])
            ->assertOk()
            ->assertJsonPath('progress.resi_verified', true);

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return))->assertSessionHas('success');
        $this->assertSame(3, $this->filter->refresh()->stock);
    }

    /* ------------------------------------------------ helpers ------------ */

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

    protected function giveStock(Product $product, int $quantity): void
    {
        $inbound = \App\Models\Inbound::create([
            'code' => \App\Models\Inbound::nextCode(),
            'date' => now(),
            'status' => \App\Models\Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
