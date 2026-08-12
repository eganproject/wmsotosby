<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\StockApiSyncService;
use Illuminate\Console\Command;

class BackfillStockApiRecords extends Command
{
    protected $signature = 'stock-api:backfill';

    protected $description = 'Mengisi proyeksi API stok tanpa mengubah saldo';

    public function handle(): int
    {
        Product::query()->orderBy('id')->chunkById(200, function ($products): void {
            $products->each(fn (Product $product) => StockApiSyncService::sync($product));
        });

        $this->info('Backfill API stok selesai.');

        return self::SUCCESS;
    }
}
