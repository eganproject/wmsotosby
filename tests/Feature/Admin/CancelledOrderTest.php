<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Pesanan yang dibatalkan pembeli.
 *
 * Pembatalan datang dari luar gudang dan bisa tiba kapan saja — sebelum
 * dipacking, di tengah QC, atau setelah paket menunggu di antrean. Yang harus
 * dijaga selalu sama: stok tidak boleh berkurang untuk pesanan yang tidak akan
 * pernah berangkat.
 */
class CancelledOrderTest extends TestCase
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

    /* --------------------------------------------------- dari import ----- */

    /**
     * Status pembatalan ditulis berbeda-beda tiap marketplace, dan permintaan
     * pembatalan yang belum disetujui pun harus menghentikan packing.
     */
    public static function cancelledStatuses(): array
    {
        return [
            'Shopee' => ['Dibatalkan'],
            'TikTok' => ['Canceled'],
            'Lazada' => ['Cancelled'],
            'permintaan pembeli' => ['Pembatalan Diminta'],
            'permintaan bahasa Inggris' => ['Cancellation Requested'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancelledStatuses')]
    public function test_the_import_reads_a_cancellation_from_the_order_status(string $status): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah', 'Status Pesanan'],
            ['SPXID111', 'FLT-OLI-STD', '2', $status],
        ])->assertSessionHas('success');

        $order = ShipmentOrder::firstOrFail();

        $this->assertTrue($order->isCancelled());
        $this->assertFalse($order->isCancelledByHand(), 'Batal dari berkas tidak boleh tercatat atas nama siapa pun.');
        $this->assertSame(ShipmentOrder::STAGE_CANCELLED, $order->stage());
    }

    public function test_an_ordinary_status_is_left_alone(): void
    {
        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah', 'Status Pesanan'],
            ['SPXID111', 'FLT-OLI-STD', '2', 'Siap Dikirim'],
        ])->assertSessionHas('success');

        $this->assertFalse(ShipmentOrder::firstOrFail()->isCancelled());
    }

    /**
     * Petugas biasanya tahu dari aplikasi marketplace lebih dulu daripada
     * berkas yang diekspor belakangan, jadi import tidak boleh mencabut
     * pembatalan yang sudah ditandai orang.
     */
    public function test_a_later_import_never_undoes_a_manual_cancellation(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.imports.orders.cancel', $order), [
            'cancellation_reason' => 'Pembeli chat minta batal.',
        ])->assertSessionHas('success');

        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah', 'Status Pesanan'],
            ['SPXID111', 'FLT-OLI-STD', '2', 'Siap Dikirim'],
        ])->assertSessionHas('success');

        $order = ShipmentOrder::firstOrFail();

        $this->assertTrue($order->isCancelled());
        $this->assertSame('Pembeli chat minta batal.', $order->cancellation_reason);
    }

    /**
     * Import ulang dulu menghapus baris resi lalu membuatnya dari nol, dan
     * kolom shipment_order_id pada dokumen diatur nullOnDelete — sehingga
     * dokumen yang sudah dikirim diam-diam kehilangan tautannya lalu muncul
     * lagi sebagai "Belum QC" padahal stoknya sudah berkurang.
     */
    public function test_reimporting_keeps_documents_attached_to_their_waybill(): void
    {
        $order = $this->makeOrder();
        $outbound = $this->makePackage($order, scanned: 2);

        $this->uploadCsv([
            ['Nomor Resi', 'SKU', 'Jumlah', 'Status Pesanan'],
            ['SPXID111', 'FLT-OLI-STD', '2', 'Siap Dikirim'],
        ])->assertSessionHas('success');

        $this->assertSame($order->id, ShipmentOrder::firstOrFail()->id, 'Baris resi harus diperbarui, bukan dibuat ulang.');
        $this->assertSame($order->id, $outbound->refresh()->shipment_order_id);
        $this->assertSame(ShipmentOrder::STAGE_CHECKED, ShipmentOrder::firstOrFail()->stage());
    }

    /* --------------------------------------------------- blokir ---------- */

    public function test_a_cancelled_waybill_can_not_be_opened_at_the_packing_station(): void
    {
        $this->cancel($this->makeOrder());

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Pesanan dibatalkan pembeli · kembalikan barang ke rak');

        $this->assertSame(0, Outbound::count(), 'Dokumennya tidak boleh terbentuk sama sekali.');
    }

    /**
     * Pembatalan bisa tiba setelah paket selesai di-QC, jadi menjaga stasiun
     * packing saja tidak cukup.
     */
    public function test_a_cancelled_package_can_not_be_dispatched_by_scanning(): void
    {
        $order = $this->makeOrder();
        $outbound = $this->makePackage($order, scanned: 2);
        $this->cancel($order);

        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.ready.scan'), ['code' => 'SPXID111'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.code.0',
                "Pesanan dibatalkan pembeli · kembalikan barang, hapus {$outbound->code}",
            );

        $this->assertTrue($outbound->refresh()->isDraft());
        $this->assertSame(50, $this->product->refresh()->stock);
    }

    /**
     * Mencentang seluruh halaman lalu menekan proses adalah cara termudah
     * mengirim paket batal tanpa sengaja — dan satu paket batal tidak boleh
     * ikut menggagalkan paket sah di sebelahnya.
     */
    public function test_a_cancelled_package_survives_the_bulk_button_but_the_others_go(): void
    {
        $cancelled = $this->makePackage($this->cancel($this->makeOrder()), scanned: 2);
        $healthy = $this->makePackage($this->makeOrder('SPXID222'), scanned: 2);

        $response = $this->actingAs($this->admin)->post(route('admin.outbounds.ready.process'), [
            'ids' => [$cancelled->id, $healthy->id],
        ]);

        $response->assertSessionHas('success', "Paket {$healthy->code} dikirim. Stok sudah berkurang.");
        $response->assertSessionHas('error');

        $this->assertTrue($cancelled->refresh()->isDraft());
        $this->assertTrue($healthy->refresh()->isPosted());
        $this->assertSame(48, $this->product->refresh()->stock);
    }

    public function test_the_queue_shows_a_cancelled_package_instead_of_hiding_it(): void
    {
        $outbound = $this->makePackage($this->cancel($this->makeOrder()), scanned: 2);

        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            // Barangnya sudah turun dari rak; seseorang harus mengembalikannya.
            ->assertSee($outbound->code)
            ->assertSee('Dibatalkan pembeli')
            ->assertSee('Kembalikan 2 unit ke rak');
    }

    /* --------------------------------------------------- tandai manual --- */

    public function test_a_waybill_can_be_marked_cancelled_by_hand(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.imports.orders.cancel', $order), [
            'cancellation_reason' => 'Pembeli chat minta batal.',
        ])->assertSessionHas('success');

        $order->refresh();

        $this->assertTrue($order->isCancelledByHand());
        $this->assertSame($this->admin->id, $order->cancelled_by);
        $this->assertStringContainsString('Pembeli chat minta batal.', $order->cancellationDetail());
    }

    public function test_marking_cancelled_needs_a_reason(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post(route('admin.imports.orders.cancel', $order), [])
            ->assertSessionHasErrors('cancellation_reason');

        $this->assertFalse($order->refresh()->isCancelled());
    }

    /**
     * Barang yang sudah berangkat tidak bisa ditarik dengan menandai resinya.
     */
    public function test_a_shipped_waybill_can_not_be_cancelled(): void
    {
        $order = $this->makeOrder();
        $outbound = $this->makePackage($order, scanned: 2);

        $this->actingAs($this->admin)->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]]);
        $this->assertTrue($outbound->refresh()->isPosted());

        $this->actingAs($this->admin)->post(route('admin.imports.orders.cancel', $order), [
            'cancellation_reason' => 'Pembeli minta batal.',
        ])->assertSessionHas('error');

        $this->assertFalse($order->refresh()->isCancelled());
    }

    public function test_a_cancellation_can_be_withdrawn(): void
    {
        $order = $this->cancel($this->makeOrder());

        $this->actingAs($this->admin)
            ->delete(route('admin.imports.orders.restore', $order))
            ->assertSessionHas('success');

        $this->assertFalse($order->refresh()->isCancelled());

        // Dan paketnya bisa dipacking lagi seperti biasa.
        $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.marketplace.store'), ['code' => 'SPXID111'])
            ->assertOk();
    }

    /** Saringan yang sedang aktif bertahan setelah satu keputusan. */
    public function test_the_active_filter_survives_a_decision(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.imports.orders.cancel', $order), [
            'cancellation_reason' => 'Pembeli minta batal.',
            'stage' => ShipmentOrder::STAGE_AWAITING_QC,
            'courier' => 'SPX',
        ])->assertRedirect(route('admin.imports.status', [
            'stage' => ShipmentOrder::STAGE_AWAITING_QC,
            'courier' => 'SPX',
        ]));
    }

    public function test_marking_cancelled_needs_its_own_permission(): void
    {
        $order = $this->makeOrder();

        $role = Role::create(['name' => 'Pengamat Resi', 'slug' => 'pengamat-resi']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'imports.view'])->pluck('id'));

        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.imports.status'))->assertOk();

        $this->actingAs($viewer)->post(route('admin.imports.orders.cancel', $order), [
            'cancellation_reason' => 'Coba-coba.',
        ])->assertForbidden();

        $this->assertFalse($order->refresh()->isCancelled());
    }

    /* --------------------------------------------------- tahap ----------- */

    /**
     * Tiap resi hanya boleh berada di satu tahap, supaya jumlah tiap tahap
     * bisa dijumlahkan menjadi total tanpa ada yang terhitung dua kali.
     */
    public function test_a_cancelled_waybill_counts_only_once(): void
    {
        $this->cancel($this->makeOrder());
        $this->makeOrder('SPXID222');

        $this->assertSame(1, ShipmentOrder::cancelled()->count());
        $this->assertSame(1, ShipmentOrder::awaitingQc()->count());
        $this->assertSame(0, ShipmentOrder::qualityChecked()->count());
        $this->assertSame(0, ShipmentOrder::shipped()->count());
    }

    /**
     * Paket yang sudah berangkat tetap "dikirim" meskipun pesanannya kemudian
     * dibatalkan: stoknya memang sudah keluar, dan yang seperti itu kembali
     * lewat penerimaan retur.
     */
    public function test_a_shipped_waybill_stays_shipped_even_if_it_is_cancelled_later(): void
    {
        $order = $this->makeOrder();
        $outbound = $this->makePackage($order, scanned: 2);

        $this->actingAs($this->admin)->post(route('admin.outbounds.ready.process'), ['ids' => [$outbound->id]]);

        $order->forceFill(['cancelled_at' => now()])->save();

        $this->assertSame(ShipmentOrder::STAGE_SHIPPED, $order->refresh()->stage());
        $this->assertSame(1, ShipmentOrder::shipped()->count());
        $this->assertSame(0, ShipmentOrder::cancelled()->count());
    }

    public function test_the_status_page_lists_cancelled_waybills(): void
    {
        $this->cancel($this->makeOrder());

        $this->actingAs($this->admin)
            ->get(route('admin.imports.status', ['stage' => ShipmentOrder::STAGE_CANCELLED]))
            ->assertOk()
            ->assertSee('SPXID111')
            ->assertSee('Dibatalkan');
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makeOrder(string $tracking = 'SPXID111'): ShipmentOrder
    {
        $import = \App\Models\ShipmentImport::create([
            'filename' => 'ginee.csv', 'source' => 'ginee', 'row_count' => 1,
            'detected_columns' => ['tracking_number', 'sku'],
        ]);

        $order = $import->orders()->create([
            'tracking_number' => $tracking,
            'order_number' => 'INV-'.$tracking,
            'marketplace' => 'Shopee',
            'buyer_name' => 'Pembeli',
            'courier' => 'SPX',
        ]);

        $order->items()->create([
            'sku' => $this->product->sku,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 2,
        ]);

        return $order->load('items.product');
    }

    protected function cancel(ShipmentOrder $order): ShipmentOrder
    {
        $order->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $this->admin->id,
            'cancellation_reason' => 'Pembeli minta batal.',
        ])->save();

        return $order;
    }

    protected function makePackage(ShipmentOrder $order, int $scanned): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(),
            'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE,
            'marketplace' => $order->marketplace,
            'recipient' => 'Pembeli marketplace',
            'tracking_number' => $order->tracking_number,
            'shipment_order_id' => $order->id,
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);

        $outbound->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'scanned_quantity' => $scanned,
        ]);

        return $outbound->load('items.product');
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function uploadCsv(array $rows)
    {
        $csv = collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n");

        $path = tempnam(sys_get_temp_dir(), 'ginee').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'ginee-orders.csv', 'text/csv', null, true),
        ]);
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(),
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
