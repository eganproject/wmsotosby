<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $filter;

    protected Product $busi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->filter = Product::create(['sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 5]);
        $this->busi = Product::create(['sku' => 'BSI-IRIDIUM', 'name' => 'Busi Iridium', 'unit' => 'pcs', 'min_stock' => 10]);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_adjustment_pages_are_rendered(): void
    {
        $adjustment = $this->makeAdjustment([[$this->filter, 10, 8]]);

        foreach ([
            route('admin.adjustments.index'),
            route('admin.adjustments.create'),
            route('admin.adjustments.show', $adjustment),
            route('admin.adjustments.edit', $adjustment),
        ] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    /**
     * Penyesuaian kini satu kelompok dengan stok opname: menunya satu entri,
     * halamannya dipisah tab.
     */
    public function test_the_menu_reaches_the_adjustment_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Opname &amp; Penyesuaian', false);

        $this->actingAs($this->admin)->get(route('admin.adjustments.index'))
            ->assertOk()
            ->assertSee('Penyesuaian Stok')
            ->assertSee(route('admin.opnames.index'), false);
    }

    /* --------------------------------------------------- pembuatan ------- */

    public function test_a_draft_records_the_system_stock_at_the_time_of_counting(): void
    {
        $this->giveStock($this->filter, 20);

        $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'date' => now()->format('Y-m-d'),
            'reason' => 'Stok opname',
            'items' => [['product_id' => $this->filter->id, 'actual_quantity' => 18]],
        ])->assertSessionHasNoErrors();

        $item = StockAdjustment::latest('id')->firstOrFail()->items->first();

        // Saldo tercatat diambil server, bukan dari kiriman form.
        $this->assertSame(20, $item->system_quantity);
        $this->assertSame(18, $item->actual_quantity);
        $this->assertSame(-2, $item->difference());
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'date' => now()->format('Y-m-d'),
            'items' => [['product_id' => $this->filter->id, 'actual_quantity' => 5]],
        ])->assertSessionHasErrors('reason');
    }

    public function test_a_negative_count_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.adjustments.store'), [
            'date' => now()->format('Y-m-d'),
            'reason' => 'Stok opname',
            'items' => [['product_id' => $this->filter->id, 'actual_quantity' => -3]],
        ])->assertSessionHasErrors('items.0.actual_quantity');
    }

    /* --------------------------------------------------- penerapan ------- */

    public function test_a_shortage_reduces_the_stock(): void
    {
        $this->giveStock($this->filter, 20);

        $adjustment = $this->makeAdjustment([[$this->filter, 20, 18]]);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment))
            ->assertSessionHas('success');

        $this->assertTrue($adjustment->refresh()->isPosted());
        $this->assertSame(18, $this->filter->refresh()->stock);

        $movement = StockMovement::where('product_id', $this->filter->id)->latest('id')->firstOrFail();

        $this->assertSame('out', $movement->type);
        $this->assertSame(2, $movement->quantity);
        $this->assertSame(18, $movement->balance_after);
        $this->assertStringContainsString('Penyesuaian stok', $movement->description);
    }

    public function test_a_surplus_increases_the_stock(): void
    {
        $this->giveStock($this->filter, 5);

        $adjustment = $this->makeAdjustment([[$this->filter, 5, 9]]);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));

        $this->assertSame(9, $this->filter->refresh()->stock);

        $movement = StockMovement::where('product_id', $this->filter->id)->latest('id')->firstOrFail();

        $this->assertSame('in', $movement->type);
        $this->assertSame(4, $movement->quantity);
    }

    public function test_several_products_are_adjusted_in_one_document(): void
    {
        $this->giveStock($this->filter, 10);
        $this->giveStock($this->busi, 30);

        $adjustment = $this->makeAdjustment([
            [$this->filter, 10, 12],
            [$this->busi, 30, 25],
        ]);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));

        $this->assertSame(12, $this->filter->refresh()->stock);
        $this->assertSame(25, $this->busi->refresh()->stock);
    }

    public function test_a_line_without_a_difference_creates_no_movement(): void
    {
        $this->giveStock($this->filter, 10);
        $this->giveStock($this->busi, 10);

        $before = StockMovement::count();

        $adjustment = $this->makeAdjustment([
            [$this->filter, 10, 10],  // tidak berubah
            [$this->busi, 10, 7],
        ]);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));

        // Hanya satu pergerakan baru, untuk baris yang benar-benar berselisih.
        $this->assertSame($before + 1, StockMovement::count());
        $this->assertSame(10, $this->filter->refresh()->stock);
    }

    public function test_a_document_without_any_difference_can_not_be_applied(): void
    {
        $this->giveStock($this->filter, 10);

        $adjustment = $this->makeAdjustment([[$this->filter, 10, 10]]);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment))
            ->assertSessionHas('error');

        $this->assertFalse($adjustment->refresh()->isPosted());
    }

    public function test_the_final_stock_matches_the_count_even_if_stock_moved_meanwhile(): void
    {
        $this->giveStock($this->filter, 20);

        // Dihitung saat saldo 20, hasilnya 18.
        $adjustment = $this->makeAdjustment([[$this->filter, 20, 18]]);

        // Sebelum disetujui, ada barang masuk lagi.
        $this->giveStock($this->filter, 5);
        $this->assertSame(25, $this->filter->refresh()->stock);

        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));

        // Stok akhir tetap sama dengan hasil hitung fisik.
        $this->assertSame(18, $this->filter->refresh()->stock);

        $item = $adjustment->refresh()->items->first();

        // Selisih yang dibukukan berbeda dari yang tertulis, dan itu tercatat.
        $this->assertSame(-7, $item->applied_difference);
        $this->assertSame(-2, $item->difference());
        $this->assertTrue($item->wasAppliedDifferently());
    }

    public function test_stock_never_goes_negative(): void
    {
        $adjustment = $this->makeAdjustment([[$this->filter, 0, 0]]);
        $adjustment->items()->update(['system_quantity' => 5, 'actual_quantity' => 3]);

        // Stok sebenarnya 0, jadi penyesuaian ke 3 justru menambah — aman.
        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment->refresh()));

        $this->assertSame(3, $this->filter->refresh()->stock);
    }

    /* --------------------------------------------------- persetujuan ----- */

    public function test_staff_submission_waits_for_approval(): void
    {
        $this->giveStock($this->filter, 10);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $adjustment = $this->makeAdjustment([[$this->filter, 10, 8]]);

        $this->actingAs($staff)->post(route('admin.adjustments.submit', $adjustment))
            ->assertSessionHas('success');

        $this->assertTrue($adjustment->refresh()->isPending());
        $this->assertSame(10, $this->filter->refresh()->stock);
    }

    public function test_the_approval_inbox_lists_pending_adjustments(): void
    {
        $this->giveStock($this->filter, 10);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);
        $adjustment = $this->makeAdjustment([[$this->filter, 10, 8]]);

        $this->actingAs($staff)->post(route('admin.adjustments.submit', $adjustment));

        $this->actingAs($this->admin)->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertSee('Penyesuaian Stok')
            ->assertSee($adjustment->code);

        $this->actingAs($this->admin)
            ->post(route('admin.approvals.approve', ['adjustment', $adjustment->id]))
            ->assertSessionHas('success');

        $this->assertSame(8, $this->filter->refresh()->stock);
    }

    public function test_a_pending_adjustment_can_not_be_edited_or_deleted(): void
    {
        $this->giveStock($this->filter, 10);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);
        $adjustment = $this->makeAdjustment([[$this->filter, 10, 8]]);

        $this->actingAs($staff)->post(route('admin.adjustments.submit', $adjustment));

        $this->actingAs($this->admin)->get(route('admin.adjustments.edit', $adjustment))
            ->assertRedirect(route('admin.adjustments.show', $adjustment));

        $this->actingAs($this->admin)->delete(route('admin.adjustments.destroy', $adjustment))
            ->assertSessionHas('error');
    }

    public function test_an_applied_adjustment_offers_no_cancel_button(): void
    {
        $this->giveStock($this->filter, 10);

        $adjustment = $this->makeAdjustment([[$this->filter, 10, 8]]);
        $this->actingAs($this->admin)->post(route('admin.adjustments.submit', $adjustment));

        $this->actingAs($this->admin)->get(route('admin.adjustments.show', $adjustment))
            ->assertOk()
            ->assertDontSee('Batalkan');
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<int, array{0: Product, 1: int, 2: int}>  $lines
     */
    protected function makeAdjustment(array $lines): StockAdjustment
    {
        $adjustment = StockAdjustment::create([
            'code' => StockAdjustment::nextCode(),
            'date' => now(),
            'reason' => 'Stok opname',
            'status' => StockAdjustment::STATUS_DRAFT,
        ]);

        foreach ($lines as [$product, $system, $actual]) {
            $adjustment->items()->create([
                'product_id' => $product->id,
                'system_quantity' => $system,
                'actual_quantity' => $actual,
            ]);
        }

        return $adjustment->load('items.product');
    }

    protected function giveStock(Product $product, int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
