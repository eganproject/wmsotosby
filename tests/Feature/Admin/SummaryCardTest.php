<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Support\DateRange;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kartu ringkasan harus menghitung hal yang sama dengan tabel di bawahnya.
 *
 * Angka yang dihitung atas seluruh data sementara tabelnya sudah disaring
 * adalah jenis kesalahan yang paling mahal: ia terlihat wajar, tidak pernah
 * melempar galat, dan baru ketahuan saat seseorang menjumlahkan sendiri baris
 * yang tampil lalu mendapati hasilnya beda.
 */
class SummaryCardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- status resi ----- */

    public function test_the_waybill_cards_follow_the_date_filter(): void
    {
        $this->makeOrder('SPXID111', Carbon::today());
        $this->makeOrder('SPXID222', Carbon::today());
        $this->makeOrder('SPXID333', Carbon::today()->subMonth());

        // Halaman terbuka pada hari berjalan: dua resi, bukan tiga.
        $counts = $this->counts('admin.imports.status');

        $this->assertSame(2, array_sum($counts));
        $this->assertSame(2, $counts[ShipmentOrder::STAGE_AWAITING_QC]);

        $all = $this->counts('admin.imports.status', ['range' => DateRange::ALL]);

        $this->assertSame(3, array_sum($all));
    }

    public function test_the_waybill_cards_follow_the_courier_filter(): void
    {
        $this->makeOrder('SPXID111', Carbon::today(), 'SPX');
        $this->makeOrder('JNE111', Carbon::today(), 'JNE');

        $this->assertSame(1, array_sum($this->counts('admin.imports.status', ['courier' => 'SPX'])));
        $this->assertSame(2, array_sum($this->counts('admin.imports.status')));
    }

    public function test_the_waybill_cards_follow_the_search(): void
    {
        $this->makeOrder('SPXID111', Carbon::today());
        $this->makeOrder('JNE999', Carbon::today());

        $this->assertSame(1, array_sum($this->counts('admin.imports.status', ['search' => 'SPXID'])));
    }

    /**
     * Tahap sendiri tidak boleh ikut membatasi kartunya — kartu itulah pemilih
     * tahapnya, dan jumlah keempatnya harus tetap menjadi total.
     */
    public function test_choosing_a_stage_does_not_shrink_the_cards(): void
    {
        $this->makeOrder('SPXID111', Carbon::today());
        $this->makeOrder('SPXID222', Carbon::today());

        $this->assertSame(
            $this->counts('admin.imports.status'),
            $this->counts('admin.imports.status', ['stage' => ShipmentOrder::STAGE_SHIPPED]),
        );
    }

    /* --------------------------------------------------- barang & stok --- */

    public function test_the_product_cards_follow_the_category_filter(): void
    {
        $this->makeProduct('FLT-1', 'Filter', stock: 10, minStock: 2);
        $this->makeProduct('FLT-2', 'Filter', stock: 1, minStock: 5);
        $this->makeProduct('OLI-1', 'Oli', stock: 50, minStock: 2);

        $summary = $this->summary('admin.products.index', ['category' => 'Filter']);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(11, $summary['units']);
        $this->assertSame(1, $summary['low']);
    }

    /**
     * Kondisi stok tidak ikut membatasi kartunya: kartu Menipis dan Habis
     * justru pemilih kondisi itu.
     */
    public function test_choosing_a_stock_condition_does_not_shrink_the_cards(): void
    {
        $this->makeProduct('FLT-1', 'Filter', stock: 10, minStock: 2);
        $this->makeProduct('FLT-2', 'Filter', stock: 1, minStock: 5);

        $this->assertSame(
            $this->summary('admin.products.index'),
            $this->summary('admin.products.index', ['stock' => 'low']),
        );
    }

    /* --------------------------------------------------- data import ---- */

    public function test_the_import_cards_follow_the_filter(): void
    {
        $this->makeOrder('SPXID111', Carbon::today(), 'SPX');
        $this->makeOrder('JNE111', Carbon::today(), 'JNE');

        $summary = $this->summary('admin.imports.index', ['courier' => 'SPX']);

        $this->assertSame(1, $summary['orders']);
        $this->assertSame(1, $summary['items'], 'Baris barang ikut menyempit bersama pesanannya.');

        // Jumlah berkas menghitung unggahan, bukan pesanan, jadi tetap apa adanya.
        $this->assertSame(2, $summary['batches']);
    }

    public function test_the_import_cards_follow_the_date_filter(): void
    {
        $this->makeOrder('SPXID111', Carbon::today());
        $this->makeOrder('SPXID222', Carbon::today()->subMonth());

        $this->assertSame(1, $this->summary('admin.imports.index')['orders']);
        $this->assertSame(2, $this->summary('admin.imports.index', ['range' => DateRange::ALL])['orders']);
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @return array<string, int>
     */
    protected function counts(string $route, array $query = []): array
    {
        return $this->actingAs($this->admin)->get(route($route, $query))
            ->assertOk()
            ->viewData('counts');
    }

    /**
     * @return array<string, int>
     */
    protected function summary(string $route, array $query = []): array
    {
        return $this->actingAs($this->admin)->get(route($route, $query))
            ->assertOk()
            ->viewData('summary');
    }

    protected function makeProduct(string $sku, string $category, int $stock, int $minStock): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => 'Barang '.$sku, 'unit' => 'pcs',
            'category' => $category, 'min_stock' => $minStock,
        ]);

        $product->forceFill(['stock' => $stock])->save();

        return $product;
    }

    /** Saringannya memakai tanggal unggah, jadi waktunya digeser saat dibuat. */
    protected function makeOrder(string $tracking, Carbon $date, string $courier = 'SPX'): ShipmentOrder
    {
        $this->travelTo($date);

        $import = ShipmentImport::create([
            'filename' => 'ginee-'.$tracking.'.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'courier' => $courier,
            'order_date' => $date,
        ]);

        $order->items()->create(['sku' => 'FLT-X', 'product_name' => 'Filter', 'quantity' => 2]);

        $this->travelBack();

        return $order;
    }
}
