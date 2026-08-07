<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\RestockReportService;
use App\Support\RestockFilters;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laporan kebutuhan restock.
 *
 * Menjawab satu pertanyaan: hari ini perlu memesan apa, berapa banyak. Yang
 * paling menentukan benar-salahnya bukan tampilannya, melainkan definisi
 * "tersedia" — saldo yang sudah dijanjikan ke pembeli tidak boleh ikut dihitung
 * sebagai persediaan yang masih bisa dipakai.
 */
class RestockReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /* --------------------------------------------------- perhitungan ----- */

    /**
     * Barang yang masih di atas batas menipis pun perlu dipesan bila lajunya
     * tidak akan bertahan sampai akhir masa yang disiapkan.
     */
    public function test_a_fast_moving_item_is_flagged_before_it_reaches_the_minimum(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 5);
        $this->receive($product, 60);
        $this->ship($product, 30, Carbon::now()->subDays(10));

        // Sisa 30, laju 1/hari, disiapkan untuk 60 hari ke depan.
        $row = $this->row($this->filters(cover: 60));

        $this->assertSame(30, $row->available());
        $this->assertEqualsWithDelta(1.0, $row->perDay(), 0.001);
        $this->assertFalse($row->isBelowMinimum(), 'Masih jauh di atas batas menipis.');
        $this->assertSame(30, $row->suggested(), 'Butuh 60 unit untuk 60 hari, tersisa 30.');
        $this->assertSame('Menutup 60 hari ke depan', $row->reason());
    }

    /**
     * Barang yang jarang bergerak tetap perlu dikembalikan ke batas menipis;
     * ramalan laju tidak pernah bisa membenarkan rak yang kosong.
     */
    public function test_a_slow_item_is_still_topped_up_to_its_minimum(): void
    {
        $product = $this->makeProduct('KMP-1', minStock: 10);
        $this->receive($product, 3);

        $row = $this->row($this->filters(), 'KMP-1');

        $this->assertTrue($row->isIdle());
        $this->assertNull($row->daysOfCover());
        $this->assertSame(7, $row->suggested());
        $this->assertSame('Mengembalikan ke batas menipis', $row->reason());
    }

    /**
     * Inti laporan ini: unit yang sudah masuk dokumen barang keluar tetapi
     * belum diproses masih ada di rak, tetapi sudah menjadi milik pembeli.
     * Menghitungnya sebagai persediaan berarti memesan terlambat.
     */
    public function test_units_already_promised_to_buyers_are_not_counted_as_available(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 10);
        $this->receive($product, 30);

        $before = $this->row($this->filters());
        $this->assertSame(30, $before->available());
        $this->assertSame(0, $before->suggested());

        $this->promise($product, 25);

        $after = $this->row($this->filters());

        $this->assertSame(30, $after->stock, 'Saldo gudang tidak berubah — barangnya masih di rak.');
        $this->assertSame(25, $after->committed);
        $this->assertSame(5, $after->available());
        $this->assertSame(5, $after->suggested(), 'Perlu 5 unit lagi untuk kembali ke batas 10.');
    }

    /** Dokumen yang sudah dikirim bukan lagi janji — saldonya memang sudah keluar. */
    public function test_a_shipped_document_no_longer_counts_as_promised(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 2);
        $this->receive($product, 30);

        $outbound = $this->promise($product, 10);
        $this->assertSame(10, $this->row($this->filters())->committed);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        $row = $this->row($this->filters());

        $this->assertSame(0, $row->committed);
        $this->assertSame(20, $row->available());
    }

    public function test_an_empty_shelf_is_the_most_urgent(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 5);
        $this->receive($product, 10);
        $this->promise($product, 10);

        $row = $this->row($this->filters());

        $this->assertSame(0, $row->available());
        $this->assertTrue($row->isOutOfStock());
        $this->assertSame('habis', $row->urgency());
        $this->assertSame('Habis', $row->urgencyBadge()['label']);
    }

    /* --------------------------------------------------- sudut pandang --- */

    public function test_the_default_view_lists_only_what_needs_ordering(): void
    {
        $needy = $this->makeProduct('FLT-1', minStock: 20);
        $this->receive($needy, 5);

        $fine = $this->makeProduct('KMP-1', minStock: 2);
        $this->receive($fine, 500);

        $rows = app(RestockReportService::class)->paginate($this->filters());

        $this->assertCount(1, $rows->items());
        $this->assertSame('FLT-1', $rows->items()[0]->sku);

        // Sudut pandang "semua" tetap memperlihatkan keduanya.
        $all = app(RestockReportService::class)->paginate($this->filters(view: 'semua'));
        $this->assertCount(2, $all->items());
    }

    public function test_inactive_goods_are_left_out(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 20);
        $product->forceFill(['is_active' => false])->save();

        $rows = app(RestockReportService::class)->paginate($this->filters(view: 'semua'));

        $this->assertCount(0, $rows->items(), 'Barang nonaktif memang tidak dipesan lagi.');
    }

    public function test_the_most_urgent_comes_first(): void
    {
        $empty = $this->makeProduct('AAA-KOSONG', minStock: 5);

        $slow = $this->makeProduct('BBB-LAMBAT', minStock: 5);
        $this->receive($slow, 40);
        $this->ship($slow, 10, Carbon::now()->subDays(10));

        $fast = $this->makeProduct('CCC-CEPAT', minStock: 5);
        $this->receive($fast, 40);
        $this->ship($fast, 60, Carbon::now()->subDays(10));

        $rows = app(RestockReportService::class)->paginate($this->filters(view: 'semua'));
        $order = collect($rows->items())->pluck('sku')->all();

        $this->assertSame(['AAA-KOSONG', 'CCC-CEPAT', 'BBB-LAMBAT'], $order);
    }

    /* --------------------------------------------------- ringkasan ------- */

    public function test_the_summary_counts_the_whole_picture(): void
    {
        $needy = $this->makeProduct('FLT-1', minStock: 20);
        $this->receive($needy, 5);
        $this->promise($needy, 2);

        $fine = $this->makeProduct('KMP-1', minStock: 2);
        $this->receive($fine, 500);

        $summary = app(RestockReportService::class)->summary($this->filters());

        $this->assertSame(2, $summary['products'], 'Ringkasan tidak ikut dibatasi sudut pandang.');
        $this->assertSame(1, $summary['needing']);
        $this->assertSame(17, $summary['units'], '20 batas dikurangi 3 tersedia.');
        $this->assertSame(1, $summary['thin']);
        $this->assertSame(2, $summary['committed']);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_shows_the_report(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 20);
        $this->receive($product, 5);

        $this->actingAs($this->admin)->get(route('admin.reports.restock'))
            ->assertOk()
            ->assertSee('Kebutuhan Restock')
            ->assertSee('FLT-1')
            ->assertSee('Saran Pesan')
            // Definisi yang paling mudah disalahpahami dijelaskan di halamannya.
            ->assertSee('sudah dijanjikan ke pembeli');
    }

    public function test_an_unreadable_cover_falls_back_to_a_sane_number(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.restock', ['cover' => 'sebulan']))
            ->assertOk();

        $filters = RestockFilters::fromRequest(Request::create('/', 'GET', ['cover' => '99999']));

        $this->assertSame(365, $filters->coverDays, 'Satu angka salah ketik tidak boleh melahirkan saran sejuta unit.');
    }

    public function test_the_report_needs_the_permission(): void
    {
        $role = Role::create(['name' => 'Pengamat Gudang', 'slug' => 'pengamat-gudang']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.reports.restock'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.reports.restock.export'))->assertForbidden();
    }

    public function test_the_report_downloads_as_a_spreadsheet(): void
    {
        $product = $this->makeProduct('FLT-1', minStock: 20);
        $this->receive($product, 5);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.restock.export'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('kebutuhan-restock-', $response->headers->get('content-disposition'));
    }

    /**
     * Laporan ini justru dibuka saat barangnya sudah banyak, jadi jumlah
     * query-nya tidak boleh tumbuh mengikuti jumlah baris.
     */
    public function test_the_report_costs_the_same_no_matter_how_many_goods(): void
    {
        foreach (range(1, 40) as $number) {
            $product = $this->makeProduct('SKU-'.$number, minStock: 20);
            $this->receive($product, $number);
            $this->promise($product, 1);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.reports.restock'))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "Laporan memakai {$queries} query.");
    }

    /* --------------------------------------------------- helpers --------- */

    protected function filters(int $cover = 30, string $view = 'perlu', string $sort = 'mendesak'): RestockFilters
    {
        return RestockFilters::fromRequest(Request::create('/', 'GET', [
            'cover' => $cover,
            'view' => $view,
            'sort' => $sort,
        ]));
    }

    protected function row(RestockFilters $filters, string $sku = 'FLT-1')
    {
        return collect(app(RestockReportService::class)->paginate(
            new RestockFilters($filters->from, $filters->to, $filters->coverDays, view: 'semua'),
        )->items())->firstOrFail(fn ($row) => $row->sku === $sku);
    }

    protected function makeProduct(string $sku, int $minStock): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => 'Barang '.$sku, 'unit' => 'pcs',
            'category' => 'Filter', 'min_stock' => $minStock,
        ]);
    }

    protected function receive(Product $product, int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function ship(Product $product, int $quantity, Carbon $at): void
    {
        $this->travelTo($at);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_REGULAR, 'recipient' => 'Bengkel',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound));

        $this->travelBack();
    }

    /** Dokumen keluar yang belum diproses: barangnya masih di rak, sudah dijanjikan. */
    protected function promise(Product $product, int $quantity): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_REGULAR, 'recipient' => 'Pembeli',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $outbound;
    }
}
