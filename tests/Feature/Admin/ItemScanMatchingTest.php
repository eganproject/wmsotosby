<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pencocokan kode saat scan barang: barcode maupun SKU sama-sama diterima.
 */
class ItemScanMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $withBarcode;

    protected Product $withoutBarcode;

    protected Outbound $outbound;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@wmsotosby.test')->firstOrFail();

        $this->withBarcode = Product::create([
            'sku' => 'FLT-OLI-STD', 'barcode' => '8991234500035',
            'name' => 'Filter Oli Standar', 'unit' => 'pcs',
        ]);

        // Banyak barang belum punya barcode; SKU harus tetap bisa dipakai.
        $this->withoutBarcode = Product::create([
            'sku' => 'KMP-REM-DPN', 'barcode' => null,
            'name' => 'Kampas Rem Depan', 'unit' => 'set',
        ]);

        $this->outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi',
            'marketplace' => 'Shopee', 'tracking_number' => 'SPXID1',
            'status' => Outbound::STATUS_DRAFT, 'resi_verified_at' => now(),
        ]);

        $this->outbound->items()->create(['product_id' => $this->withBarcode->id, 'quantity' => 2]);
        $this->outbound->items()->create(['product_id' => $this->withoutBarcode->id, 'quantity' => 1]);

        // Barang tanpa stok tercatat tidak boleh discan; di sini yang diuji
        // pencocokan kodenya, jadi stoknya dicukupkan.
        foreach ([$this->withBarcode, $this->withoutBarcode] as $product) {
            $product->forceFill(['stock' => 10])->save();
        }
    }

    public function test_a_barcode_is_accepted(): void
    {
        $this->scan('8991234500035')
            ->assertOk()
            ->assertJsonPath('matched_by', 'barcode')
            ->assertJsonPath('sku', 'FLT-OLI-STD');
    }

    public function test_a_sku_is_accepted(): void
    {
        $this->scan('FLT-OLI-STD')
            ->assertOk()
            ->assertJsonPath('matched_by', 'sku')
            ->assertJsonPath('sku', 'FLT-OLI-STD');
    }

    public function test_a_product_without_a_barcode_is_scanned_by_sku(): void
    {
        $this->scan('KMP-REM-DPN')
            ->assertOk()
            ->assertJsonPath('matched_by', 'sku')
            ->assertJsonPath('completed', true);
    }

    public function test_matching_ignores_case_and_spaces(): void
    {
        $this->scan(' flt-oli-std ')->assertOk()->assertJsonPath('matched_by', 'sku');
        $this->scan('8991 2345 00035')->assertOk()->assertJsonPath('matched_by', 'barcode');

        $this->assertSame(2, $this->outbound->items()->sum('scanned_quantity'));
    }

    public function test_a_whitespace_only_code_is_rejected_by_the_request(): void
    {
        // TrimStrings bawaan Laravel memangkasnya menjadi kosong lebih dulu.
        $this->scan('   ')->assertStatus(422);

        $this->assertSame(0, (int) $this->outbound->items()->sum('scanned_quantity'));
    }

    public function test_a_blank_code_never_matches_a_product_without_a_barcode(): void
    {
        // Lapisan kedua: bila service dipanggil langsung tanpa lewat request,
        // kode kosong tidak boleh cocok dengan barcode yang juga kosong.
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->actingAs($this->admin);

        app(\App\Services\OutboundScanService::class)
            ->scanItem($this->outbound->load('items.product'), '   ');
    }

    public function test_the_service_guard_leaves_the_document_untouched(): void
    {
        try {
            app(\App\Services\OutboundScanService::class)
                ->scanItem($this->outbound->load('items.product'), '   ');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(
                'Kode tidak terbaca. Ulangi scan pada barang.',
                $exception->errors()['code'][0],
            );
        }

        $this->assertSame(0, (int) $this->outbound->items()->sum('scanned_quantity'));
    }

    public function test_a_known_product_outside_the_order_says_so(): void
    {
        Product::create(['sku' => 'BSI-IRIDIUM', 'barcode' => '8991234500073', 'name' => 'Busi Iridium', 'unit' => 'pcs']);

        $this->scan('8991234500073')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Busi Iridium (SKU BSI-IRIDIUM) tidak termasuk dalam pesanan ini.');
    }

    public function test_an_unregistered_code_says_so(): void
    {
        $this->scan('9999999999999')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Kode 9999999999999 tidak dikenali sebagai barcode maupun SKU barang mana pun.');
    }

    public function test_scanning_beyond_the_quantity_names_the_sku(): void
    {
        $this->scan('KMP-REM-DPN');

        $this->scan('KMP-REM-DPN')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'KMP-REM-DPN sudah lengkap · 1 set');
    }

    public function test_both_fields_can_be_mixed_within_one_document(): void
    {
        $this->scan('8991234500035')->assertOk();  // barcode
        $this->scan('FLT-OLI-STD')->assertOk();    // SKU untuk barang yang sama
        $this->scan('KMP-REM-DPN')->assertOk();    // SKU, barang tanpa barcode

        $this->assertTrue($this->outbound->refresh()->isFullyScanned());
    }

    public function test_the_scan_page_states_that_both_codes_work(): void
    {
        $this->actingAs($this->admin)->get(route('admin.outbounds.scan', $this->outbound))
            ->assertOk()
            ->assertSee('Setiap barang bisa discan lewat barcode maupun SKU-nya.')
            ->assertSee('Tanpa barcode — scan pakai SKU')
            ->assertSee('8991234500035');
    }

    protected function scan(string $code)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('admin.outbounds.scan.item', $this->outbound), ['code' => $code]);
    }
}
