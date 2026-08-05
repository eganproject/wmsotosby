<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retur yang resinya tidak ada di data import.
 *
 * Tanpa data import tidak ada pembanding, jadi barang hilang tidak mungkin
 * discan — yang bisa dilakukan operator adalah menyatakan berapa yang
 * seharusnya kembali, lalu selisihnya terhadap barang yang benar-benar ada
 * dicatat sebagai hilang.
 */
class ManualReturnTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }

    public function test_an_unknown_waybill_opens_manual_entry_instead_of_failing(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'TIDAKADA'])
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('tracking_number', 'TIDAKADA')
            ->assertJsonPath('message', 'Resi tidak ada di data import. Scan atau ketik barangnya satu per satu.');

        $this->assertDatabaseCount('return_receipts', 0);
    }

    /**
     * Inti perbaikannya: yang datang 2, tapi pembeli menjanjikan 3.
     */
    public function test_the_operator_can_declare_how_many_should_have_come_back(): void
    {
        $return = $this->openManualReturn();
        $item = $return->items->first();

        // Unit kedua discan.
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.item', $return), ['code' => 'FLT-OLI-STD'])
            ->assertOk();

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), [
            'items' => [
                $item->id => ['good' => 2, 'damaged' => 0, 'expected' => 3],
            ],
        ])->assertOk()->assertJsonPath('missing', 1);

        $return->refresh()->load('items');

        $this->assertSame(3, $return->items->first()->quantity);
        $this->assertSame(2, $return->goodQuantity());
        $this->assertSame(1, $return->missingQuantity());
        // Hanya yang layak jual yang masuk stok.
        $this->assertSame(2, $this->product->refresh()->stock);
    }

    public function test_damaged_units_are_recorded_but_never_enter_sellable_stock(): void
    {
        $return = $this->openManualReturn();
        $item = $return->items->first();

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.item', $return), ['code' => 'FLT-OLI-STD']);

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), [
            'items' => [
                $item->id => ['good' => 1, 'damaged' => 1, 'expected' => 2],
            ],
        ])->assertOk();

        $return->refresh()->load('items');

        $this->assertSame(1, $return->damagedQuantity());
        $this->assertSame(0, $return->missingQuantity());
        $this->assertSame(1, $this->product->refresh()->stock);
    }

    public function test_the_check_may_not_exceed_what_was_declared(): void
    {
        $return = $this->openManualReturn();
        $item = $return->items->first();

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), [
            'items' => [
                $item->id => ['good' => 3, 'damaged' => 1, 'expected' => 2],
            ],
        ])->assertStatus(422);

        $this->assertTrue($return->refresh()->isDraft());
    }

    /**
     * Resi hasil import tetap memakai jumlah dari marketplace; operator tidak
     * bisa menaikkannya lewat permintaan yang dibuat-buat.
     */
    public function test_an_imported_waybill_ignores_a_declared_quantity(): void
    {
        $import = \App\Models\ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);
        $order = $import->orders()->create([
            'tracking_number' => 'SPXRET1', 'order_number' => 'INV-1', 'marketplace' => 'Shopee',
        ]);
        $order->items()->create(['sku' => 'FLT-OLI-STD', 'quantity' => 2, 'product_id' => $this->product->id]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1'])
            ->assertOk()
            ->assertJsonPath('found', true);

        $return = ReturnReceipt::with('items')->latest('id')->firstOrFail();
        $item = $return->items->first();

        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.finish', $return), [
            'items' => [
                $item->id => ['good' => 2, 'damaged' => 0, 'expected' => 9],
            ],
        ])->assertOk();

        // Jumlahnya tetap 2 seperti data import, bukan 9.
        $this->assertSame(2, $return->refresh()->items->first()->quantity);
    }

    public function test_the_station_explains_the_manual_rule(): void
    {
        $this->actingAs($this->admin)->get(route('admin.returns.marketplace'))
            ->assertOk()
            ->assertSee('seharusnya')
            ->assertSee('Jumlah yang seharusnya dikembalikan pembeli');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function openManualReturn(): ReturnReceipt
    {
        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.manual'), [
            'tracking_number' => 'MANUAL-1',
            'code' => 'FLT-OLI-STD',
        ])->assertOk();

        return ReturnReceipt::with('items')->latest('id')->firstOrFail();
    }
}
