<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Support\DateRange;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Beban kerja per ekspedisi pada suatu rentang tanggal.
 *
 * Yang paling perlu dijaga: angkanya harus sama persis dengan halaman Status
 * Resi. Dua halaman yang menghitung hal yang sama dengan cara berbeda cepat
 * atau lambat akan berselisih, dan yang salah tidak akan ketahuan.
 */
class CourierReportTest extends TestCase
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

    /* --------------------------------------------------- isi laporan ----- */

    public function test_each_courier_is_counted_with_its_stages(): void
    {
        $awaiting = $this->makeOrder('SPX', 'SPXID111');
        $this->makeOrder('SPX', 'SPXID222');

        $checked = $this->makeOrder('SPX', 'SPXID333');
        $this->packageFor($checked, scanned: true);

        $this->makeOrder('JNE', 'JNE111');

        $row = $this->rowFor('SPX');

        $this->assertSame(3, $row->total);
        $this->assertSame(2, $row->awaiting);
        $this->assertSame(1, $row->checked);
        $this->assertSame(0, $row->shipped);
        $this->assertSame(0, $row->cancelled);

        // 2 unit per resi.
        $this->assertSame(6, $row->units);

        $this->assertSame(1, $this->rowFor('JNE')->total);
        $this->assertNotNull($awaiting);
    }

    /**
     * Keempat tahap memilah habis seluruh resi — tidak ada yang terhitung dua
     * kali maupun terlewat.
     */
    public function test_the_stages_add_up_to_the_total(): void
    {
        $this->makeOrder('SPX', 'SPXID111');

        $checked = $this->makeOrder('SPX', 'SPXID222');
        $this->packageFor($checked, scanned: true);

        $shipped = $this->makeOrder('SPX', 'SPXID333');
        $this->packageFor($shipped, scanned: true, post: true);

        $cancelled = $this->makeOrder('SPX', 'SPXID444');
        $cancelled->forceFill(['cancelled_at' => now()])->save();

        $row = $this->rowFor('SPX');

        $this->assertSame(4, $row->total);
        $this->assertSame(
            $row->total,
            $row->awaiting + $row->checked + $row->shipped + $row->cancelled,
        );

        $this->assertSame(1, $row->awaiting);
        $this->assertSame(1, $row->checked);
        $this->assertSame(1, $row->shipped);
        $this->assertSame(1, $row->cancelled);
    }

    /** Angkanya harus sama dengan yang dihitung halaman Status Resi. */
    public function test_the_numbers_agree_with_the_waybill_status_page(): void
    {
        $this->makeOrder('SPX', 'SPXID111');

        $checked = $this->makeOrder('SPX', 'SPXID222');
        $this->packageFor($checked, scanned: true);

        $row = $this->rowFor('SPX');

        $this->assertSame(ShipmentOrder::awaitingQc()->count(), $row->awaiting);
        $this->assertSame(ShipmentOrder::qualityChecked()->count(), $row->checked);
        $this->assertSame(ShipmentOrder::shipped()->count(), $row->shipped);
        $this->assertSame(ShipmentOrder::cancelled()->count(), $row->cancelled);
    }

    /* --------------------------------------------------- penyaringan ----- */

    /**
     * Inti permintaannya: hanya ekspedisi yang benar-benar punya resi pada
     * rentang itu yang tampil. Ekspedisi tanpa resi bukan baris bernilai nol.
     */
    public function test_only_couriers_with_waybills_in_the_range_appear(): void
    {
        $this->makeOrder('SPX', 'SPXID111', Carbon::today());
        $this->makeOrder('JNE', 'JNE111', Carbon::today()->subMonth());

        $today = $this->couriers();

        $this->assertSame(['SPX'], $today->pluck('courier')->all());

        $all = $this->couriers(['range' => DateRange::ALL]);

        $this->assertEqualsCanonicalizing(['SPX', 'JNE'], $all->pluck('courier')->all());
    }

    /** Resi tanpa nama ekspedisi tidak melahirkan baris kosong. */
    public function test_waybills_without_a_courier_are_left_out(): void
    {
        $this->makeOrder('SPX', 'SPXID111');
        $this->makeOrder(null, 'SPXID222');
        $this->makeOrder('', 'SPXID333');

        $this->assertSame(['SPX'], $this->couriers()->pluck('courier')->all());
    }

    public function test_the_page_opens_on_the_current_day(): void
    {
        $this->makeOrder('SPX', 'SPXID111', Carbon::today());
        $this->makeOrder('JNE', 'JNE111', Carbon::today()->subMonth());

        $this->actingAs($this->admin)->get(route('admin.imports.couriers'))
            ->assertOk()
            ->assertSee('SPX')
            ->assertDontSee('JNE');
    }

    public function test_the_busiest_courier_comes_first(): void
    {
        $this->makeOrder('JNE', 'JNE111');

        foreach (['SPXID111', 'SPXID222', 'SPXID333'] as $tracking) {
            $this->makeOrder('SPX', $tracking);
        }

        $this->assertSame(['SPX', 'JNE'], $this->couriers()->pluck('courier')->all());
    }

    public function test_a_courier_can_be_searched_by_name(): void
    {
        $this->makeOrder('SPX Standard', 'SPXID111');
        $this->makeOrder('JNE Reguler', 'JNE111');

        $this->assertSame(['JNE Reguler'], $this->couriers(['search' => 'jne'])->pluck('courier')->all());
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_links_through_to_the_waybill_list(): void
    {
        $this->makeOrder('SPX', 'SPXID111');

        $this->actingAs($this->admin)->get(route('admin.imports.couriers'))
            ->assertOk()
            ->assertSee('Beban per Ekspedisi')
            // Tanpa argumen kedua: nilainya ikut di-escape seperti Blade
            // melakukannya, sehingga "&" pada alamat cocok dengan "&amp;".
            ->assertSee(route('admin.imports.status', [
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->toDateString(),
                'courier' => 'SPX',
            ]));
    }

    public function test_an_empty_range_says_so_instead_of_showing_nothing(): void
    {
        $this->actingAs($this->admin)->get(route('admin.imports.couriers'))
            ->assertOk()
            ->assertSee('Tidak ada resi pada rentang ini');
    }

    public function test_the_page_needs_the_import_permission(): void
    {
        $role = Role::create(['name' => 'Pengamat Gudang', 'slug' => 'pengamat-gudang']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.imports.couriers'))->assertForbidden();
    }

    /**
     * Laporan dibuka saat resinya sudah banyak, jadi jumlah query-nya tidak
     * boleh tumbuh mengikuti jumlah ekspedisi maupun resi.
     */
    public function test_the_report_costs_the_same_no_matter_how_many_waybills(): void
    {
        foreach (range(1, 30) as $number) {
            $this->makeOrder('EKS-'.($number % 6), 'RESI'.$number);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.imports.couriers'))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "Laporan memakai {$queries} query.");
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function couriers(array $query = [])
    {
        $response = $this->actingAs($this->admin)->get(route('admin.imports.couriers', $query))->assertOk();

        return collect($response->viewData('couriers'));
    }

    protected function rowFor(string $courier, array $query = []): object
    {
        return $this->couriers($query)->firstOrFail(fn ($row) => $row->courier === $courier);
    }

    /**
     * Resi disaring menurut kapan berkasnya diunggah, jadi waktunya digeser
     * saat barisnya dibuat.
     */
    protected function makeOrder(?string $courier, string $tracking, ?Carbon $date = null): ShipmentOrder
    {
        $this->travelTo($date ?? Carbon::today());

        $import = ShipmentImport::create([
            'filename' => 'ginee.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'courier' => $courier,
            'order_date' => $date ?? Carbon::today(),
        ]);

        $order->items()->create([
            'sku' => $this->product->sku,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 2,
        ]);

        $this->travelBack();

        return $order;
    }

    protected function packageFor(ShipmentOrder $order, bool $scanned, bool $post = false): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => $order->marketplace,
            'recipient' => 'Pembeli',
            'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);

        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'scanned_quantity' => $scanned ? 2 : 0,
        ]);

        if ($post) {
            $outbound->forceFill(['status' => Outbound::STATUS_POSTED, 'posted_at' => now()])->save();
        }

        return $outbound;
    }
}
