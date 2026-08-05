<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Susunan kolom sesuai eksport Ginee yang dipakai pengguna:
 * NO. | Tanggal Pembuatan | ID Pesanan | SKU | Jumlah | Kurir |
 * AWB/No. Tracking | Metode Pengiriman | Catatan Pembeli
 */
class GineeColumnLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        foreach (['KAB2', 'KAB149', 'KAB146', 'KAB3', 'KAB104'] as $sku) {
            Product::create(['sku' => $sku, 'name' => 'Kabel '.$sku, 'unit' => 'pcs']);
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function sheet(): array
    {
        return [
            ['NO.', 'Tanggal Pembuatan', 'ID Pesanan', 'SKU', 'Jumlah', 'Kurir', 'AWB/No. Tracking', 'Metode Pengiriman', 'Catatan Pembeli'],
            ['', '04-08-2026 09:34', '260804EU17WQYN', 'KAB2', 1, 'SPX Standard', 'SPXID065774278028', '', ''],
            [1, '04-08-2026 09:34', '260804EU17WQYN', 'KAB149', 1, 'SPX Standard', 'SPXID065774278028', '', ''],
            ['', '04-08-2026 09:29', '260804ETRFGX4S', 'KAB146', 1, 'SPX Hemat', 'SPXID063276231458', '', 'Tolong dibungkus rapi'],
            ['', '04-08-2026 09:29', '260804ETRFGX4S', 'KAB3', 1, 'SPX Hemat', 'SPXID063276231458', '', 'Tolong dibungkus rapi'],
            [2, '04-08-2026 09:29', '260804ETRFGX4S', 'KAB104', 1, 'SPX Hemat', 'SPXID063276231458', '', 'Tolong dibungkus rapi'],
        ];
    }

    public function test_the_export_layout_is_imported(): void
    {
        $this->upload($this->sheet())->assertSessionHas('success');

        // Dua nomor resi, lima baris SKU.
        $this->assertDatabaseCount('shipment_orders', 2);
        $this->assertDatabaseCount('shipment_order_items', 5);
    }

    public function test_the_waybill_column_named_awb_no_tracking_is_recognised(): void
    {
        $this->upload($this->sheet());

        $this->assertNotNull(ShipmentOrder::where('tracking_number', 'SPXID065774278028')->first());
        $this->assertNotNull(ShipmentOrder::where('tracking_number', 'SPXID063276231458')->first());
    }

    public function test_the_order_id_column_is_recognised(): void
    {
        $this->upload($this->sheet());

        $order = ShipmentOrder::where('tracking_number', 'SPXID065774278028')->firstOrFail();

        $this->assertSame('260804EU17WQYN', $order->order_number);
    }

    public function test_the_courier_column_is_stored(): void
    {
        $this->upload($this->sheet());

        $this->assertSame('SPX Standard', ShipmentOrder::where('tracking_number', 'SPXID065774278028')->value('courier'));
        $this->assertSame('SPX Hemat', ShipmentOrder::where('tracking_number', 'SPXID063276231458')->value('courier'));
    }

    public function test_the_marketplace_is_inferred_from_the_courier(): void
    {
        // Berkas ini tidak punya kolom Channel, tetapi SPX jelas milik Shopee.
        $this->upload($this->sheet());

        $this->assertSame('Shopee', ShipmentOrder::where('tracking_number', 'SPXID065774278028')->value('marketplace'));
    }

    public function test_the_creation_date_keeps_its_day_month_order_and_time(): void
    {
        $this->upload($this->sheet());

        $order = ShipmentOrder::where('tracking_number', 'SPXID065774278028')->firstOrFail();

        // 04-08-2026 berarti 4 Agustus 2026, bukan 8 April.
        $this->assertSame('2026-08-04 09:34:00', $order->order_date->toDateTimeString());
    }

    public function test_the_buyer_note_is_stored_and_not_mistaken_for_the_buyer_name(): void
    {
        $this->upload($this->sheet());

        $order = ShipmentOrder::where('tracking_number', 'SPXID063276231458')->firstOrFail();

        $this->assertSame('Tolong dibungkus rapi', $order->buyer_note);
        $this->assertNull($order->buyer_name);
    }

    public function test_the_numbering_column_is_ignored(): void
    {
        $this->upload($this->sheet());

        $order = ShipmentOrder::where('tracking_number', 'SPXID063276231458')->firstOrFail();

        // Kolom NO. hanya terisi di baris terakhir tiap pesanan, dan tidak
        // boleh mengganggu pengelompokan.
        $this->assertCount(3, $order->items);
        $this->assertSame(3, $order->totalQuantity());
    }

    public function test_all_skus_are_matched_to_the_product_master(): void
    {
        $this->upload($this->sheet());

        $order = ShipmentOrder::where('tracking_number', 'SPXID065774278028')->firstOrFail();

        $this->assertTrue($order->isFullyMatched());
        $this->assertEqualsCanonicalizing(['KAB2', 'KAB149'], $order->items->pluck('sku')->all());
    }

    public function test_the_import_page_shows_the_courier(): void
    {
        $this->upload($this->sheet());

        $this->actingAs($this->admin)->get(route('admin.imports.index'))
            ->assertOk()
            ->assertSee('SPX Standard')
            ->assertSee('SPX Hemat')
            ->assertSee('Tolong dibungkus rapi');
    }

    public function test_the_dashboard_counts_orders_per_courier(): void
    {
        $this->upload($this->sheet());

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pesanan per Ekspedisi')
            ->assertSee('SPX Standard')
            ->assertSee('SPX Hemat');
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function upload(array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit(
                    [$columnIndex + 1, $rowIndex + 1],
                    $value,
                    is_int($value)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ginee').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'ginee-orders.xlsx', null, null, true),
        ]);
    }
}
