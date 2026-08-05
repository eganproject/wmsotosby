<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockFlowTest extends TestCase
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

    public function test_operational_pages_are_rendered(): void
    {
        $inbound = $this->makeInbound(10);
        $outbound = $this->makeMarketplaceOutbound(2);

        $pages = [
            route('admin.dashboard'),
            route('admin.products.index'),
            route('admin.products.create'),
            route('admin.products.edit', $this->product),
            route('admin.products.show', $this->product),
            route('admin.inbounds.index'),
            route('admin.inbounds.create'),
            route('admin.inbounds.show', $inbound),
            route('admin.inbounds.edit', $inbound),
            route('admin.outbounds.index'),
            route('admin.outbounds.create'),
            route('admin.outbounds.show', $outbound),
            route('admin.outbounds.edit', $outbound),
            route('admin.outbounds.scan', $outbound),
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }

    public function test_inbound_posting_increases_stock_and_writes_a_movement(): void
    {
        $inbound = $this->makeInbound(25);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound))
            ->assertSessionHas('success');

        $this->assertSame(25, $this->product->refresh()->stock);
        $this->assertSame(Inbound::STATUS_POSTED, $inbound->refresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 25,
            'balance_after' => 25,
        ]);
    }

    public function test_an_approved_inbound_can_not_be_unposted(): void
    {
        $inbound = $this->makeInbound(25);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));

        // Endpoint pembatalan posting sudah tidak ada — stok yang sudah masuk
        // hanya bisa dikoreksi lewat penyesuaian stok.
        $this->actingAs($this->admin)->post("/admin/inbounds/{$inbound->id}/unpost")
            ->assertNotFound();

        $this->assertSame(25, $this->product->refresh()->stock);
        $this->assertSame(Inbound::STATUS_POSTED, $inbound->refresh()->status);
    }

    public function test_posted_documents_can_not_be_edited_or_deleted(): void
    {
        $inbound = $this->makeInbound(5);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));

        $this->actingAs($this->admin)->get(route('admin.inbounds.edit', $inbound))
            ->assertRedirect(route('admin.inbounds.show', $inbound));

        $this->actingAs($this->admin)->delete(route('admin.inbounds.destroy', $inbound))
            ->assertSessionHas('error');

        $this->assertNotNull($inbound->fresh());

        // Halaman detail menjelaskan jalan keluarnya, bukan sekadar menolak.
        $this->actingAs($this->admin)->get(route('admin.inbounds.show', $inbound))
            ->assertOk()
            ->assertSee('Dokumen ini final dan tidak bisa diubah lagi.')
            ->assertSee(route('admin.adjustments.create'));
    }

    public function test_regular_outbound_reduces_stock(): void
    {
        $this->giveStock(30);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Jaya',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 12]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound))
            ->assertSessionHas('success');

        $this->assertSame(18, $this->product->refresh()->stock);
        $this->assertSame(Outbound::STATUS_POSTED, $outbound->refresh()->status);

        // Pengiriman yang sudah disetujui tidak bisa ditarik kembali.
        $this->actingAs($this->admin)->post("/admin/outbounds/{$outbound->id}/unpost")
            ->assertNotFound();

        $this->assertSame(18, $this->product->refresh()->stock);
    }

    public function test_outbound_can_not_exceed_available_stock(): void
    {
        $this->giveStock(3);

        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Jaya',
            'status' => Outbound::STATUS_DRAFT,
        ]);
        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => 10]);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $outbound))
            ->assertSessionHas('error');

        $this->assertSame(3, $this->product->refresh()->stock);
        $this->assertSame(Outbound::STATUS_DRAFT, $outbound->refresh()->status);
    }

    public function test_stock_never_goes_negative_on_concurrent_documents(): void
    {
        $this->giveStock(10);

        $first = $this->makeRegularOutbound(8);
        $second = $this->makeRegularOutbound(8);

        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $first))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('admin.outbounds.submit', $second))->assertSessionHas('error');

        $this->assertSame(2, $this->product->refresh()->stock);
    }

    /* ------------------------------------------------ helpers ------------ */

    protected function giveStock(int $quantity): void
    {
        $inbound = $this->makeInbound($quantity);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    protected function makeInbound(int $quantity): Inbound
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => now(),
            
            'status' => Inbound::STATUS_DRAFT,
        ]);

        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        return $inbound;
    }

    protected function makeRegularOutbound(int $quantity): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_REGULAR,
            'recipient' => 'Bengkel Jaya',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        return $outbound;
    }

    protected function makeMarketplaceOutbound(int $quantity): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID01234567890',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $outbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        return $outbound;
    }
}
