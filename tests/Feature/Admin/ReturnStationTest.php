<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\Role;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stasiun retur: scan resi, periksa kondisi, terima, lalu siap untuk resi
 * berikutnya — tanpa berpindah halaman.
 */
class ReturnStationTest extends TestCase
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

        $this->filter = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs',
        ]);

        $this->busi = Product::create([
            'sku' => 'BSI-IRIDIUM', 'barcode' => '8991234500073',
            'name' => 'Busi Iridium', 'unit' => 'pcs',
        ]);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_station_page_has_no_document_form(): void
    {
        $this->actingAs($this->admin)->get(route('admin.returns.marketplace'))
            ->assertOk()
            ->assertSee('Stasiun Retur')
            // Rail langkah selalu terlihat supaya alurnya jelas.
            ->assertSee('Periksa')
            ->assertSee('Terima')
            ->assertSee('Lanjut otomatis ke resi berikutnya')
            ->assertSee('Belum ada paket aktif')
            ->assertDontSee('name="sender"', false)
            ->assertDontSee('name="items[0][product_id]"', false);
    }

    public function test_the_return_list_links_to_the_station(): void
    {
        $this->actingAs($this->admin)->get(route('admin.returns.index'))
            ->assertOk()
            ->assertSee(route('admin.returns.marketplace'), false)
            ->assertSee('Scan Retur Marketplace');
    }

    /* --------------------------------------------------- scan resi ------- */

    public function test_scanning_a_waybill_builds_the_document_from_the_import(): void
    {
        $this->importOrder('SPXRET1', [['FLT-OLI-STD', 2], ['BSI-IRIDIUM', 1]], buyer: 'Andi Pratama');

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1']);

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('return.code', $return->code)
            ->assertJsonPath('return.sender', 'Andi Pratama')
            ->assertJsonPath('return.order_number', 'INV-SPXRET1')
            ->assertJsonPath('items.0.sku', 'FLT-OLI-STD')
            ->assertJsonPath('items.0.good', 2)
            ->assertJsonPath('urls.finish', route('admin.returns.marketplace.finish', $return));

        // Resi baru saja discan, jadi verifikasinya langsung tercatat.
        $this->assertTrue($return->isResiVerified());
        $this->assertSame(3, $return->totalQuantity());
    }

    public function test_an_unknown_waybill_switches_to_manual_entry(): void
    {
        // Resi yang belum diimport bukan kegagalan: operator mengisi sendiri.
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'TIDAKADA'])
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('tracking_number', 'TIDAKADA');

        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_items_can_be_looked_up_by_barcode_or_sku(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.lookup'), ['code' => 'FLT-OLI-STD'])
            ->assertOk()
            ->assertJsonPath('product.sku', 'FLT-OLI-STD')
            ->assertJsonPath('product.name', 'Filter Oli Standar');

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.lookup'), ['code' => 'TIDAKADA'])
            ->assertStatus(422);
    }

    public function test_the_first_scanned_item_creates_the_document_straight_away(): void
    {
        // Tidak ada tahap perantara: satu scan sudah menghasilkan dokumen
        // lengkap dengan endpoint lanjutannya.
        $first = $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.manual'), [
            'tracking_number' => 'TANPAIMPORT1',
            'code' => 'FLT-OLI-STD',
        ])->assertOk()->assertJsonPath('found', true);

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->assertSame('TANPAIMPORT1', $return->tracking_number);
        $this->assertSame(1, $return->totalQuantity());
        $this->assertTrue($return->isResiVerified());

        $first->assertJsonPath('scanned.sku', 'FLT-OLI-STD')
            ->assertJsonPath('scanned.quantity', 1)
            ->assertJsonPath('urls.item', route('admin.returns.marketplace.item', $return));
    }

    public function test_further_scans_add_to_the_same_document(): void
    {
        $first = $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.manual'), [
            'tracking_number' => 'TANPAIMPORT1',
            'code' => 'FLT-OLI-STD',
        ]);

        $itemUrl = $first->json('urls.item');

        // Barang lain menambah baris baru.
        $this->actingAs($this->admin)->postJson($itemUrl, ['code' => 'BSI-IRIDIUM'])
            ->assertOk()
            ->assertJsonPath('scanned.sku', 'BSI-IRIDIUM')
            ->assertJsonPath('scanned.quantity', 1);

        // Barang yang sama menambah jumlahnya, bukan barisnya.
        $this->actingAs($this->admin)->postJson($itemUrl, ['code' => '8991234500035'])
            ->assertOk()
            ->assertJsonPath('scanned.quantity', 2);

        $this->assertDatabaseCount('return_receipts', 1);

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->assertCount(2, $return->items);
        $this->assertSame(3, $return->totalQuantity());
    }

    public function test_a_manual_return_can_be_received(): void
    {
        $first = $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.manual'), [
            'tracking_number' => 'TANPAIMPORT1',
            'code' => 'FLT-OLI-STD',
        ]);

        $this->actingAs($this->admin)->postJson($first->json('urls.item'), ['code' => 'FLT-OLI-STD']);

        $this->actingAs($this->admin)
            ->postJson($first->json('urls.finish'), ['items' => []])
            ->assertOk()
            ->assertJsonPath('good', 2);

        $this->assertSame(2, $this->filter->refresh()->stock);
    }

    public function test_a_wrongly_scanned_line_can_be_removed(): void
    {
        $first = $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.manual'), [
            'tracking_number' => 'TANPAIMPORT1',
            'code' => 'FLT-OLI-STD',
        ]);

        $added = $this->actingAs($this->admin)->postJson($first->json('urls.item'), ['code' => 'BSI-IRIDIUM']);

        $wrong = collect($added->json('items'))->firstWhere('sku', 'BSI-IRIDIUM');

        $this->actingAs($this->admin)->deleteJson($wrong['remove_url'])
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $this->assertSame(1, ReturnReceipt::latest('id')->firstOrFail()->totalQuantity());
    }

    public function test_an_unknown_code_can_not_start_a_manual_return(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.manual'), ['tracking_number' => 'X1', 'code' => 'TIDAKADA'])
            ->assertStatus(422);

        $this->assertDatabaseCount('return_receipts', 0);
    }

    public function test_a_manual_return_refuses_a_waybill_already_used(): void
    {
        $this->startPackage('SPXRET1', [['FLT-OLI-STD', 1]]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.manual'), [
                'tracking_number' => 'SPXRET1',
                'code' => 'FLT-OLI-STD',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Nomor resi ini sudah dipakai dokumen retur lain.');
    }

    /* --------------------------------------------------- barang hilang --- */

    public function test_missing_units_are_detected_from_the_difference(): void
    {
        // Resi menyebut 5, hasil periksa 3 bagus dan 1 rusak, berarti 1 hilang.
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 5]]);

        $itemId = $start->json('items.0.id');

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), [
                'items' => [$itemId => ['good' => 3, 'damaged' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('good', 3)
            ->assertJsonPath('damaged', 1)
            ->assertJsonPath('missing', 1);

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->assertSame(1, $return->missingQuantity());
        $this->assertTrue($return->hasMissing());

        // Hanya yang layak jual masuk stok.
        $this->assertSame(3, $this->filter->refresh()->stock);
    }

    public function test_a_fully_missing_line_adds_nothing_to_stock(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), [
                'items' => [$start->json('items.0.id') => ['good' => 0, 'damaged' => 0]],
            ])
            ->assertOk()
            ->assertJsonPath('missing', 2);

        $this->assertSame(0, $this->filter->refresh()->stock);
    }

    public function test_an_inspection_beyond_the_waybill_quantity_is_refused(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), [
                'items' => [$start->json('items.0.id') => ['good' => 2, 'damaged' => 1]],
            ])
            ->assertStatus(422);

        $this->assertFalse(ReturnReceipt::latest('id')->firstOrFail()->isPosted());
        $this->assertSame(0, $this->filter->refresh()->stock);
    }

    public function test_the_missing_count_is_shown_on_the_document(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 5]]);

        $this->actingAs($this->admin)->postJson($start->json('urls.finish'), [
            'items' => [$start->json('items.0.id') => ['good' => 3, 'damaged' => 1]],
        ]);

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.returns.show', $return))
            ->assertOk()
            ->assertSee('1 hilang')
            ->assertSee('3 layak jual')
            ->assertSee('1 rusak');
    }

    /* --------------------------------------------------- alur berantai --- */

    public function test_a_package_is_received_without_leaving_the_page(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 2]]);

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), ['items' => []])
            ->assertOk()
            ->assertJsonPath('posted', true)
            ->assertJsonPath('good', 2)
            ->assertJsonPath('damaged', 0);

        $this->assertSame(2, $this->filter->refresh()->stock);
    }

    public function test_items_marked_damaged_do_not_return_to_stock(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 2], ['BSI-IRIDIUM', 3]]);

        $items = collect($start->json('items'))->keyBy('sku');

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), [
                'reason' => 'Barang rusak',
                'items' => [
                    $items['FLT-OLI-STD']['id'] => ['good' => 2, 'damaged' => 0],
                    $items['BSI-IRIDIUM']['id'] => ['good' => 0, 'damaged' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('good', 2)
            ->assertJsonPath('damaged', 3);

        $this->assertSame(2, $this->filter->refresh()->stock);
        $this->assertSame(0, $this->busi->refresh()->stock);

        $this->assertSame('Barang rusak', ReturnReceipt::latest('id')->value('reason'));
    }

    public function test_two_waybills_can_be_received_back_to_back(): void
    {
        foreach ([['SPXRET1', 2], ['SPXRET2', 3]] as [$tracking, $quantity]) {
            $start = $this->startPackage($tracking, [['FLT-OLI-STD', $quantity]]);

            $this->actingAs($this->admin)
                ->postJson($start->json('urls.finish'), ['items' => []])
                ->assertOk();
        }

        $this->assertSame(2, ReturnReceipt::where('status', ReturnReceipt::STATUS_POSTED)->count());
        $this->assertSame(5, $this->filter->refresh()->stock);
    }

    public function test_a_receiver_without_posting_rights_submits_for_approval(): void
    {
        $this->importOrder('SPXRET1', [['FLT-OLI-STD', 1]]);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $start = $this->actingAs($staff)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1']);

        $this->actingAs($staff)->postJson($start->json('urls.finish'), ['items' => []])
            ->assertOk()
            ->assertJsonPath('posted', false);

        $this->assertTrue(ReturnReceipt::latest('id')->firstOrFail()->isPending());
        $this->assertSame(0, $this->filter->refresh()->stock);
    }

    public function test_an_already_received_waybill_is_refused(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 1]]);
        $this->actingAs($this->admin)->postJson($start->json('urls.finish'), ['items' => []])->assertOk();

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', "Resi ini sudah diproses pada dokumen retur {$return->code}.");
    }

    public function test_scanning_the_same_waybill_twice_reuses_the_draft(): void
    {
        $this->startPackage('SPXRET1', [['FLT-OLI-STD', 2]]);
        $this->actingAs($this->admin)->postJson(route('admin.returns.marketplace.store'), ['code' => 'SPXRET1']);

        $this->assertDatabaseCount('return_receipts', 1);
        $this->assertSame(2, ReturnReceipt::firstOrFail()->totalQuantity());
    }

    public function test_a_negative_inspection_value_is_rejected(): void
    {
        $start = $this->startPackage('SPXRET1', [['FLT-OLI-STD', 1]]);

        $this->actingAs($this->admin)
            ->postJson($start->json('urls.finish'), [
                'items' => [$start->json('items.0.id') => ['good' => -1, 'damaged' => 0]],
            ])
            ->assertStatus(422);

        $this->assertFalse(ReturnReceipt::latest('id')->firstOrFail()->isPosted());
    }

    public function test_the_manual_return_form_still_works(): void
    {
        $this->actingAs($this->admin)->get(route('admin.returns.create'))->assertOk();
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * @param  array<int, array{0: string, 1: int}>  $items
     */
    protected function startPackage(string $tracking, array $items)
    {
        $this->importOrder($tracking, $items);

        return $this->actingAs($this->admin)
            ->postJson(route('admin.returns.marketplace.store'), ['code' => $tracking])
            ->assertOk();
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $items
     */
    protected function importOrder(string $tracking, array $items, string $buyer = 'Pembeli'): ShipmentOrder
    {
        $import = ShipmentImport::firstOrCreate(['filename' => 'ginee.xlsx'], ['source' => 'ginee']);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'buyer_name' => $buyer,
        ]);

        foreach ($items as [$sku, $quantity]) {
            $order->items()->create([
                'sku' => $sku,
                'quantity' => $quantity,
                'product_id' => Product::where('sku', $sku)->value('id'),
            ]);
        }

        return $order->load('items.product');
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
