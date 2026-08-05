<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Master barang sengaja tidak di-seed: diisi lewat menu
        // Barang & Stok, baik satu per satu maupun import Excel.
        $this->call([
            RolePermissionSeeder::class,
            SupplierSeeder::class,
        ]);
    }
}
