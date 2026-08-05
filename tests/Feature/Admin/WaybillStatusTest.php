<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Status resi: tiga tahap yang dihitung dari dokumen gudang, bukan status
 * yang diketik manual.
 */
class WaybillStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected ShipmentImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $this->import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'source' => 'ginee']);
    }

    /* --------------------------------------------------- tahapan --------- */

    public function test_a_waybill_without_a_document_is_awaiting_qc(): void
    {
        $order = $this->importOrder('SPXID111', 2);

        $this->assertSame(ShipmentOrder::STAGE_AWAITING_QC, $order->stage());
        $this->assertSame('Belum QC', $order->stageLabel());
        $this->assertSame('Belum discan sama sekali', $order->stageDetail());
    }

    public function test_a_half_scanned_waybill_is_still_awaiting_qc(): void
    {
        $order = $this->importOrder('SPXID111', 3);
        $this->makeDocument($order, quantity: 3, scanned: 1);

        $order = $this->fresh($order);

        $this->assertSame(ShipmentOrder::STAGE_AWAITING_QC, $order->stage());
        $this->assertSame('Barang 1/3 unit discan', $order->stageDetail());
    }

    public function test_a_document_without_a_scanned_waybill_is_awaiting_qc(): void
    {
        $order = $this->importOrder('SPXID111', 2);
        $this->makeDocument($order, quantity: 2, scanned: 0, resiVerified: false);

        $order = $this->fresh($order);

        $this->assertSame(ShipmentOrder::STAGE_AWAITING_QC, $order->stage());
        $this->assertSame('Dokumen dibuat, resi belum discan', $order->stageDetail());
    }

    public function test_a_fully_scanned_waybill_is_ready_to_ship(): void
    {
        $order = $this->importOrder('SPXID111', 2);
        $this->makeDocument($order, quantity: 2, scanned: 2);

        $order = $this->fresh($order);

        $this->assertSame(ShipmentOrder::STAGE_CHECKED, $order->stage());
        $this->assertSame('Siap Dikirim', $order->stageLabel());
        $this->assertSame('QC selesai, menunggu diproses', $order->stageDetail());
    }

    public function test_a_posted_document_marks_the_waybill_as_shipped(): void
    {
        $order = $this->importOrder('SPXID111', 2);
        $outbound = $this->makeDocument($order, quantity: 2, scanned: 2);

        $outbound->forceFill(['status' => Outbound::STATUS_POSTED, 'posted_at' => now()])->save();

        $order = $this->fresh($order);

        $this->assertSame(ShipmentOrder::STAGE_SHIPPED, $order->stage());
        $this->assertSame('Dikirim', $order->stageLabel());
        $this->assertStringStartsWith('Dikirim ', $order->stageDetail());
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_page_counts_every_stage(): void
    {
        $this->importOrder('SPXID111', 2);

        $checked = $this->importOrder('SPXID222', 2);
        $this->makeDocument($checked, quantity: 2, scanned: 2);

        $shipped = $this->importOrder('SPXID333', 1);
        $this->makeDocument($shipped, quantity: 1, scanned: 1)
            ->forceFill(['status' => Outbound::STATUS_POSTED, 'posted_at' => now()])->save();

        $response = $this->actingAs($this->admin)->get(route('admin.imports.status'))->assertOk();

        $counts = $response->viewData('counts');

        $this->assertSame(1, $counts[ShipmentOrder::STAGE_AWAITING_QC]);
        $this->assertSame(1, $counts[ShipmentOrder::STAGE_CHECKED]);
        $this->assertSame(1, $counts[ShipmentOrder::STAGE_SHIPPED]);

        $response->assertSee('Belum QC')
            ->assertSee('Siap Dikirim')
            ->assertSee('Dikirim')
            ->assertSee('SPXID111')
            ->assertSee('SPXID222')
            ->assertSee('SPXID333');
    }

    public function test_the_list_can_be_narrowed_to_one_stage(): void
    {
        $this->importOrder('SPXID111', 2);

        $checked = $this->importOrder('SPXID222', 2);
        $this->makeDocument($checked, quantity: 2, scanned: 2);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.imports.status', ['stage' => ShipmentOrder::STAGE_CHECKED]))
            ->assertOk();

        $this->assertSame(1, $response->viewData('orders')->total());
        $response->assertSee('SPXID222')->assertDontSee('SPXID111');
    }

    public function test_the_stage_filter_survives_a_search(): void
    {
        $checked = $this->importOrder('SPXID222', 2);
        $this->makeDocument($checked, quantity: 2, scanned: 2);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.imports.status', ['stage' => ShipmentOrder::STAGE_CHECKED, 'search' => 'SPXID222']))
            ->assertOk();

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertSame(ShipmentOrder::STAGE_CHECKED, $response->viewData('stage'));
    }

    public function test_an_unmatched_sku_is_reported_on_the_row(): void
    {
        $import = $this->import;
        $order = $import->orders()->create([
            'tracking_number' => 'SPXID999', 'order_number' => 'INV-999', 'marketplace' => 'Shopee',
        ]);
        $order->items()->create(['sku' => 'SKU-ASING', 'quantity' => 1, 'product_id' => null]);

        $this->actingAs($this->admin)->get(route('admin.imports.status'))
            ->assertOk()
            ->assertSee('SKU belum lengkap di master barang');
    }

    public function test_the_dashboard_shows_the_same_three_stages(): void
    {
        $this->importOrder('SPXID111', 2);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('waybills')[ShipmentOrder::STAGE_AWAITING_QC]);

        $response->assertSee('Status Resi')
            ->assertSee('Belum QC')
            ->assertSee(route('admin.imports.status'));
    }

    public function test_the_page_needs_the_import_permission(): void
    {
        $role = \App\Models\Role::create(['name' => 'Tanpa Resi', 'slug' => 'tanpa-resi']);
        $role->permissions()->sync(\App\Models\Permission::where('slug', 'dashboard.view')->pluck('id'));

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.imports.status'))->assertForbidden();
    }

    /* --------------------------------------------------- helpers --------- */

    protected function importOrder(string $tracking, int $quantity): ShipmentOrder
    {
        $order = $this->import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'courier' => 'SPX Standard',
        ]);

        $order->items()->create([
            'sku' => $this->product->sku,
            'quantity' => $quantity,
            'product_id' => $this->product->id,
        ]);

        return $order->load('items');
    }

    protected function makeDocument(ShipmentOrder $order, int $quantity, int $scanned, bool $resiVerified = true): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => 'Shopee',
            'recipient' => 'Pembeli marketplace',
            'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => $resiVerified ? now() : null,
        ]);

        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'scanned_quantity' => $scanned,
        ]);

        return $outbound;
    }

    /**
     * Muat ulang seperti yang dilakukan halaman, lengkap dengan sum-nya.
     */
    protected function fresh(ShipmentOrder $order): ShipmentOrder
    {
        return ShipmentOrder::with([
            'items',
            'outbound' => fn ($query) => $query
                ->withSum('items', 'quantity')
                ->withSum('items', 'scanned_quantity'),
        ])->findOrFail($order->id);
    }
}
