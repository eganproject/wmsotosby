<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnReceiptTest extends TestCase
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
            'sku' => 'FLT-OLI-STD',
            'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar',
            'unit' => 'pcs',
            'min_stock' => 5,
        ]);
    }

    public function test_return_pages_are_rendered(): void
    {
        $return = $this->makeReturn(marketplace: true);

        $pages = [
            route('admin.returns.index'),
            route('admin.returns.create'),
            route('admin.returns.create', ['type' => 'marketplace']),
            route('admin.returns.show', $return),
            route('admin.returns.edit', $return),
            route('admin.returns.scan', $return),
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    /* ---------------------------------------------- pilihan jenis retur -- */

    public function test_marketplace_return_requires_marketplace_and_waybill(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => ReturnReceipt::TYPE_MARKETPLACE,
            'sender' => 'Andi Pratama',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'damaged_quantity' => 0]],
        ])->assertSessionHasErrors(['marketplace', 'tracking_number']);
    }

    public function test_non_marketplace_return_can_be_created_without_a_waybill(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Bengkel Jaya',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'damaged_quantity' => 0]],
        ])->assertSessionHasNoErrors();

        $return = ReturnReceipt::latest('id')->firstOrFail();

        $this->assertFalse($return->requiresResiScan());
        $this->assertTrue($return->isReadyToPost());
    }

    public function test_marketplace_return_is_redirected_to_the_scan_page(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => ReturnReceipt::TYPE_MARKETPLACE,
            'sender' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXRET0001',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'damaged_quantity' => 0]],
        ])->assertRedirect(route('admin.returns.scan', ReturnReceipt::latest('id')->first()));
    }

    public function test_non_marketplace_return_with_a_waybill_also_requires_scanning(): void
    {
        $this->actingAs($this->admin)->post(route('admin.returns.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => ReturnReceipt::TYPE_REGULAR,
            'sender' => 'Bengkel Jaya',
            'tracking_number' => 'JNE00099887',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'damaged_quantity' => 0]],
        ])->assertRedirect(route('admin.returns.scan', ReturnReceipt::latest('id')->first()));

        $this->assertTrue(ReturnReceipt::latest('id')->first()->requiresResiScan());
    }

    /* ---------------------------------------------- scan resi ------------ */

    public function test_wrong_waybill_is_rejected(): void
    {
        $return = $this->makeReturn(marketplace: true);

        $this->scanResi($return, 'SPXRET9999')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Resi tidak cocok dengan dokumen retur ini.');

        $this->assertFalse($return->refresh()->isResiVerified());
    }

    public function test_correct_waybill_is_accepted_ignoring_case_and_spaces(): void
    {
        $return = $this->makeReturn(marketplace: true);

        $this->scanResi($return, ' spx ret 0001 ')
            ->assertOk()
            ->assertJsonPath('progress.resi_verified', true);

        $this->assertTrue($return->refresh()->isResiVerified());
    }

    public function test_return_can_not_be_posted_before_the_waybill_is_scanned(): void
    {
        $return = $this->makeReturn(marketplace: true);

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return))
            ->assertSessionHas('error');

        $this->assertSame(ReturnReceipt::STATUS_DRAFT, $return->refresh()->status);
        $this->assertSame(0, $this->product->refresh()->stock);
    }

    public function test_scan_page_is_not_available_without_a_waybill(): void
    {
        $return = $this->makeReturn(marketplace: false);

        $this->actingAs($this->admin)->get(route('admin.returns.scan', $return))
            ->assertRedirect(route('admin.returns.show', $return));
    }

    public function test_verification_can_be_reset(): void
    {
        $return = $this->makeReturn(marketplace: true);
        $this->scanResi($return, 'SPXRET0001');

        $this->actingAs($this->admin)->post(route('admin.returns.scan.reset', $return))
            ->assertSessionHas('success');

        $this->assertFalse($return->refresh()->isResiVerified());
    }

    /* ---------------------------------------------- stok ----------------- */

    public function test_verified_return_restores_stock(): void
    {
        $return = $this->makeReturn(marketplace: true, quantity: 4);
        $this->scanResi($return, 'SPXRET0001');

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return))
            ->assertSessionHas('success');

        $this->assertSame(ReturnReceipt::STATUS_POSTED, $return->refresh()->status);
        $this->assertSame(4, $this->product->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 4,
        ]);
    }

    public function test_damaged_items_do_not_return_to_sellable_stock(): void
    {
        $return = $this->makeReturn(marketplace: false, quantity: 3);
        $return->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'damaged_quantity' => 2,
        ]);

        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return))
            ->assertSessionHas('success');

        // Hanya 3 unit layak jual yang masuk stok, 2 unit rusak tidak.
        $this->assertSame(3, $this->product->refresh()->stock);
        $this->assertSame(5, $return->refresh()->totalQuantity());
        $this->assertSame(2, $return->damagedQuantity());
    }

    public function test_an_approved_return_can_not_be_unposted(): void
    {
        $return = $this->makeReturn(marketplace: false, quantity: 4);
        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return));

        $this->actingAs($this->admin)->post("/admin/returns/{$return->id}/unpost")
            ->assertNotFound();

        $this->assertSame(4, $this->product->refresh()->stock);
        $this->assertSame(ReturnReceipt::STATUS_POSTED, $return->refresh()->status);
    }

    public function test_posted_return_can_not_be_edited_deleted_or_scanned(): void
    {
        $return = $this->makeReturn(marketplace: true, quantity: 2);
        $this->scanResi($return, 'SPXRET0001');
        $this->actingAs($this->admin)->post(route('admin.returns.submit', $return));

        $this->actingAs($this->admin)->get(route('admin.returns.edit', $return))
            ->assertRedirect(route('admin.returns.show', $return));

        $this->actingAs($this->admin)->delete(route('admin.returns.destroy', $return))
            ->assertSessionHas('error');

        $this->scanResi($return, 'SPXRET0001')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Dokumen sudah diproses dan tidak bisa discan lagi.');
    }

    public function test_editing_a_document_resets_its_verification(): void
    {
        $return = $this->makeReturn(marketplace: true, quantity: 2);
        $this->scanResi($return, 'SPXRET0001');
        $this->assertTrue($return->refresh()->isResiVerified());

        $this->actingAs($this->admin)->put(route('admin.returns.update', $return), [
            'date' => now()->format('Y-m-d'),
            'type' => ReturnReceipt::TYPE_MARKETPLACE,
            'sender' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXRET0001',
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'damaged_quantity' => 0]],
        ])->assertRedirect(route('admin.returns.scan', $return));

        $this->assertFalse($return->refresh()->isResiVerified());
    }

    /* ---------------------------------------------- helpers -------------- */

    protected function scanResi(ReturnReceipt $return, string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.returns.scan.resi', $return), ['code' => $code]);
    }

    protected function makeReturn(bool $marketplace, int $quantity = 2): ReturnReceipt
    {
        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(),
            'date' => now(),
            'type' => $marketplace ? ReturnReceipt::TYPE_MARKETPLACE : ReturnReceipt::TYPE_REGULAR,
            'sender' => $marketplace ? 'Andi Pratama' : 'Bengkel Jaya',
            'marketplace' => $marketplace ? 'Shopee' : null,
            'tracking_number' => $marketplace ? 'SPXRET0001' : null,
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);

        $return->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'good_quantity' => $quantity,
        ]);

        return $return;
    }
}
