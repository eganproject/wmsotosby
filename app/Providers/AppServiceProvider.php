<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
