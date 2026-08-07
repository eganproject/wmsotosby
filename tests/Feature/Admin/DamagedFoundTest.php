<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Barang rusak yang ditemukan saat menerima kiriman dan saat menghitung rak.
 *
 * Keduanya menyelesaikan masalah yang sama: sebelumnya barang rusak hanya bisa
 * dicatat lewat retur pelanggan, sehingga temuan di gudang berakhir sebagai
 * angka yang lebih kecil tanpa penjelasan — terbaca sebagai barang hilang,
 * padahal barangnya masih ada dan mungkin bisa diklaim ke pemasok.
 */
class DamagedFoundTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar',
            'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    /* --------------------------------------------------- barang masuk ---- */

    public function test_a_delivery_that_arrives_damaged_is_split_between_the_balances(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);

        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'reference' => 'SJ-00123',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 100, 'damaged_quantity' => 7],
            ],
            'submit' => 1,
        ])->assertSessionHas('success');

        $this->product->refresh();

        $this->assertSame(93, $this->product->stock, 'Yang rusak tidak boleh masuk saldo layak jual.');
        $this->assertSame(7, $this->product->damaged_stock);
    }

    public function test_the_two_movements_name_their_own_balance(): void
    {
        $this->receive(quantity: 10, damaged: 4);

        $movements = StockMovement::where('reference_type', Inbound::class)->orderBy('id')->get();

        $this->assertCount(2, $movements);

        $this->assertSame(StockMovement::BUCKET_GOOD, $movements[0]->bucket);
        $this->assertSame(6, $movements[0]->quantity);

        $this->assertSame(StockMovement::BUCKET_DAMAGED, $movements[1]->bucket);
        $this->assertSame(4, $movements[1]->quantity);
        $this->assertStringContainsString('rusak', $movements[1]->description);
    }

    /** Seluruh kiriman rusak: tidak ada yang masuk saldo layak jual. */
    public function test_a_delivery_that_is_entirely_damaged_adds_nothing_sellable(): void
    {
        $this->receive(quantity: 5, damaged: 5);

        $this->product->refresh();

        $this->assertSame(0, $this->product->stock);
        $this->assertSame(5, $this->product->damaged_stock);
        $this->assertCount(1, StockMovement::where('reference_type', Inbound::class)->get());
    }

    public function test_more_damaged_than_received_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'damaged_quantity' => 9],
            ],
        ])->assertSessionHasErrors('items.0.damaged_quantity');

        $this->assertDatabaseCount('inbounds', 0);
    }

    /** Dokumen lama tanpa kolom rusak tetap berarti persis seperti dulu. */
    public function test_a_delivery_without_the_field_is_entirely_sellable(): void
    {
        $this->actingAs($this->admin)->post(route('admin.inbounds.store'), [
            'date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 20]],
            'submit' => 1,
        ])->assertSessionHas('success');

        $this->product->refresh();

        $this->assertSame(20, $this->product->stock);
        $this->assertSame(0, $this->product->damaged_stock);
    }

    /* --------------------------------------------------- stok opname ----- */

    /**
     * Inti perubahan ini: rak berisi 10 unit, 2 di antaranya pecah. Dulu
     * petugas hanya bisa menulis 8, dan 2 unit itu lenyap sebagai barang
     * hilang. Sekarang keduanya dicatat pada tempatnya masing-masing.
     */
    public function test_damaged_found_while_counting_goes_to_the_damaged_balance(): void
    {
        $this->receive(quantity: 10, damaged: 0);

        $opname = $this->openOpname();
        $item = $opname->items()->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 8],
            'damaged' => [$item->id => 2],
            'baseline' => [$item->id => null],
        ])->assertSessionHas('success');

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname))
            ->assertSessionHas('success');

        $this->product->refresh();

        $this->assertSame(8, $this->product->stock);
        $this->assertSame(2, $this->product->damaged_stock, 'Barangnya masih ada, hanya rusak.');

        $item->refresh();

        $this->assertSame(-2, $item->applied_difference);
        $this->assertSame(2, $item->applied_damaged);
    }

    /**
     * Temuan ditambahkan ke saldo rusak, bukan menggantikannya: barang rusak
     * lain bisa saja tersimpan di rak yang tidak ikut dihitung sesi ini.
     */
    public function test_the_finding_is_added_to_the_existing_damaged_balance(): void
    {
        $this->receive(quantity: 20, damaged: 5);
        $this->assertSame(5, $this->product->refresh()->damaged_stock);

        $opname = $this->openOpname();
        $item = $opname->items()->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 12],
            'damaged' => [$item->id => 3],
            'baseline' => [$item->id => null],
        ]);

        $this->actingAs($this->admin)->post(route('admin.opnames.submit', $opname));

        $this->product->refresh();

        $this->assertSame(12, $this->product->stock);
        $this->assertSame(8, $this->product->damaged_stock, '5 yang sudah ada ditambah 3 temuan baru.');
    }

    /** Hitungan yang dibatalkan ikut membatalkan temuan rusaknya. */
    public function test_clearing_the_count_clears_the_damaged_finding(): void
    {
        $this->receive(quantity: 10, damaged: 0);

        $opname = $this->openOpname();
        $item = $opname->items()->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 8],
            'damaged' => [$item->id => 2],
            'baseline' => [$item->id => null],
        ]);

        $this->assertSame(2, $item->refresh()->damaged_quantity);

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => null],
            'damaged' => [$item->id => 2],
            'baseline' => [$item->id => 8],
        ]);

        $item->refresh();

        $this->assertNull($item->counted_quantity);
        $this->assertSame(0, $item->damaged_quantity);
    }

    /**
     * Baris yang hanya diisi jumlah rusaknya tetap baris yang disentuh —
     * hitungan bagusnya kebetulan sama dengan saldo tercatat.
     */
    public function test_a_row_touched_only_on_the_damaged_field_is_still_saved(): void
    {
        $this->receive(quantity: 10, damaged: 0);

        $opname = $this->openOpname();
        $item = $opname->items()->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.opnames.count', $opname), [
            'counts' => [$item->id => 10],
            'damaged' => [$item->id => 3],
            'baseline' => [$item->id => null],
        ])->assertSessionHas('success');

        $this->assertSame(3, $item->refresh()->damaged_quantity);
    }

    public function test_the_counting_screen_offers_both_fields(): void
    {
        $this->receive(quantity: 10, damaged: 0);

        $opname = $this->openOpname();
        $item = $opname->items()->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.opnames.show', $opname))
            ->assertOk()
            ->assertSee('name="counts['.$item->id.']"', false)
            ->assertSee('name="damaged['.$item->id.']"', false)
            ->assertSee('Bagus')
            ->assertSee('Rusak');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function receive(int $quantity, int $damaged): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $inbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'damaged_quantity' => $damaged,
        ]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function openOpname(): StockOpname
    {
        $this->actingAs($this->admin)->post(route('admin.opnames.store'), [
            'date' => now()->toDateString(),
            'scope' => 'all',
        ])->assertSessionHas('success');

        return StockOpname::latest('id')->firstOrFail();
    }
}
