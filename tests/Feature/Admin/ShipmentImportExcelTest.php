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
 * Import resi dari berkas Excel asli (.xlsx), format yang dipakai Ginee.
 */
class ShipmentImportExcelTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs']);
        Product::create(['sku' => 'BSI-IRIDIUM', 'name' => 'Busi Iridium', 'unit' => 'pcs']);
    }

    public function test_an_xlsx_export_is_imported(): void
    {
        $this->uploadXlsx([
            ['Nomor Pesanan', 'Nomor Resi', 'Channel', 'Toko', 'Nama Pembeli', 'SKU', 'Nama Produk', 'Jumlah'],
            ['250805-A', 'SPXID55501', 'Shopee', 'Otosby Official', 'Andi', 'FLT-OLI-STD', 'Filter Oli Standar', 2],
            ['250805-A', 'SPXID55501', 'Shopee', 'Otosby Official', 'Andi', 'BSI-IRIDIUM', 'Busi Iridium', 4],
            ['250805-B', 'JX55502', 'Tokopedia', 'Otosby Official', 'Siti', 'FLT-OLI-STD', 'Filter Oli Standar', 1],
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('shipment_orders', 2);

        $order = ShipmentOrder::where('tracking_number', 'SPXID55501')->firstOrFail();

        $this->assertSame('250805-A', $order->order_number);
        $this->assertSame('Shopee', $order->marketplace);
        $this->assertSame('Andi', $order->buyer_name);
        $this->assertSame(6, $order->totalQuantity());
        $this->assertTrue($order->isFullyMatched());
    }

    public function test_numeric_cells_are_read_as_quantities(): void
    {
        // Excel menyimpan angka sebagai float, bukan teks.
        $this->uploadXlsx([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID55501', 'FLT-OLI-STD', 12],
        ]);

        $this->assertSame(12, ShipmentOrder::firstOrFail()->totalQuantity());
    }

    public function test_a_file_without_an_extension_is_still_recognised(): void
    {
        // Berkas unggahan disimpan Laravel sebagai berkas sementara tanpa
        // ekstensi, jadi format harus dikenali dari isinya.
        $path = $this->makeXlsx([
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID77701', 'FLT-OLI-STD', 3],
        ]);

        $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'ginee-orders.xlsx', null, null, true),
        ])->assertSessionHas('success');

        $this->assertSame(3, ShipmentOrder::firstOrFail()->totalQuantity());
    }

    public function test_leading_blank_rows_before_the_header_are_skipped(): void
    {
        $this->uploadXlsx([
            ['', '', ''],
            ['Laporan Pesanan', '', ''],
            ['', '', ''],
            ['Nomor Resi', 'SKU', 'Jumlah'],
            ['SPXID88801', 'FLT-OLI-STD', 5],
        ])->assertSessionHas('success');

        $this->assertSame(5, ShipmentOrder::firstOrFail()->totalQuantity());
    }

    public function test_an_xlsx_without_a_sku_column_is_rejected(): void
    {
        $this->uploadXlsx([
            ['Nomor Resi', 'Nama Produk', 'Jumlah'],
            ['SPXID55501', 'Filter Oli', 2],
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('shipment_orders', 0);
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function uploadXlsx(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($this->makeXlsx($rows), 'ginee-orders.xlsx', null, null, true),
        ]);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ginee').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
