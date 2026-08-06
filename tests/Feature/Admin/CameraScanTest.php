<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pemindaian lewat kamera ponsel.
 *
 * Scanner genggam tetap jalur utama; kamera adalah jalan kedua bagi petugas
 * yang hanya membawa ponsel. Keduanya bermuara ke kolom kode yang sama,
 * sehingga tidak ada cabang alur baru yang perlu dijaga.
 */
class CameraScanTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();
    }

    /** Awal jalur SVG ikon kamera — lihat components/icon.blade.php. */
    protected const CAMERA_ICON = 'M6.827 6.175';

    /** Awal jalur SVG ikon kaca pembesar, yang dulu dipakai tombol ini. */
    protected const SEARCH_ICON = 'm21 21-5.197-5.197';

    public static function scanPages(): array
    {
        return [
            'stasiun packing' => ['admin.outbounds.marketplace'],
            'stasiun retur' => ['admin.returns.marketplace'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scanPages')]
    public function test_a_scan_station_offers_the_camera(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route))
            ->assertOk()
            ->assertSee('cameraScanner()', false)
            ->assertSee('Pindai dengan kamera')
            // Hasil kamera masuk ke kolom kode yang sama dengan scanner genggam.
            ->assertSee('camera-scan.window', false);
    }

    public function test_the_saved_document_scan_page_offers_the_camera_too(): void
    {
        $outbound = $this->makeOutbound();

        $this->actingAs($this->admin)->get(route('admin.outbounds.scan', $outbound))
            ->assertOk()
            ->assertSee('cameraScanner()', false)
            ->assertSee('camera-scan.window', false);
    }

    /**
     * Antrean siap kirim ikut memindai resi untuk mengirim paket, jadi ia
     * butuh tombol yang sama seperti stasiun lainnya.
     */
    public function test_the_dispatch_queue_offers_the_camera(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.ready'))
            ->assertOk()
            ->assertSee('cameraScanner()', false)
            ->assertSee('Pindai dengan kamera')
            ->assertSee('camera-scan.window', false);
    }

    /**
     * Halaman ini sempat punya kolom scan tanpa tombol kamera sama sekali,
     * padahal retur justru sering diterima sambil memegang ponsel.
     */
    public function test_the_return_scan_page_offers_the_camera(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->get(route('admin.returns.scan', $return))
            ->assertOk()
            ->assertSee('cameraScanner()', false)
            ->assertSee('Pindai dengan kamera')
            ->assertSee('camera-scan.window', false);
    }

    /**
     * Tombolnya memakai ikon kamera, bukan kaca pembesar.
     *
     * Kaca pembesar sudah dipakai kolom kode di sebelahnya untuk arti yang
     * berbeda — "cari kode ini" — sehingga dua tombol bersebelahan terlihat
     * mengerjakan hal yang sama.
     */
    public function test_the_camera_button_wears_a_camera_icon(): void
    {
        $partial = file_get_contents(resource_path('views/admin/partials/camera-scan.blade.php'));

        $this->assertStringNotContainsString('name="search"', $partial);

        $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'))
            ->assertOk()
            ->assertSee(self::CAMERA_ICON, false);
    }

    /**
     * Kolom kode tetap memakai kaca pembesar: keduanya berdampingan justru
     * supaya bedanya terbaca sekilas.
     */
    public function test_the_code_field_keeps_its_magnifier(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'))
            ->assertOk()
            ->assertSee(self::SEARCH_ICON, false);
    }

    /**
     * Kamera menutupi seluruh layar, jadi umpan balik stasiun harus ikut
     * tampil di dalamnya. Tanpa itu operator memindai tanpa tahu apakah
     * bacaannya diterima — persis kebingungan yang dilaporkan dari lapangan.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scanPages')]
    public function test_the_camera_overlay_reports_what_the_station_says(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route))
            ->assertOk()
            // Pesan hasil scan dari komponen stasiun.
            ->assertSee('feedback.message', false)
            // Tahap yang sedang berjalan terbaca di dalam layar kamera:
            // judulnya, nomor langkahnya, dan petunjuk arahnya.
            ->assertSee('Langkah ${', false)
            ->assertSee('Scan label resi', false)
            ->assertSee('Arahkan ke label resi', false)
            ->assertSee('Arahkan kamera ke kode');
    }

    /**
     * Kamera hanya jalan di halaman aman. Pesannya harus menyebut sebabnya,
     * bukan sekadar gagal, karena di HTTP peramban menolak diam-diam.
     */
    public function test_the_https_requirement_is_spelled_out(): void
    {
        $script = file_get_contents(resource_path('js/camera-scanner.js'));

        $this->assertStringContainsString('isSecureContext', $script);
        $this->assertStringContainsString('https://', $script);
    }

    /**
     * Mesin pemindainya dimuat belakangan supaya bundel utama tidak ikut
     * membesar bagi peramban yang sudah punya BarcodeDetector bawaan.
     */
    public function test_the_scanning_engine_is_loaded_on_demand(): void
    {
        $script = file_get_contents(resource_path('js/camera-scanner.js'));

        $this->assertStringContainsString("import('barcode-detector/pure')", $script);
        $this->assertStringContainsString("'BarcodeDetector' in window", $script);
        // Berkas wasm disajikan dari domain sendiri, bukan CDN.
        $this->assertStringContainsString('setZXingModuleOverrides', $script);
    }

    /* --------------------------------------------------- helpers --------- */

    protected function makeOutbound(): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Pembeli',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID1',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $outbound->items()->create(['product_id' => $this->makeProduct()->id, 'quantity' => 1]);

        return $outbound;
    }

    protected function makeReturn(): ReturnReceipt
    {
        $return = ReturnReceipt::create([
            'code' => ReturnReceipt::nextCode(), 'date' => now(),
            'type' => ReturnReceipt::TYPE_MARKETPLACE, 'sender' => 'Pembeli',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID9',
            'status' => ReturnReceipt::STATUS_DRAFT,
        ]);

        $return->items()->create([
            'product_id' => $this->makeProduct('FLT-2', '8991234500042')->id,
            'quantity' => 1, 'good_quantity' => 1, 'damaged_quantity' => 0,
        ]);

        return $return;
    }

    protected function makeProduct(string $sku = 'FLT-1', string $barcode = '8991234500035'): Product
    {
        return Product::create([
            'sku' => $sku, 'barcode' => $barcode,
            'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0,
        ]);
    }
}
