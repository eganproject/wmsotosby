<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\User;
use App\Services\StockApiSyncService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPermissionGates();

        RateLimiter::for('stock-api', fn (Request $request) => Limit::perMinute(
            config('stock_api.rate_limit_per_minute')
        )->by(hash('sha256', (string) $request->bearerToken().'|'.$request->ip())));

        Product::saved(fn (Product $product) => StockApiSyncService::sync($product));
        Product::deleting(fn (Product $product) => StockApiSyncService::markDeleted($product));
    }

    /**
     * Setiap ability berformat "modul.aksi" diperlakukan sebagai permission,
     * sehingga can() dan @can bekerja tanpa perlu mendaftarkan gate satu per satu.
     *
     * Pemeriksaan sengaja dilakukan saat gate dipanggil, bukan saat boot, agar
     * permission yang baru ditambahkan langsung berlaku.
     */
    protected function registerPermissionGates(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if (! str_contains($ability, '.')) {
                return null;
            }

            // null berarti "tidak berpendapat" sehingga policy lain tetap bisa
            // dipertimbangkan; tanpa gate lain hasilnya tetap ditolak.
            return $user->hasPermission($ability) ?: null;
        });
    }
}
