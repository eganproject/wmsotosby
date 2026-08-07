<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ShipmentImport;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Anggaran query per halaman.
 *
 * Halaman daftar memuat 10 baris; jumlah query harus tetap sama berapa pun
 * jumlah barisnya. Batas di bawah ini sengaja ketat supaya N+1 yang menyelinap
 * langsung ketahuan, bukan baru terasa saat data membesar.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->seedWarehouse();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['admin.dashboard', 22],
            'barang & stok' => ['admin.products.index', 15],
            'mutasi stok' => ['admin.movements.index', 15],
            'pemasok' => ['admin.suppliers.index', 12],
            'barang masuk' => ['admin.inbounds.index', 14],
            'barang keluar' => ['admin.outbounds.index', 15],
            'siap dikirim' => ['admin.outbounds.ready', 15],
            'penerimaan retur' => ['admin.returns.index', 14],
            'penyesuaian stok' => ['admin.adjustments.index', 15],
            'stok opname' => ['admin.opnames.index', 15],
            'barang rusak' => ['admin.disposals.index', 17],
            'import resi' => ['admin.imports.index', 16],
            'status resi' => ['admin.imports.status', 14],
            'per ekspedisi' => ['admin.imports.couriers', 16],
            'persetujuan' => ['admin.approvals.index', 22],
            'pengguna' => ['admin.users.index', 14],
            'role' => ['admin.roles.index', 14],
            'hak akses' => ['admin.permissions.index', 14],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_a_list_page_stays_within_its_query_budget(string $route, int $budget): void
    {
        $queries = $this->measure($route);

        $this->assertLessThanOrEqual(
            $budget,
            $queries,
            "Halaman {$route} menjalankan {$queries} query, melebihi anggaran {$budget}.",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pagesWithRows(): array
    {
        return [
            'barang & stok' => ['admin.products.index', 'SKU-A1'],
            'barang masuk' => ['admin.inbounds.index', 'IN-A1'],
            'barang keluar' => ['admin.outbounds.index', 'OUT-A1'],
            'penerimaan retur' => ['admin.returns.index', 'RET-A1'],
            'penyesuaian stok' => ['admin.adjustments.index', 'ADJ-A1'],
            'stok opname' => ['admin.opnames.index', 'OPN-A1'],
            'import resi' => ['admin.imports.index', 'TRK-A1'],
            'status resi' => ['admin.imports.status', 'TRK-A1'],
        ];
    }

    /**
     * Anggaran query hanya berarti bila halamannya benar-benar berisi.
     *
     * Halaman kosong selalu hemat query, jadi tanpa penjagaan ini satu saringan
     * bawaan yang keliru bisa menyembunyikan seluruh baris sekaligus membuat
     * seluruh anggaran di atas lulus tanpa mengukur apa pun.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pagesWithRows')]
    public function test_the_budget_is_measured_against_a_page_that_has_rows(string $route, string $needle): void
    {
        $this->actingAs($this->admin)->get(route($route))->assertOk()->assertSee($needle);
    }

    public function test_list_pages_do_not_grow_with_the_number_of_rows(): void
    {
        $routes = [
            'admin.products.index', 'admin.movements.index', 'admin.inbounds.index',
            'admin.outbounds.index', 'admin.outbounds.ready', 'admin.adjustments.index',
            'admin.opnames.index', 'admin.disposals.index', 'admin.imports.status',
        ];

        $before = collect($routes)->mapWithKeys(fn ($route) => [$route => $this->measure($route)]);

        // Data diperbanyak; jumlah query harus tetap.
        $this->seedWarehouse(suffix: 'B');

        foreach ($routes as $route) {
            $after = $this->measure($route);

            $this->assertSame(
                $before[$route],
                $after,
                "Halaman {$route} bertambah query saat datanya bertambah — indikasi N+1.",
            );
        }
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * Hitung query satu permintaan halaman.
     *
     * Pengguna diambil ulang setiap kali agar relasi yang sempat termuat pada
     * permintaan sebelumnya tidak membuat pengukuran ini tampak lebih hemat
     * daripada kenyataannya di produksi.
     */
    protected function measure(string $route): int
    {
        $user = User::findOrFail($this->admin->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route($route))->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Query pengambilan pengguna di atas tidak dihitung karena berada di
        // luar jendela pencatatan.
        return $count;
    }

    protected function seedWarehouse(string $suffix = 'A'): void
    {
        $supplier = Supplier::create(['code' => "SUP-{$suffix}", 'name' => "Agen {$suffix}"]);

        $products = collect(range(1, 6))->map(fn ($i) => Product::create([
            'sku' => "SKU-{$suffix}{$i}",
            'barcode' => "899{$suffix}00000{$i}",
            'name' => "Barang {$suffix}{$i}",
            'unit' => 'pcs',
            'min_stock' => 5,
        ]));

        $import = ShipmentImport::create(['filename' => "ginee-{$suffix}.xlsx", 'source' => 'ginee']);

        foreach (range(1, 4) as $i) {
            $inbound = Inbound::create([
                'code' => "IN-{$suffix}{$i}", 'date' => now(),
                'supplier_id' => $supplier->id, 'status' => Inbound::STATUS_DRAFT,
            ]);

            $outbound = Outbound::create([
                'code' => "OUT-{$suffix}{$i}", 'date' => now(), 'type' => Outbound::TYPE_REGULAR,
                'recipient' => "Pembeli {$i}", 'status' => Outbound::STATUS_DRAFT,
            ]);

            $return = ReturnReceipt::create([
                'code' => "RET-{$suffix}{$i}", 'date' => now(), 'type' => ReturnReceipt::TYPE_REGULAR,
                'sender' => "Pengirim {$i}", 'status' => ReturnReceipt::STATUS_DRAFT,
            ]);

            $opname = \App\Models\StockOpname::create([
                'code' => "OPN-{$suffix}{$i}", 'date' => now(),
                'scope' => \App\Models\StockOpname::SCOPE_ALL, 'status' => \App\Models\StockOpname::STATUS_DRAFT,
            ]);

            $adjustment = StockAdjustment::create([
                'code' => "ADJ-{$suffix}{$i}", 'date' => now(),
                'reason' => 'Stok opname', 'status' => StockAdjustment::STATUS_DRAFT,
            ]);

            $order = $import->orders()->create([
                'tracking_number' => "TRK-{$suffix}{$i}",
                'order_number' => "INV-{$suffix}{$i}",
                'marketplace' => 'Shopee',
                'courier' => 'SPX Standard',
            ]);

            foreach ($products->take(3) as $product) {
                $inbound->items()->create(['product_id' => $product->id, 'quantity' => 5]);
                $outbound->items()->create(['product_id' => $product->id, 'quantity' => 1]);
                $return->items()->create(['product_id' => $product->id, 'quantity' => 2, 'good_quantity' => 2]);
                $adjustment->items()->create([
                    'product_id' => $product->id, 'system_quantity' => 5, 'actual_quantity' => 4,
                ]);
                $opname->items()->create([
                    'product_id' => $product->id, 'system_quantity' => 5, 'counted_quantity' => 4,
                ]);
                $order->items()->create([
                    'sku' => $product->sku, 'quantity' => 2, 'product_id' => $product->id,
                ]);
            }

            // Sebagian dokumen menunggu persetujuan agar kotak masuk terisi.
            if ($i === 1) {
                foreach ([$inbound, $outbound, $return, $adjustment] as $document) {
                    $document->forceFill([
                        'status' => 'pending',
                        'submitted_at' => now(),
                        'submitted_by' => $this->admin->id,
                    ])->save();
                }
            }
        }
    }
}
