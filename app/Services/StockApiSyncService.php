<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockApiSyncRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class StockApiSyncService
{
    public static function sync(Product $product, ?Carbon $changedAt = null): void
    {
        if (! Schema::hasTable('stock_api_sync_records')) {
            return;
        }
        $data = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'uom' => $product->unit ?: 'pcs',
            // API pusat hanya menerima stok layak jual. Stok rusak sengaja tidak dijumlahkan.
            'qty' => max(0, (int) $product->stock),
            'min_qty' => (int) $product->min_stock ?: null,
            'status' => $product->is_active ? 'active' : 'inactive',
            'source_updated_at' => $changedAt ?? $product->updated_at ?? now(),
        ];

        $record = StockApiSyncRecord::query()
            ->where(fn ($query) => $query
                ->where('product_id', $product->id)
                ->orWhere('sku', $product->sku))
            ->first();

        $record
            ? $record->fill($data)->save()
            : StockApiSyncRecord::create($data);
    }

    public static function markDeleted(Product $product): void
    {
        if (! Schema::hasTable('stock_api_sync_records')) {
            return;
        }
        StockApiSyncRecord::query()
            ->where('product_id', $product->id)
            ->each(fn (StockApiSyncRecord $record) => $record->update([
                'product_id' => null,
                'qty' => 0,
                'min_qty' => null,
                'status' => 'deleted',
                'source_updated_at' => now(),
            ]));
    }
}
