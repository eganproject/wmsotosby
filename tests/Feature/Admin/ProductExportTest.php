<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'category' => 'Filter', 'unit' => 'pcs', 'min_stock' => 5]);
        Product::create(['sku' => 'BSI-IRIDIUM', 'name' => 'Busi Iridium', 'category' => 'Kelistrikan', 'unit' => 'pcs', 'min_stock' => 10]);
    }

    public function test_the_stock_page_offers_an_excel_download(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee(route('admin.products.export'), false)
            ->assertSee('Export Excel');
    }

    public function test_the_export_returns_a_spreadsheet(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.products.export'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_the_exported_file_contains_the_stock_rows(): void
    {
        $rows = $this->readExport(route('admin.products.export'));

        // Baris 4 adalah judul kolom, data mulai baris 5.
        $this->assertSame('SKU', $rows[3][0]);
        $this->assertSame('Nama Barang', $rows[3][2]);
        $this->assertSame('Stok', $rows[3][6]);

        $skus = collect($rows)->slice(4)->pluck(0)->filter()->values()->all();

        $this->assertEqualsCanonicalizing(['BSI-IRIDIUM', 'FLT-OLI-STD'], $skus);
    }

    public function test_the_export_follows_the_active_filter(): void
    {
        $rows = $this->readExport(route('admin.products.export', ['search' => 'Busi']));

        $skus = collect($rows)->slice(4)->pluck(0)->filter()->values()->all();

        $this->assertSame(['BSI-IRIDIUM'], $skus);
    }

    public function test_a_user_without_permission_can_not_export(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.products.export'))->assertForbidden();
    }

    /* --------------------------------------------------- seeder ---------- */

    public function test_the_supplier_seeder_creates_the_default_agent(): void
    {
        $this->seed(SupplierSeeder::class);

        $this->assertSame(1, Supplier::count());

        $supplier = Supplier::where('code', 'SUP-0001')->firstOrFail();

        $this->assertSame('Agen Surabaya', $supplier->name);
        $this->assertSame('Budi Santoso', $supplier->contact_name);
        $this->assertTrue($supplier->is_active);
    }

    public function test_the_supplier_seeder_can_run_twice(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->seed(SupplierSeeder::class);

        $this->assertSame(1, Supplier::count());
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function readExport(string $url): array
    {
        $response = $this->actingAs($this->admin)->get($url);

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }
}
