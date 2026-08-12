<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockApiAllowedIp;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stock_api.enabled' => true,
            'stock_api.token' => 'test-secret',
            'stock_api.warehouse_code' => 'WMSOTOSBY',
        ]);

        StockApiAllowedIp::create([
            'ip_address' => '127.0.0.1',
            'label' => 'Test',
            'is_active' => true,
        ]);
    }

    public function test_health_requires_a_valid_token(): void
    {
        $this->getJson('/api/v1/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_health_returns_the_compatible_payload(): void
    {
        $this->withToken('test-secret')->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('warehouse_code', 'WMSOTOSBY')
            ->assertJsonStructure(['success', 'warehouse_code', 'server_time']);
    }

    public function test_stock_quantity_excludes_damaged_stock(): void
    {
        $product = Product::create([
            'sku' => 'SKU-001',
            'name' => 'Produk Satu',
            'category' => 'Kategori',
            'unit' => 'pcs',
            'min_stock' => 3,
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => 12, 'damaged_stock' => 7])->save();

        $this->withToken('test-secret')->getJson('/api/v1/stocks')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'SKU-001')
            ->assertJsonPath('data.0.qty', 12)
            ->assertJsonPath('data.0.min_qty', 3)
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_historical_stock_only_uses_the_good_bucket(): void
    {
        $product = Product::create([
            'sku' => 'SKU-HISTORY',
            'name' => 'Produk Historis',
            'unit' => 'pcs',
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => 10, 'damaged_stock' => 4])->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'bucket' => StockMovement::BUCKET_GOOD,
            'quantity' => 10,
            'balance_after' => 10,
            'description' => 'Stok awal',
        ])->forceFill(['created_at' => '2026-08-01 08:00:00'])->saveQuietly();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'bucket' => StockMovement::BUCKET_DAMAGED,
            'quantity' => 4,
            'balance_after' => 4,
            'description' => 'Stok rusak',
        ])->forceFill(['created_at' => '2026-08-01 09:00:00'])->saveQuietly();

        $this->withToken('test-secret')->getJson('/api/v1/stocks?as_of=2026-08-01')
            ->assertOk()
            ->assertJsonPath('data.0.qty', 10);
    }

    public function test_invalid_filters_use_the_compatible_error_shape(): void
    {
        $this->withToken('test-secret')->getJson('/api/v1/stocks?per_page=501')
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_PARAMETER');
    }

    public function test_deleted_product_is_retained_as_a_tombstone(): void
    {
        $product = Product::create([
            'sku' => 'SKU-DELETED',
            'name' => 'Produk Dihapus',
            'unit' => 'pcs',
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $product->delete();

        $this->withToken('test-secret')->getJson('/api/v1/stocks')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SKU-DELETED')
            ->assertJsonPath('data.0.qty', 0)
            ->assertJsonPath('data.0.status', 'deleted');
    }
}
