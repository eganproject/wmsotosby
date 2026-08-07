<?php

namespace Tests\Feature\Admin;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengeluarkan barang layak jual di luar penjualan.
 *
 * Sebelumnya dokumen pengeluaran hanya mengambil dari saldo rusak, sehingga
 * barang layak jual yang harus dikembalikan ke pemasok atau dibuang karena
 * kedaluwarsa tidak punya jalan keluar sama sekali — satu-satunya cara adalah
 * penyesuaian stok, yang mencatatnya sebagai koreksi angka alih-alih sebagai
 * barang yang benar-benar pergi ke suatu tempat.
 */
class GoodStockDisposalTest extends TestCase
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

        $this->giveStock(50);
    }

    /* --------------------------------------------------- ke pemasok ------ */

    public function test_good_stock_can_be_returned_to_the_supplier(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'PT Sumber Otoparts']);

        $disposal = $this->make(DamagedDisposal::ACTION_RETURN, quantity: 12, extra: [
            'supplier_id' => $supplier->id,
        ]);

        $this->process($disposal);

        $this->product->refresh();

        $this->assertSame(38, $this->product->stock);
        $this->assertSame(0, $this->product->damaged_stock, 'Barangnya pergi ke pemasok, bukan ke saldo rusak.');
        $this->assertSame($supplier->id, $disposal->refresh()->supplier_id);
    }

    public function test_expired_good_stock_can_be_discarded(): void
    {
        $disposal = $this->make(DamagedDisposal::ACTION_DISCARD, quantity: 5);

        $this->process($disposal);

        $this->assertSame(45, $this->product->refresh()->stock);
    }

    /**
     * Jejaknya harus menyebut saldo mana yang berkurang, supaya kartu stok
     * layak jual dan kartu stok rusak tetap bisa direkonstruksi terpisah.
     */
    public function test_the_movement_is_written_against_the_sellable_balance(): void
    {
        $this->process($this->make(DamagedDisposal::ACTION_DISCARD, quantity: 5));

        $movement = StockMovement::where('reference_type', DamagedDisposal::class)->latest('id')->firstOrFail();

        $this->assertSame(StockMovement::BUCKET_GOOD, $movement->bucket);
        $this->assertSame('out', $movement->type);
        $this->assertSame(45, $movement->balance_after);
        $this->assertStringContainsString('Pengeluaran barang', $movement->description);
    }

    public function test_more_than_the_sellable_balance_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'bucket' => StockMovement::BUCKET_GOOD,
            'action' => DamagedDisposal::ACTION_DISCARD,
            'quantities' => [$this->product->id => 80],
        ])->assertSessionHasErrors('quantities');

        $this->assertSame(50, $this->product->refresh()->stock);
        $this->assertDatabaseCount('damaged_disposals', 0);
    }

    /* --------------------------------------------------- pindah saldo ---- */

    /**
     * Barang yang ternyata cacat di gudang akhirnya punya jalan masuk ke saldo
     * rusak. Sebelumnya saldo rusak hanya bisa bertambah dari retur pelanggan.
     */
    public function test_good_stock_found_broken_moves_into_the_damaged_balance(): void
    {
        $disposal = $this->make(DamagedDisposal::ACTION_WRITE_OFF, quantity: 8);

        $this->assertFalse($disposal->leavesTheWarehouse());

        $this->process($disposal);

        $this->product->refresh();

        $this->assertSame(42, $this->product->stock);
        $this->assertSame(8, $this->product->damaged_stock);

        // Dua pergerakan, satu untuk tiap saldo, dalam dokumen yang sama.
        $movements = StockMovement::where('reference_type', DamagedDisposal::class)->get();

        $this->assertCount(2, $movements);
        $this->assertSame(
            [StockMovement::BUCKET_GOOD, StockMovement::BUCKET_DAMAGED],
            $movements->sortBy('id')->pluck('bucket')->all(),
        );
    }

    /** Dan dari sana ia bisa diteruskan seperti barang rusak lainnya. */
    public function test_the_moved_units_can_then_be_handled_as_damaged_stock(): void
    {
        $this->process($this->make(DamagedDisposal::ACTION_WRITE_OFF, quantity: 8));

        $disposal = $this->make(DamagedDisposal::ACTION_REPAIR, quantity: 3, bucket: StockMovement::BUCKET_DAMAGED);
        $this->process($disposal);

        $this->product->refresh();

        $this->assertSame(45, $this->product->stock, '42 tersisa ditambah 3 yang berhasil diperbaiki.');
        $this->assertSame(5, $this->product->damaged_stock);
    }

    /* --------------------------------------------------- penjagaan ------- */

    /**
     * Memperbaiki barang yang memang layak jual tidak punya arti, begitu pula
     * menandai rusak sesuatu yang sudah tercatat rusak.
     */
    public function test_an_action_that_makes_no_sense_for_the_balance_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'bucket' => StockMovement::BUCKET_GOOD,
            'action' => DamagedDisposal::ACTION_REPAIR,
            'quantities' => [$this->product->id => 3],
        ])->assertSessionHasErrors('action');

        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'bucket' => StockMovement::BUCKET_DAMAGED,
            'action' => DamagedDisposal::ACTION_WRITE_OFF,
            'quantities' => [$this->product->id => 3],
        ])->assertSessionHasErrors('action');

        $this->assertDatabaseCount('damaged_disposals', 0);
    }

    /** Penjagaan kedua, seandainya data tersimpan lewat jalur lain. */
    public function test_a_mismatched_document_can_not_be_posted(): void
    {
        $disposal = $this->make(DamagedDisposal::ACTION_DISCARD, quantity: 3);

        // Dipaksa menjadi tidak masuk akal tanpa melewati form.
        $disposal->forceFill(['action' => DamagedDisposal::ACTION_REPAIR])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.disposals.submit', $disposal))
            ->assertSessionHas('error');

        $this->assertSame(50, $this->product->refresh()->stock);
        $this->assertTrue($disposal->refresh()->isDraft());
    }

    /** Dokumen lama tanpa kolom saldo tetap dibaca sebagai saldo rusak. */
    public function test_a_document_without_a_balance_still_means_damaged(): void
    {
        $disposal = DamagedDisposal::create([
            'code' => DamagedDisposal::nextCode(), 'date' => now(),
            'action' => DamagedDisposal::ACTION_DISCARD,
            'status' => DamagedDisposal::STATUS_DRAFT,
        ]);

        $this->assertTrue($disposal->isFromDamaged());
        $this->assertSame('Stok rusak', $disposal->bucketLabel());
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_offers_both_entry_points(): void
    {
        $this->actingAs($this->admin)->get(route('admin.disposals.index'))
            ->assertOk()
            ->assertSee('Keluarkan Barang Layak Jual')
            ->assertSee('Tangani Barang Rusak');
    }

    public function test_the_form_only_offers_actions_that_fit_the_balance(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.disposals.create', ['bucket' => 'good']))
            ->assertOk()
            ->assertSee('Keluarkan Barang Layak Jual')
            ->assertSee('Dipindahkan ke stok rusak')
            ->assertDontSee('Diperbaiki jadi layak jual');

        $this->giveDamagedStock(4);

        $this->actingAs($this->admin)
            ->get(route('admin.disposals.create'))
            ->assertOk()
            ->assertSee('Diperbaiki jadi layak jual')
            ->assertDontSee('Dipindahkan ke stok rusak');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function make(string $action, int $quantity, string $bucket = StockMovement::BUCKET_GOOD, array $extra = []): DamagedDisposal
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), $extra + [
            'date' => now()->toDateString(),
            'bucket' => $bucket,
            'action' => $action,
            'quantities' => [$this->product->id => $quantity],
        ])->assertSessionHas('success');

        return DamagedDisposal::latest('id')->firstOrFail();
    }

    protected function process(DamagedDisposal $disposal): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.disposals.submit', $disposal))
            ->assertSessionHas('success');
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function giveDamagedStock(int $quantity): void
    {
        $this->process($this->make(DamagedDisposal::ACTION_WRITE_OFF, $quantity));
    }
}
