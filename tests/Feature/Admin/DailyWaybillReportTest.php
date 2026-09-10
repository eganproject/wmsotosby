<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Laporan baca-saja yang merekap resi menurut tanggal masuknya. */
class DailyWaybillReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
        $this->product = Product::create([
            'sku' => 'FLT-1', 'name' => 'Filter Oli', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    public function test_it_aggregates_each_day_without_double_counting_the_stages(): void
    {
        $firstDay = Carbon::parse('2026-09-01');
        $secondDay = Carbon::parse('2026-09-02');

        $this->makeOrder('SPX', 'SPX-AWAIT', $firstDay, 2);

        $checked = $this->makeOrder('JNE', 'JNE-CHECK', $firstDay, 3);
        $this->packageFor($checked, quantity: 3, scanned: 3);

        $shipped = $this->makeOrder('SPX', 'SPX-SHIPPED', $secondDay, 4);
        $this->packageFor($shipped, quantity: 4, scanned: 4, posted: true);

        $cancelled = $this->makeOrder('JNE', 'JNE-CANCEL', $secondDay, 1);
        $cancelled->forceFill(['cancelled_at' => now()])->save();

        $rows = $this->rows(['from' => '2026-09-01', 'to' => '2026-09-02'])->keyBy('date');

        $this->assertSame(2, $rows['2026-09-01']->total);
        $this->assertSame(5, $rows['2026-09-01']->units);
        $this->assertSame(2, $rows['2026-09-01']->couriers);
        $this->assertSame(1, $rows['2026-09-01']->awaiting);
        $this->assertSame(1, $rows['2026-09-01']->checked);
        $this->assertSame(50, $rows['2026-09-01']->readiness());

        $this->assertSame(2, $rows['2026-09-02']->total);
        $this->assertSame(1, $rows['2026-09-02']->shipped);
        $this->assertSame(1, $rows['2026-09-02']->cancelled);
        $this->assertSame(100, $rows['2026-09-02']->readiness());

        foreach ($rows as $row) {
            $this->assertSame($row->total, $row->awaiting + $row->checked + $row->shipped + $row->cancelled);
        }
    }

    public function test_the_default_range_is_the_current_month(): void
    {
        $today = $this->makeOrder('SPX', 'RESI-HARI-INI', Carbon::today(), 1);
        $old = $this->makeOrder('SPX', 'RESI-BULAN-LALU', Carbon::today()->subMonth(), 1);

        $rows = $this->rows();

        $this->assertSame(1, $rows->sum('total'));
        $this->assertNotNull($today);
        $this->assertNotNull($old);
    }

    public function test_courier_and_search_filters_only_change_the_report_result(): void
    {
        $date = Carbon::parse('2026-09-03');
        $this->makeOrder('SPX', 'SPX-111', $date, 2);
        $this->makeOrder('JNE', 'JNE-222', $date, 3);

        $byCourier = $this->rows([
            'from' => '2026-09-03', 'to' => '2026-09-03', 'courier' => 'SPX',
        ]);
        $bySearch = $this->rows([
            'from' => '2026-09-03', 'to' => '2026-09-03', 'search' => 'JNE-222',
        ]);

        $this->assertSame(1, $byCourier->sum('total'));
        $this->assertSame(2, $byCourier->sum('units'));
        $this->assertSame(1, $bySearch->sum('total'));
        $this->assertSame(3, $bySearch->sum('units'));
    }

    public function test_an_unreadable_date_does_not_break_the_report(): void
    {
        $this->makeOrder('SPX', 'RESI-HARI-INI', Carbon::today(), 1);

        $this->actingAs($this->admin)->get(route('admin.imports.daily', [
            'from' => 'kemarin', 'to' => 'tidak-valid',
        ]))->assertOk();
    }

    public function test_each_number_links_to_the_matching_status_list(): void
    {
        $date = Carbon::parse('2026-09-04');
        $this->makeOrder('SPX', 'SPX-111', $date, 2);

        $this->actingAs($this->admin)->get(route('admin.imports.daily', [
            'from' => '2026-09-04', 'to' => '2026-09-04', 'courier' => 'SPX',
        ]))
            ->assertOk()
            ->assertSee('Laporan Resi per Hari')
            ->assertSee(route('admin.imports.status', [
                'from' => '2026-09-04',
                'to' => '2026-09-04',
                'courier' => 'SPX',
            ]))
            ->assertSee(route('admin.imports.status', [
                'from' => '2026-09-04',
                'to' => '2026-09-04',
                'courier' => 'SPX',
                'stage' => ShipmentOrder::STAGE_AWAITING_QC,
            ]));
    }

    public function test_opening_and_exporting_the_report_never_changes_source_data(): void
    {
        $order = $this->makeOrder('SPX', 'SPX-READONLY', Carbon::today(), 2);
        $before = $order->fresh()->getAttributes();

        $this->actingAs($this->admin)->get(route('admin.imports.daily'))->assertOk();
        $export = $this->actingAs($this->admin)->get(route('admin.imports.daily.export'));

        $export->assertOk();
        $this->assertStringContainsString('spreadsheetml', $export->headers->get('content-type'));
        $this->assertStringContainsString('laporan-resi-harian-', $export->headers->get('content-disposition'));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('shipment_orders', 1);
    }

    public function test_the_report_needs_the_import_permission(): void
    {
        $role = Role::create(['name' => 'Tanpa Resi', 'slug' => 'tanpa-resi-harian']);
        $role->permissions()->sync(Permission::where('slug', 'dashboard.view')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.imports.daily'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.imports.daily.export'))->assertForbidden();
    }

    public function test_query_count_does_not_grow_with_the_number_of_days(): void
    {
        foreach (range(1, 20) as $day) {
            $this->makeOrder('SPX', "RESI-{$day}", Carbon::parse('2026-09-01')->addDays($day - 1), 1);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.imports.daily', [
            'from' => '2026-09-01', 'to' => '2026-09-30',
        ]))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "Laporan memakai {$queries} query.");
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Support\DailyWaybillReportRow>
     */
    protected function rows(array $query = [])
    {
        return collect($this->actingAs($this->admin)
            ->get(route('admin.imports.daily', $query))
            ->assertOk()
            ->viewData('rows'));
    }

    protected function makeOrder(string $courier, string $tracking, Carbon $date, int $quantity): ShipmentOrder
    {
        $this->travelTo($date);

        $import = ShipmentImport::create([
            'filename' => "ginee-{$tracking}.csv", 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'courier' => $courier,
            'order_date' => $date,
        ]);

        $order->items()->create([
            'sku' => $this->product->sku,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => $quantity,
        ]);

        $this->travelBack();

        return $order;
    }

    protected function packageFor(
        ShipmentOrder $order,
        int $quantity,
        int $scanned,
        bool $posted = false,
    ): Outbound {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => $order->marketplace,
            'recipient' => 'Pembeli',
            'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id,
            'status' => $posted ? Outbound::STATUS_POSTED : Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
            'posted_at' => $posted ? now() : null,
        ]);

        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'scanned_quantity' => $scanned,
        ]);

        return $outbound;
    }
}
