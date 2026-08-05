<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceScanTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Outbound $outbound;

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

        $this->giveStock(20);

        $this->outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID01234567890',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $this->outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 2]);
    }

    /* ------------------------------------------ aturan urutan scan ------- */

    public function test_item_can_not_be_scanned_before_the_waybill(): void
    {
        $this->scanItem('8991234500035')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Scan resi terlebih dahulu sebelum scan barang.');

        $this->assertSame(0, $this->outbound->items()->sum('scanned_quantity'));
    }

    public function test_wrong_waybill_is_rejected(): void
    {
        $this->scanResi('SPXID99999999999')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Resi tidak cocok dengan dokumen ini.');

        $this->assertFalse($this->outbound->refresh()->isResiVerified());
    }

    public function test_correct_waybill_unlocks_item_scanning(): void
    {
        $this->scanResi('SPXID01234567890')
            ->assertOk()
            ->assertJsonPath('progress.resi_verified', true);

        $this->assertTrue($this->outbound->refresh()->isResiVerified());
    }

    public function test_waybill_matching_ignores_case_and_spaces(): void
    {
        $this->scanResi(' spxid 0123 4567890 ')->assertOk();

        $this->assertTrue($this->outbound->refresh()->isResiVerified());
    }

    /* ------------------------------------------ scan barang -------------- */

    public function test_item_can_be_scanned_by_barcode_or_sku(): void
    {
        $this->scanResi('SPXID01234567890');

        $this->scanItem('8991234500035')->assertOk()->assertJsonPath('progress.scanned', 1);
        $this->scanItem('FLT-OLI-STD')->assertOk()->assertJsonPath('progress.scanned', 2);

        $this->assertTrue($this->outbound->refresh()->isFullyScanned());
    }

    public function test_unknown_barcode_is_rejected(): void
    {
        $this->scanResi('SPXID01234567890');

        $this->scanItem('0000000000000')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Kode 0000000000000 tidak dikenali sebagai barcode maupun SKU barang mana pun.');
    }

    public function test_scanning_more_than_ordered_is_rejected(): void
    {
        $this->scanResi('SPXID01234567890');
        $this->scanItem('8991234500035');
        $this->scanItem('8991234500035');

        $this->scanItem('8991234500035')
            ->assertStatus(422)
            ->assertJsonPath('progress', null);

        $this->assertSame(2, (int) $this->outbound->items()->sum('scanned_quantity'));
    }

    /* ------------------------------------------ pemrosesan --------------- */

    public function test_marketplace_order_can_not_be_posted_without_verification(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.submit', $this->outbound))
            ->assertSessionHas('error');

        $this->assertSame(Outbound::STATUS_DRAFT, $this->outbound->refresh()->status);
        $this->assertSame(20, $this->product->refresh()->stock);
    }

    public function test_marketplace_order_can_not_be_posted_with_partial_item_scan(): void
    {
        $this->scanResi('SPXID01234567890');
        $this->scanItem('8991234500035'); // baru 1 dari 2

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.submit', $this->outbound))
            ->assertSessionHas('error');

        $this->assertSame(Outbound::STATUS_DRAFT, $this->outbound->refresh()->status);
    }

    public function test_fully_verified_order_can_be_posted_and_reduces_stock(): void
    {
        $this->scanResi('SPXID01234567890');
        $this->scanItem('8991234500035');
        $this->scanItem('8991234500035');

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.submit', $this->outbound))
            ->assertSessionHas('success');

        $this->assertSame(Outbound::STATUS_POSTED, $this->outbound->refresh()->status);
        $this->assertSame(18, $this->product->refresh()->stock);
    }

    public function test_posted_order_can_not_be_scanned_again(): void
    {
        $this->scanResi('SPXID01234567890');
        $this->scanItem('8991234500035');
        $this->scanItem('8991234500035');
        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $this->outbound));

        $this->scanItem('8991234500035')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Dokumen sudah diproses dan tidak bisa discan lagi.');
    }

    public function test_reset_clears_the_whole_verification(): void
    {
        $this->scanResi('SPXID01234567890');
        $this->scanItem('8991234500035');

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.scan.reset', $this->outbound))
            ->assertSessionHas('success');

        $this->outbound->refresh();
        $this->assertFalse($this->outbound->isResiVerified());
        $this->assertSame(0, (int) $this->outbound->items()->sum('scanned_quantity'));
    }

    /* ------------------------------------------ pembuatan dokumen -------- */

    public function test_marketplace_order_requires_marketplace_and_waybill(): void
    {
        $this->actingAs($this->admin)->post(route('admin.outbounds.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Siti Rahma',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors(['marketplace', 'tracking_number']);
    }

    public function test_creating_a_marketplace_order_redirects_to_the_scan_page(): void
    {
        $this->actingAs($this->admin)->post(route('admin.outbounds.store'), [
            'date' => now()->format('Y-m-d'),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Siti Rahma',
            'marketplace' => 'Tokopedia',
            'tracking_number' => 'JX9988776655',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertRedirect(route('admin.outbounds.scan', Outbound::latest('id')->first()));
    }

    public function test_scan_page_is_not_available_for_regular_shipments(): void
    {
        $regular = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Jaya',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)->get(route('admin.outbounds.scan', $regular))
            ->assertRedirect(route('admin.outbounds.show', $regular));
    }

    /* ------------------------------------------------ helpers ------------ */

    protected function scanResi(string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.resi', $this->outbound), ['code' => $code]);
    }

    protected function scanItem(string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.item', $this->outbound), ['code' => $code]);
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
