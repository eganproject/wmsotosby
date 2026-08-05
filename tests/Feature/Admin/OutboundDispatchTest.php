<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Antrean "Siap Dikirim": paket yang isinya sudah diverifikasi di stasiun
 * packing, menunggu diproses. Di sinilah stok akhirnya bergerak.
 */
class OutboundDispatchTest extends TestCase
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

        $this->giveStock(50);
    }

    /* --------------------------------------------------- halaman --------- */

    public function test_the_queue_lists_packages_that_are_fully_scanned(): void
    {
        $ready = $this->makePackage('SPXID111', 2, scanned: 2);
        $halfway = $this->makePackage('SPXID222', 3, scanned: 1);

        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->assertSee('Siap Dikirim')
            ->assertSee($ready->code)
            ->assertSee('2 unit discan')
            // Yang belum tuntas discan bukan urusan halaman ini.
            ->assertDontSee($halfway->code);
    }

    public function test_an_empty_queue_points_back_to_the_packing_station(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->assertSee('Tidak ada paket menunggu')
            ->assertSee(route('admin.outbounds.marketplace'));
    }

    /* --------------------------------------------------- pemrosesan ------ */

    public function test_selected_packages_are_shipped_together(): void
    {
        $first = $this->makePackage('SPXID111', 2, scanned: 2);
        $second = $this->makePackage('SPXID222', 3, scanned: 3);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$first->id, $second->id]])
            ->assertSessionHas('success', '2 paket dikirim. Stok sudah berkurang.');

        $this->assertTrue($first->refresh()->isPosted());
        $this->assertTrue($second->refresh()->isPosted());

        // 50 - 2 - 3
        $this->assertSame(45, $this->product->refresh()->stock);
        $this->assertSame(0, Outbound::readyToShip()->count());
    }

    public function test_a_single_package_is_named_in_the_confirmation(): void
    {
        $outbound = $this->makePackage('SPXID111', 1, scanned: 1);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertSessionHas('success', "Paket {$outbound->code} dikirim. Stok sudah berkurang.");
    }

    public function test_a_package_that_is_not_ready_is_never_processed(): void
    {
        $halfway = $this->makePackage('SPXID222', 3, scanned: 1);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$halfway->id]])
            ->assertSessionHas('error');

        $this->assertTrue($halfway->refresh()->isDraft());
        $this->assertSame(50, $this->product->refresh()->stock);
    }

    public function test_a_packer_without_approval_rights_only_submits_the_queue(): void
    {
        $outbound = $this->makePackage('SPXID111', 2, scanned: 2);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $this->actingAs($staff)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertSessionHas('success', "Paket {$outbound->code} diajukan dan menunggu persetujuan.");

        $this->assertTrue($outbound->refresh()->isPending());
        $this->assertSame(50, $this->product->refresh()->stock);
    }

    /**
     * Satu paket yang gagal tidak boleh menggagalkan sisanya — stok bisa saja
     * berubah setelah paket masuk antrean.
     */
    public function test_one_failing_package_does_not_stop_the_others(): void
    {
        $good = $this->makePackage('SPXID111', 2, scanned: 2);
        $short = $this->makePackage('SPXID222', 5, scanned: 5);

        // Stok tinggal cukup untuk paket pertama saja.
        $this->product->forceFill(['stock' => 2])->save();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$good->id, $short->id]]);

        $response->assertSessionHas('success', "Paket {$good->code} dikirim. Stok sudah berkurang.");
        $response->assertSessionHas('error');

        $this->assertTrue($good->refresh()->isPosted());
        $this->assertTrue($short->refresh()->isDraft());
        $this->assertSame(0, $this->product->refresh()->stock);
    }

    public function test_processing_requires_the_posting_permission(): void
    {
        $outbound = $this->makePackage('SPXID111', 1, scanned: 1);

        // Boleh melihat antreannya, tidak boleh memprosesnya.
        $role = \App\Models\Role::create(['name' => 'Pengamat Gudang', 'slug' => 'pengamat-gudang']);
        $role->permissions()->sync(
            \App\Models\Permission::whereIn('slug', ['dashboard.view', 'outbounds.view'])->pluck('id'),
        );

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.outbounds.ready'))->assertOk();

        $this->actingAs($viewer)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertForbidden();

        $this->assertTrue($outbound->refresh()->isDraft());
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makePackage(string $tracking, int $quantity, int $scanned): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => 'Shopee',
            'recipient' => 'Pembeli marketplace',
            'tracking_number' => $tracking,
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);

        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'scanned_quantity' => $scanned,
        ]);

        return $outbound->load('items.product');
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = \App\Models\Inbound::create([
            'code' => \App\Models\Inbound::nextCode(),
            'date' => now(),
            'status' => \App\Models\Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
