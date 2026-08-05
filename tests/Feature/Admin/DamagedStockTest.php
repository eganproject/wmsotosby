<?php

namespace Tests\Feature\Admin;

use App\Models\DamagedDisposal;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saldo barang rusak.
 *
 * Unit rusak punya saldonya sendiri: masuk dari penerimaan retur, keluar
 * hanya lewat dokumen penanganan. Tidak ada jalan lain, sehingga tidak ada
 * barang rusak yang menguap tanpa jejak.
 */
class DamagedStockTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    /* --------------------------------------------------- masuk ----------- */

    public function test_damaged_returns_land_in_their_own_balance(): void
    {
        $this->receiveReturn(good: 3, damaged: 2);

        $this->product->refresh();

        $this->assertSame(3, $this->product->stock);
        $this->assertSame(2, $this->product->damaged_stock);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'bucket' => StockMovement::BUCKET_DAMAGED,
            'type' => 'in',
            'quantity' => 2,
            'balance_after' => 2,
        ]);
    }

    public function test_the_two_balances_never_mix(): void
    {
        $this->receiveReturn(good: 3, damaged: 2);
        $this->receiveReturn(good: 1, damaged: 4);

        $this->product->refresh();

        $this->assertSame(4, $this->product->stock);
        $this->assertSame(6, $this->product->damaged_stock);
    }

    /* --------------------------------------------------- keluar ---------- */

    public function test_discarding_removes_the_units_for_good(): void
    {
        $this->receiveReturn(good: 0, damaged: 5);

        $disposal = $this->makeDisposal(DamagedDisposal::ACTION_DISCARD, 3);

        $this->actingAs($this->admin)->post(route('admin.disposals.submit', $disposal))
            ->assertSessionHas('success');

        $this->product->refresh();

        $this->assertTrue($disposal->refresh()->isPosted());
        $this->assertSame(2, $this->product->damaged_stock);
        $this->assertSame(0, $this->product->stock);
    }

    public function test_repairing_moves_the_units_back_to_sellable_stock(): void
    {
        $this->receiveReturn(good: 0, damaged: 5);

        $disposal = $this->makeDisposal(DamagedDisposal::ACTION_REPAIR, 4);

        $this->actingAs($this->admin)->post(route('admin.disposals.submit', $disposal));

        $this->product->refresh();

        $this->assertSame(1, $this->product->damaged_stock);
        $this->assertSame(4, $this->product->stock);

        // Dua pergerakan pada dokumen yang sama: keluar dari rusak, masuk ke layak jual.
        $this->assertSame(2, StockMovement::where('reference_type', DamagedDisposal::class)->count());
    }

    public function test_handling_more_than_the_damaged_balance_is_refused(): void
    {
        $this->receiveReturn(good: 0, damaged: 2);

        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'action' => DamagedDisposal::ACTION_DISCARD,
            'quantities' => [$this->product->id => 5],
        ])->assertSessionHasErrors('quantities');

        $this->assertDatabaseCount('damaged_disposals', 0);
        $this->assertSame(2, $this->product->refresh()->damaged_stock);
    }

    public function test_a_document_without_any_quantity_is_refused(): void
    {
        $this->receiveReturn(good: 0, damaged: 2);

        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'action' => DamagedDisposal::ACTION_DISCARD,
            'quantities' => [$this->product->id => 0],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('damaged_disposals', 0);
    }

    /* --------------------------------------------------- alur & halaman -- */

    public function test_a_staff_submission_waits_for_approval(): void
    {
        $this->receiveReturn(good: 0, damaged: 3);

        $disposal = $this->makeDisposal(DamagedDisposal::ACTION_DISCARD, 3);
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $this->actingAs($staff)->post(route('admin.disposals.submit', $disposal))
            ->assertSessionHas('success');

        $this->assertTrue($disposal->refresh()->isPending());
        // Stok rusak belum berkurang sebelum disetujui.
        $this->assertSame(3, $this->product->refresh()->damaged_stock);

        $this->actingAs($this->admin)
            ->post(route('admin.approvals.approve', ['disposal', $disposal->id]))
            ->assertSessionHas('success');

        $this->assertSame(0, $this->product->refresh()->damaged_stock);
    }

    public function test_the_pages_render_and_show_the_balance(): void
    {
        $this->receiveReturn(good: 1, damaged: 4);

        $this->actingAs($this->admin)->get(route('admin.disposals.index'))
            ->assertOk()
            ->assertSee('Saldo Barang Rusak')
            ->assertSee('FLT-OLI-STD')
            ->assertSee('Unit Rusak');

        $this->actingAs($this->admin)->get(route('admin.disposals.create'))
            ->assertOk()
            ->assertSee('Dibuang / dimusnahkan')
            ->assertSee('Diperbaiki jadi layak jual');

        $this->actingAs($this->admin)->get(route('admin.products.show', $this->product))
            ->assertOk()
            ->assertSee('4 pcs rusak');
    }

    public function test_the_movement_page_can_show_the_damaged_ledger_only(): void
    {
        $this->receiveReturn(good: 3, damaged: 2);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.movements.index', ['bucket' => 'damaged']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('movements')->total());
        $this->assertSame(2, $response->viewData('summary')['incoming']);
    }

    /* --------------------------------------------------- helpers --------- */

    protected function receiveReturn(int $good, int $damaged): ReturnReceipt
    {
        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(),
            'date' => now(),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Pembeli',
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);

        $return->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $good + $damaged,
            'good_quantity' => $good,
            'damaged_quantity' => $damaged,
        ]);

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return));

        return $return;
    }

    protected function makeDisposal(string $action, int $quantity): DamagedDisposal
    {
        $this->actingAs($this->admin)->post(route('admin.disposals.store'), [
            'date' => now()->toDateString(),
            'action' => $action,
            'quantities' => [$this->product->id => $quantity],
        ])->assertRedirect();

        return DamagedDisposal::latest('id')->firstOrFail();
    }
}
