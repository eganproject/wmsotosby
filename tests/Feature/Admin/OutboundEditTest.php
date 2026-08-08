<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengubah dokumen barang keluar dari daftar.
 *
 * Baris barang selalu ditulis ulang seluruhnya saat disimpan. Dulu itu berarti
 * setiap penyimpanan — termasuk yang tidak mengubah apa pun — menghapus seluruh
 * hasil pemindaian dan membatalkan verifikasi resi, sehingga paket yang sudah
 * selesai QC diam-diam hilang dari antrean Siap Dikirim dan harus dipindai
 * ulang dari nol.
 */
class OutboundEditTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar',
            'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $this->giveStock(50);

        $this->outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID01234567890',
            'status' => Outbound::STATUS_DRAFT, 'resi_verified_at' => now(),
        ]);
        $this->outbound->items()->create([
            'product_id' => $this->product->id, 'quantity' => 4, 'scanned_quantity' => 4,
        ]);
    }

    /* ------------------------------------------------ hasil scan --------- */

    public function test_saving_without_any_change_keeps_the_package_ready_to_ship(): void
    {
        $this->assertSame(1, Outbound::readyToShip()->count(), 'Prasyarat: paket sudah mengantre.');

        $this->save()->assertRedirect(route('admin.outbounds.scan', $this->outbound));

        $this->outbound->refresh();

        $this->assertTrue($this->outbound->isResiVerified(), 'Resi tidak berganti, verifikasinya harus bertahan.');
        $this->assertSame(4, (int) $this->outbound->items()->sum('scanned_quantity'));
        $this->assertSame(1, Outbound::readyToShip()->count(), 'Paket tetap di antrean.');
    }

    /** Mengetik ulang catatan bukan alasan memindai ulang satu gudang. */
    public function test_editing_only_the_note_keeps_the_scan_result(): void
    {
        $this->save(['note' => 'Titip ke pos satpam.']);

        $this->assertTrue($this->outbound->refresh()->isResiVerified());
        $this->assertSame(4, (int) $this->outbound->items()->sum('scanned_quantity'));
    }

    /** Nomor resi berganti berarti paketnya lain, jadi verifikasinya gugur. */
    public function test_changing_the_waybill_voids_the_verification(): void
    {
        $this->save(['tracking_number' => 'SPXID99999999999']);

        $this->outbound->refresh();

        $this->assertFalse($this->outbound->isResiVerified());
        $this->assertSame(0, Outbound::readyToShip()->count());
    }

    /** Jumlah yang dikurangi tidak boleh menyisakan hasil scan yang mustahil. */
    public function test_reducing_the_quantity_clamps_the_scan_result(): void
    {
        $this->save(items: [
            ['product_id' => $this->product->id, 'quantity' => 2, 'note' => null],
        ]);

        $item = $this->outbound->refresh()->items()->firstOrFail();

        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(2, (int) $item->scanned_quantity, 'Tidak boleh 4 dari 2.');
    }

    /** Barang yang baru ditambahkan tentu belum pernah dipindai. */
    public function test_a_newly_added_line_starts_unscanned(): void
    {
        $other = Product::create([
            'sku' => 'BAN-RING-14', 'name' => 'Ban Ring 14', 'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $this->save(items: [
            ['product_id' => $this->product->id, 'quantity' => 4, 'note' => null],
            ['product_id' => $other->id, 'quantity' => 1, 'note' => null],
        ]);

        $this->outbound->refresh();

        $this->assertSame(4, (int) $this->outbound->items()->where('product_id', $this->product->id)->value('scanned_quantity'));
        $this->assertSame(0, (int) $this->outbound->items()->where('product_id', $other->id)->value('scanned_quantity'));
        $this->assertSame(0, Outbound::readyToShip()->count(), 'Ada barang baru yang belum dipindai.');
    }

    /* ------------------------------------------------ barang nonaktif ---- */

    /**
     * Barang yang sudah dinonaktifkan tetapi masih tercantum di dokumen harus
     * tetap ada di dropdown. Tanpa pilihannya, baris itu jatuh ke kosong dan
     * lenyap tanpa suara begitu dokumen disimpan ulang.
     */
    public function test_a_deactivated_product_still_appears_in_the_editor(): void
    {
        $this->product->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.edit', $this->outbound))
            ->assertOk()
            ->assertSee('FLT-OLI-STD');
    }

    /* ------------------------------------------------ helpers ------------ */

    protected function save(array $overrides = [], ?array $items = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.outbounds.update', $this->outbound), $overrides + [
            'date' => $this->outbound->date->format('Y-m-d'),
            'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID01234567890',
            'items' => $items ?? [
                ['product_id' => $this->product->id, 'quantity' => 4, 'note' => null],
            ],
        ]);
    }

    protected function giveStock(int $quantity): void
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }
}
