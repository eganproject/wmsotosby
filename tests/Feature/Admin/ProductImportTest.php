<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductImport;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Import master barang beserta stoknya dari Excel.
 */
class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_import_page_is_reachable_from_the_product_list(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee(route('admin.products.import'), false)
            ->assertSee('Import Excel');

        $this->actingAs($this->admin)->get(route('admin.products.import'))
            ->assertOk()
            ->assertSee('Import Barang &amp; Stok', false)
            ->assertSee('Unduh Template');
    }

    public function test_the_template_can_be_downloaded(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.import.template'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('template-import-barang.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_the_template_carries_the_expected_columns_and_a_guide(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.import.template'));

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $book = IOFactory::load($path);

        $this->assertSame(['Data Barang', 'Petunjuk'], $book->getSheetNames());

        $header = $book->getSheetByName('Data Barang')->toArray()[0];

        $this->assertSame(
            ['SKU', 'Nama Barang', 'Barcode', 'Kategori', 'Satuan', 'Lokasi Rak', 'Stok Minimum', 'Stok'],
            $header,
        );
    }

    public function test_optional_column_headings_are_grey_and_required_ones_black(): void
    {
        $sheet = $this->templateSheet('Data Barang');

        // Kolom wajib: SKU (A) dan Nama Barang (B).
        foreach (['A1', 'B1'] as $cell) {
            $this->assertSame('0A0A0A', $sheet->getStyle($cell)->getFill()->getStartColor()->getRGB(), $cell);
            $this->assertSame('FFFFFF', $sheet->getStyle($cell)->getFont()->getColor()->getRGB(), $cell);
        }

        // Sisanya opsional: Barcode, Kategori, Satuan, Lokasi Rak, Stok Minimum, Stok.
        foreach (['C1', 'D1', 'E1', 'F1', 'G1', 'H1'] as $cell) {
            $this->assertSame('E7E7E7', $sheet->getStyle($cell)->getFill()->getStartColor()->getRGB(), $cell);
            $this->assertSame('454545', $sheet->getStyle($cell)->getFont()->getColor()->getRGB(), $cell);
        }
    }

    public function test_the_guide_sheet_explains_the_colour_convention(): void
    {
        $notes = collect($this->templateSheet('Petunjuk')->toArray())->flatten()->filter()->implode(' ');

        $this->assertStringContainsString('berlatar abu-abu boleh dikosongkan', $notes);
    }

    public function test_the_template_can_be_filled_in_and_imported_back(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.import.template'));

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        // Baris contoh pada template memang siap pakai.
        $this->actingAs($this->admin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'template-import-barang.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Product::count());
        $this->assertSame(40, Product::where('sku', 'FLT-OLI-STD')->value('stock'));
    }

    /* --------------------------------------------------- isi berkas ------ */

    public function test_products_are_created_with_their_stock(): void
    {
        $this->upload([
            ['SKU', 'Nama Barang', 'Barcode', 'Kategori', 'Satuan', 'Lokasi Rak', 'Stok Minimum', 'Stok'],
            ['FLT-OLI-STD', 'Filter Oli Standar', '8991234500035', 'Filter', 'pcs', 'A-02-01', 15, 40],
            ['BSI-IRIDIUM', 'Busi Iridium', '8991234500073', 'Kelistrikan', 'pcs', 'B-02-01', 24, 120],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Product::count());

        $filter = Product::where('sku', 'FLT-OLI-STD')->firstOrFail();

        $this->assertSame('Filter Oli Standar', $filter->name);
        $this->assertSame('8991234500035', $filter->barcode);
        $this->assertSame('Filter', $filter->category);
        $this->assertSame('A-02-01', $filter->location);
        $this->assertSame(15, $filter->min_stock);
        $this->assertSame(40, $filter->stock);
    }

    public function test_imported_stock_is_recorded_on_the_stock_card(): void
    {
        $this->upload([
            ['SKU', 'Nama Barang', 'Stok'],
            ['FLT-OLI-STD', 'Filter Oli Standar', 40],
        ]);

        $product = Product::firstOrFail();

        // Stok tidak ditulis diam-diam: ada jejaknya di kartu stok.
        $movement = StockMovement::where('product_id', $product->id)->firstOrFail();

        $this->assertSame('in', $movement->type);
        $this->assertSame(40, $movement->quantity);
        $this->assertSame(40, $movement->balance_after);
        $this->assertStringContainsString('Import stok', $movement->description);
    }

    public function test_an_existing_sku_is_updated_instead_of_duplicated(): void
    {
        Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Nama Lama', 'unit' => 'pcs', 'min_stock' => 5]);

        $this->upload([
            ['SKU', 'Nama Barang', 'Stok Minimum'],
            ['FLT-OLI-STD', 'Filter Oli Standar', 20],
        ]);

        $this->assertSame(1, Product::count());
        $this->assertSame('Filter Oli Standar', Product::firstOrFail()->name);
        $this->assertSame(20, Product::firstOrFail()->min_stock);
    }

    public function test_a_second_import_adjusts_the_stock_by_the_difference(): void
    {
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['FLT-OLI-STD', 'Filter Oli', 40]]);
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['FLT-OLI-STD', 'Filter Oli', 25]]);

        $product = Product::firstOrFail();

        $this->assertSame(25, $product->stock);

        // Selisihnya keluar 15, bukan menimpa angka begitu saja.
        $movements = StockMovement::where('product_id', $product->id)->orderBy('id')->get();

        $this->assertCount(2, $movements);
        $this->assertSame('out', $movements[1]->type);
        $this->assertSame(15, $movements[1]->quantity);
        $this->assertSame(25, $movements[1]->balance_after);
    }

    public function test_an_unchanged_stock_creates_no_movement(): void
    {
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['FLT-OLI-STD', 'Filter Oli', 40]]);
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['FLT-OLI-STD', 'Filter Oli', 40]]);

        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, ProductImport::latest('id')->value('stock_adjusted_count'));
    }

    public function test_a_file_without_a_stock_column_leaves_the_stock_alone(): void
    {
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['FLT-OLI-STD', 'Filter Oli', 40]]);

        $this->upload([['SKU', 'Nama Barang', 'Kategori'], ['FLT-OLI-STD', 'Filter Oli', 'Filter']]);

        $this->assertSame(40, Product::firstOrFail()->stock);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_english_column_names_are_recognised(): void
    {
        $this->upload([
            ['Product Code', 'Product Name', 'Barcode', 'Category', 'Unit', 'Location', 'Min Stock', 'Stock'],
            ['FLT-OLI-STD', 'Oil Filter', '8991234500035', 'Filter', 'pcs', 'A-02-01', 10, 33],
        ])->assertSessionHasNoErrors();

        $this->assertSame(33, Product::firstOrFail()->stock);
    }

    public function test_a_file_without_a_sku_column_is_rejected(): void
    {
        $this->upload([
            ['Nama Barang', 'Stok'],
            ['Filter Oli', 5],
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Product::count());
    }

    public function test_duplicate_barcodes_stop_the_whole_import(): void
    {
        $this->upload([
            ['SKU', 'Nama Barang', 'Barcode'],
            ['SKU-A', 'Barang A', '111'],
            ['SKU-B', 'Barang B', '111'],
        ])->assertSessionHasErrors('file');

        // Tidak ada barang yang setengah tersimpan.
        $this->assertSame(0, Product::count());
    }

    public function test_a_barcode_already_used_by_another_product_is_rejected(): void
    {
        Product::create(['sku' => 'LAMA', 'name' => 'Barang Lama', 'barcode' => '111', 'unit' => 'pcs']);

        $this->upload([
            ['SKU', 'Nama Barang', 'Barcode'],
            ['BARU', 'Barang Baru', '111'],
        ])->assertSessionHasErrors('file');

        $this->assertNull(Product::where('sku', 'BARU')->first());
    }

    public function test_a_negative_stock_is_rejected(): void
    {
        $this->upload([['SKU', 'Nama Barang', 'Stok'], ['SKU-A', 'Barang A', -5]])
            ->assertSessionHasErrors();

        $this->assertSame(0, Product::count());
    }

    public function test_a_user_without_permission_can_not_import(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.products.import'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.products.import.template'))->assertForbidden();
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * Unduh template lalu buka lembar tertentu untuk diperiksa.
     */
    protected function templateSheet(string $name): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.import.template'));

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getSheetByName($name);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function upload(array $rows)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'barang').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $this->actingAs($this->admin)->post(route('admin.products.import.store'), [
            'file' => new UploadedFile($path, 'barang.xlsx', null, null, true),
        ]);
    }
}
