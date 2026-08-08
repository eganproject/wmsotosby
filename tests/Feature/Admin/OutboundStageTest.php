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
 * Status paket marketplace pada daftar barang keluar.
 *
 * Kolom status di basis data hanya mengenal draft, diajukan, ditolak, dan
 * diposting. Bagi paket marketplace itu terlalu kasar: satu kata "draft"
 * dipakai bersama oleh paket yang belum disentuh sama sekali dan paket yang
 * sudah selesai QC serta sedang mengantre di Siap Dikirim — dua keadaan yang
 * menuntut tindakan sama sekali berbeda.
 */
class OutboundStageTest extends TestCase
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
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar',
            'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => 100]);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    /* ---------------------------------------------------- tiap tahap ----- */

    public function test_every_marketplace_stage_has_its_own_name(): void
    {
        $this->assertSame(Outbound::STAGE_NEED_RESI, $this->package(verified: false)->stage());
        $this->assertSame(Outbound::STAGE_SCANNING, $this->package(scanned: 1)->stage());
        $this->assertSame(Outbound::STAGE_READY, $this->package(scanned: 2)->stage());
        $this->assertSame(Outbound::STAGE_NEED_ITEMS, $this->package(quantity: 0)->stage());
    }

    /**
     * Inilah keadaan yang paling menyesatkan sebelumnya: paket yang sudah
     * lengkap dipindai dan mengantre di Siap Dikirim tetap berlabel "Draft",
     * sama persis dengan dokumen yang belum disentuh siapa pun.
     */
    public function test_a_packed_marketplace_order_is_not_called_a_draft(): void
    {
        $ready = $this->package(scanned: 2);

        $this->assertSame(1, Outbound::readyToShip()->count());
        $this->assertSame('Siap dikirim', $ready->stageLabel());
        $this->assertNotSame('Draft', $ready->stageLabel());
    }

    /** Dokumen reguler tetap sederhana: draft ya draft. */
    public function test_a_regular_document_keeps_the_plain_label(): void
    {
        $regular = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_REGULAR, 'recipient' => 'Cabang Bandung',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->assertSame('Draft', $regular->stageLabel());
    }

    /** Keputusan penyetuju tidak boleh tertutup oleh tahap pemindaian. */
    public function test_a_rejected_package_still_reads_as_rejected(): void
    {
        $package = $this->package(scanned: 0, verified: false);
        $package->forceFill(['status' => Outbound::STATUS_REJECTED])->save();

        $this->assertSame('Ditolak', $package->refresh()->stageLabel());
    }

    /* ---------------------------------------------------- halaman -------- */

    public function test_the_list_shows_the_stage_on_both_layouts(): void
    {
        $this->package(scanned: 2);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index'))
            ->assertOk()
            ->getContent();

        // Tiga kali: badge di tabel, badge di kartu ponsel, dan satu pilihan di
        // saringan. Yang penting dua yang pertama — dokumen yang sama tidak
        // boleh bercerita berbeda hanya karena layarnya lebih sempit. Dulu
        // kartu ponsel mengabaikan tahap pemindaian dan menulis "Draft".
        $this->assertSame(3, substr_count($html, 'Siap dikirim'));
    }

    /* ---------------------------------------------------- saringan ------- */

    public function test_the_filter_offers_one_option_per_badge(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index'))
            ->assertOk()
            ->getContent();

        foreach (['Perlu scan resi', 'Scan barang', 'Siap dikirim', 'Menunggu persetujuan', 'Ditolak', 'Terkirim'] as $label) {
            $this->assertStringContainsString($label, $html, "Saringan kehilangan pilihan {$label}.");
        }
    }

    public function test_the_ready_filter_returns_only_packed_orders(): void
    {
        $ready = $this->package(scanned: 2);
        $this->package(scanned: 1);

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['status' => Outbound::STAGE_READY]))
            ->assertOk()
            ->assertSee($ready->code)
            ->assertDontSee('OUT-'.now()->format('Ym').'-0002');
    }

    /** "Draft" kini berarti persis apa yang tertulis di badge, bukan lebih. */
    public function test_the_draft_filter_no_longer_hides_packed_orders_inside_it(): void
    {
        $packed = $this->package(scanned: 2);

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['status' => Outbound::STATUS_DRAFT]))
            ->assertOk()
            ->assertDontSee($packed->code);
    }

    /* ---------------------------------------------------- helpers -------- */

    protected function package(int $scanned = 0, bool $verified = true, int $quantity = 2): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID'.str_pad((string) (Outbound::count() + 1), 11, '0', STR_PAD_LEFT),
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => $verified ? now() : null,
        ]);

        if ($quantity > 0) {
            $outbound->items()->create([
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'scanned_quantity' => $scanned,
            ]);
        }

        return $outbound->load('items');
    }
}
