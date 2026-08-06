<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockReportService;
use App\Support\StockReportFilters;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laporan stok: saldo awal dan akhir periode, pergerakan di antaranya, dan
 * kecepatan perputarannya.
 */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->product = $this->makeProduct('FLT-OLI-STD', 'Filter Oli Standar', minStock: 10);
    }

    /* --------------------------------------------------- angka laporan --- */

    public function test_the_period_reports_opening_movement_and_closing(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 30, Carbon::now()->subDays(10));

        $row = $this->reportRow();

        $this->assertSame(0, $row->opening);
        $this->assertSame(100, $row->incoming);
        $this->assertSame(30, $row->outgoing);
        $this->assertSame(70, $row->closing);
    }

    /**
     * Perputaran diukur terhadap rata-rata stok yang ditahan, bukan terhadap
     * saldo akhir — barang yang sempat menumpuk lalu habis dan barang yang
     * selalu tipis punya biaya simpan yang jauh berbeda.
     */
    public function test_turnover_and_days_of_cover_follow_the_period(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 30, Carbon::now()->subDays(10));

        $row = $this->reportRow();

        // 30 unit keluar selama 30 hari.
        $this->assertEqualsWithDelta(1.0, $row->perDay(), 0.001);

        // Rata-rata stok (0 + 70) / 2 = 35, jadi 30 / 35 kali berputar.
        $this->assertEqualsWithDelta(35.0, $row->averageStock(), 0.001);
        $this->assertEqualsWithDelta(30 / 35, $row->turnover(), 0.001);

        // Sisa 70 unit dengan laju 1 unit per hari.
        $this->assertEqualsWithDelta(70.0, $row->daysOfCover(), 0.001);
        $this->assertSame('70 hari', $row->coverLabel());
    }

    /**
     * Laporan periode lampau harus menyebut saldo sebagaimana adanya saat itu.
     * Yang tersimpan di products.stock adalah saldo hari ini, jadi mutasi
     * sesudah periode harus ditarik mundur.
     */
    public function test_a_past_period_reports_the_balance_as_it_was_then(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 30, Carbon::now()->subDays(10));

        // Periode berhenti sebelum pengiriman terjadi.
        $row = $this->reportRow($this->filters(from: 25, to: 15));

        $this->assertSame(0, $row->opening);
        $this->assertSame(100, $row->incoming);
        $this->assertSame(0, $row->outgoing);
        $this->assertSame(100, $row->closing, 'Saldo akhir periode harus 100, bukan 70 seperti hari ini.');

        // Ada stoknya, hanya belum berputar sama sekali — itu nol kali, bukan
        // "tidak ada datanya". Yang memang tidak bisa dihitung adalah kapan
        // stok sebanyak itu akan habis kalau tidak pernah keluar.
        $this->assertSame(0.0, $row->turnover());
        $this->assertNull($row->daysOfCover());
        $this->assertSame('Tidak bergerak', $row->urgencyBadge()['label']);
    }

    public function test_movements_outside_the_period_are_left_out(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(200));
        $this->ship($this->product, 40, Carbon::now()->subDays(150));

        $row = $this->reportRow();

        // Semuanya terjadi jauh sebelum periode: hanya saldonya yang terbawa.
        $this->assertSame(60, $row->opening);
        $this->assertSame(0, $row->incoming);
        $this->assertSame(0, $row->outgoing);
        $this->assertSame(60, $row->closing);
        $this->assertTrue($row->isIdle());
    }

    /**
     * Inti pertanyaannya: stok bergerak saat dokumen keluar disetujui, bukan
     * saat barang selesai discan. Paket yang menunggu di antrean siap kirim
     * belum boleh muncul sebagai barang keluar.
     */
    public function test_a_fully_scanned_package_does_not_move_stock_until_it_is_processed(): void
    {
        $this->receive($this->product, 50, Carbon::now()->subDays(5));

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => 'Shopee',
            'recipient' => 'Pembeli marketplace',
            'tracking_number' => 'SPXID111',
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);
        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 5,
            'scanned_quantity' => 5,
        ]);

        $this->assertSame(1, Outbound::readyToShip()->count());
        $this->assertSame(0, $this->reportRow()->outgoing);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertSessionHas('success');

        $this->assertSame(5, $this->reportRow()->outgoing);
        $this->assertSame(45, $this->reportRow()->closing);
    }

    /**
     * Saldo rusak punya hidupnya sendiri: tidak pernah dijual, jadi tidak
     * boleh ikut membuat angka perputaran terlihat lebih sehat.
     */
    public function test_damaged_stock_is_reported_but_never_counted_as_movement(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));

        // Pemberian label bucket-nya sendiri diuji di DamagedStockTest;
        // di sini yang diuji hanya bahwa laporan menghormatinya.
        $this->product->forceFill(['damaged_stock' => 8])->save();
        StockMovement::create([
            'product_id' => $this->product->id,
            'type' => 'in',
            'bucket' => StockMovement::BUCKET_DAMAGED,
            'quantity' => 8,
            'balance_after' => 8,
            'description' => 'Retur rusak',
        ]);

        $row = $this->reportRow();

        $this->assertSame(100, $row->incoming, 'Barang rusak tidak boleh ikut terhitung sebagai barang masuk.');
        $this->assertSame(100, $row->closing);
        $this->assertSame(8, $row->damaged);

        $this->assertSame(8, $this->summary()['damaged']);
    }

    /* --------------------------------------------------- sudut pandang --- */

    public function test_the_idle_view_lists_only_goods_that_never_left(): void
    {
        $idle = $this->makeProduct('KMP-001', 'Kampas Rem');

        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->receive($idle, 40, Carbon::now()->subDays(20));
        $this->ship($this->product, 30, Carbon::now()->subDays(10));

        $rows = app(StockReportService::class)->paginate($this->filters(view: 'mati'));

        $this->assertCount(1, $rows->items());
        $this->assertSame('KMP-001', $rows->items()[0]->sku);
        $this->assertSame(1, $this->summary()['idle']);
    }

    public function test_the_low_stock_view_lists_goods_at_or_below_the_minimum(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 95, Carbon::now()->subDays(10));

        $rows = app(StockReportService::class)->paginate($this->filters(view: 'menipis'));

        // Sisa 5 dengan batas minimum 10.
        $this->assertCount(1, $rows->items());
        $this->assertSame(5, $rows->items()[0]->closing);
        $this->assertTrue($rows->items()[0]->isLow());
        $this->assertSame(1, $this->summary()['low']);
    }

    public function test_the_busiest_goods_come_first(): void
    {
        $slow = $this->makeProduct('KMP-001', 'Kampas Rem');

        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->receive($slow, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 10, Carbon::now()->subDays(5));
        $this->ship($slow, 60, Carbon::now()->subDays(5));

        $bySales = app(StockReportService::class)->paginate($this->filters());
        $this->assertSame('KMP-001', $bySales->items()[0]->sku);

        // Yang paling cepat habis bukan yang paling laku: 90 sisa dengan laju
        // 0,33/hari bertahan lebih lama daripada 40 sisa dengan laju 2/hari.
        $byCover = app(StockReportService::class)->paginate($this->filters(sort: 'sisa'));
        $this->assertSame('KMP-001', $byCover->items()[0]->sku);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_shows_the_report(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));
        $this->ship($this->product, 30, Carbon::now()->subDays(10));

        $this->actingAs($this->admin)->get(route('admin.reports.stock'))
            ->assertOk()
            ->assertSee('Laporan Stok')
            ->assertSee('FLT-OLI-STD')
            ->assertSee('Perputaran');
    }

    public function test_an_unreadable_date_falls_back_to_the_default_period(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.stock', ['from' => 'kemarin', 'to' => '??']))
            ->assertOk();
    }

    public function test_the_report_needs_its_own_permission(): void
    {
        $role = Role::create(['name' => 'Pengamat Gudang', 'slug' => 'pengamat-gudang']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.reports.stock'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.reports.stock.export'))->assertForbidden();
    }

    public function test_the_report_downloads_as_a_spreadsheet(): void
    {
        $this->receive($this->product, 100, Carbon::now()->subDays(20));

        $response = $this->actingAs($this->admin)->get(route('admin.reports.stock.export'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('laporan-stok-', $response->headers->get('content-disposition'));
    }

    /**
     * Laporan adalah halaman yang justru dibuka saat datanya sudah banyak,
     * jadi jumlah query-nya tidak boleh tumbuh mengikuti jumlah barang.
     */
    public function test_the_report_costs_the_same_no_matter_how_many_goods(): void
    {
        foreach (range(1, 40) as $number) {
            $product = $this->makeProduct('SKU-'.$number, 'Barang '.$number);
            $this->receive($product, 50, Carbon::now()->subDays(20));
            $this->ship($product, $number, Carbon::now()->subDays(10));
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.reports.stock'))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "Laporan memakai {$queries} query.");
    }

    /* --------------------------------------------------- helpers --------- */

    protected function filters(int $from = 29, int $to = 0, string $view = 'semua', string $sort = 'keluar'): StockReportFilters
    {
        return StockReportFilters::fromRequest(Request::create('/', 'GET', [
            'from' => Carbon::now()->subDays($from)->format('Y-m-d'),
            'to' => Carbon::now()->subDays($to)->format('Y-m-d'),
            'view' => $view,
            'sort' => $sort,
        ]));
    }

    protected function reportRow(?StockReportFilters $filters = null, string $sku = 'FLT-OLI-STD')
    {
        $rows = app(StockReportService::class)->paginate($filters ?? $this->filters());

        return collect($rows->items())->firstOrFail(fn ($row) => $row->sku === $sku);
    }

    /**
     * @return array<string, int|float|null>
     */
    protected function summary(?StockReportFilters $filters = null): array
    {
        return app(StockReportService::class)->summary($filters ?? $this->filters());
    }

    protected function makeProduct(string $sku, string $name, int $minStock = 0): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => $name, 'unit' => 'pcs',
            'category' => 'Filter', 'min_stock' => $minStock,
        ]);
    }

    /**
     * Barang masuk yang disetujui pada tanggal tertentu.
     */
    protected function receive(Product $product, int $quantity, Carbon $at): void
    {
        $this->travelTo($at);

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));

        $this->travelBack();
    }

    /**
     * Barang keluar biasa yang disetujui pada tanggal tertentu.
     */
    protected function ship(Product $product, int $quantity, Carbon $at): void
    {
        $this->travelTo($at);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Otosby',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        $this->travelBack();
    }
}
