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

    /**
     * Filter yang sedang dipakai harus bertahan setelah memproses.
     *
     * Sebelumnya pemrosesan mengandalkan back(), yang membaca header Referer —
     * tidak selalu ada, dan cadangan sesinya tidak pernah diperbarui pada
     * navigasi AJAX. Akibatnya operator yang menyaring satu marketplace
     * terlempar ke seluruh antrean dan harus menyaring ulang.
     */
    public function test_the_active_filter_survives_processing(): void
    {
        $first = $this->makePackage('SPXID111', 2, scanned: 2);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), [
                'ids' => [$first->id],
                'search' => 'SPXID',
                'marketplace' => 'Shopee',
            ])
            ->assertRedirect(route('admin.outbounds.ready', [
                'search' => 'SPXID',
                'marketplace' => 'Shopee',
            ]));
    }

    public function test_processing_without_a_filter_returns_to_the_plain_queue(): void
    {
        $outbound = $this->makePackage('SPXID111', 1, scanned: 1);

        $this->actingAs($this->admin)
            ->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]])
            ->assertRedirect(route('admin.outbounds.ready'));
    }

    public function test_the_queue_form_carries_the_active_filter(): void
    {
        $this->makePackage('SPXID111', 2, scanned: 2);

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.ready', ['search' => 'SPXID', 'marketplace' => 'Shopee']))
            ->assertOk()
            ->assertSee('<input type="hidden" name="search" value="SPXID">', false)
            ->assertSee('<input type="hidden" name="marketplace" value="Shopee">', false);
    }

    /* --------------------------------------------------- scan resi ------- */

    public function test_scanning_a_waybill_ships_the_package(): void
    {
        $outbound = $this->makePackage('SPXID111', 3, scanned: 3);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('outbound.id', $outbound->id)
            ->assertJsonPath('outbound.units', 3)
            ->assertJsonPath('shipped', true)
            ->assertJsonPath('message', 'Dikirim · 3 unit')
            ->assertJsonPath('remaining', 0);

        $this->assertTrue($outbound->refresh()->isPosted());
        $this->assertSame(47, $this->product->refresh()->stock);
    }

    /**
     * Scanner sering menyisipkan spasi dan mengubah besar kecil huruf. Resi
     * yang sama tidak boleh dianggap resi lain karenanya.
     */
    public function test_a_waybill_is_found_despite_spacing_and_case(): void
    {
        $outbound = $this->makePackage('SPXID111', 1, scanned: 1);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => '  spx id111 '])
            ->assertOk();

        $this->assertTrue($outbound->refresh()->isPosted());
    }

    /** Label resi bisa rusak; nomor dokumen tercetak tetap bisa dipakai. */
    public function test_the_document_number_can_be_scanned_instead(): void
    {
        $outbound = $this->makePackage('SPXID111', 1, scanned: 1);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => $outbound->code])
            ->assertOk();

        $this->assertTrue($outbound->refresh()->isPosted());
    }

    /**
     * Resi yang sama discan dua kali tidak boleh mengirim dua kali — dan
     * penolakannya harus menyebutkan sebabnya, bukan sekadar gagal.
     */
    public function test_scanning_the_same_waybill_twice_is_refused(): void
    {
        $outbound = $this->makePackage('SPXID111', 2, scanned: 2);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', "Sudah dikirim · {$outbound->code}");

        // Sekali dikirim, sekali pula stok berkurang.
        $this->assertSame(48, $this->product->refresh()->stock);
    }

    public function test_a_package_that_is_still_being_scanned_is_refused(): void
    {
        $halfway = $this->makePackage('SPXID222', 5, scanned: 2);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID222'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Belum selesai QC · sisa 3 unit');

        $this->assertTrue($halfway->refresh()->isDraft());
        $this->assertSame(50, $this->product->refresh()->stock);
    }

    public function test_an_unknown_waybill_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'TIDAKADA123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Resi tidak dikenali · belum discan di packing');
    }

    public function test_a_package_awaiting_approval_is_refused(): void
    {
        $outbound = $this->makePackage('SPXID111', 2, scanned: 2);

        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        // Petugas tanpa hak menyetujui hanya mengajukan.
        $this->actingAs($staff)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertOk()
            ->assertJsonPath('shipped', false)
            ->assertJsonPath('message', 'Diajukan · menunggu persetujuan');

        $this->assertTrue($outbound->refresh()->isPending());
        $this->assertSame(50, $this->product->refresh()->stock);

        // Discan lagi, dokumennya sedang menunggu keputusan.
        $this->actingAs($staff)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', "Menunggu persetujuan · {$outbound->code}");
    }

    /**
     * Stok bisa saja sudah diambil paket lain sejak antrean disusun. Yang
     * penting: dokumennya tidak berubah status dan pesannya menyebut barangnya.
     */
    public function test_a_package_without_enough_stock_is_refused(): void
    {
        $outbound = $this->makePackage('SPXID111', 5, scanned: 5);

        $this->product->forceFill(['stock' => 2])->save();

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Stok Filter Oli Standar tidak mencukupi: butuh 5, tersedia 2 pcs.');

        $this->assertTrue($outbound->refresh()->isDraft());
        $this->assertSame(2, $this->product->refresh()->stock);
    }

    public function test_scanning_requires_the_posting_permission(): void
    {
        $this->makePackage('SPXID111', 1, scanned: 1);

        $role = \App\Models\Role::create(['name' => 'Pengamat Gudang', 'slug' => 'pengamat-gudang']);
        $role->permissions()->sync(
            \App\Models\Permission::whereIn('slug', ['dashboard.view', 'outbounds.view'])->pluck('id'),
        );

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertForbidden();
    }

    public function test_the_queue_offers_the_scan_panel(): void
    {
        $this->makePackage('SPXID111', 1, scanned: 1);

        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->assertSee('Scan resi untuk mengirim')
            ->assertSee(route('admin.outbounds.ready.scan'));
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
