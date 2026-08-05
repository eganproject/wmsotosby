<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Data awal pemasok. Selebihnya ditambahkan sendiri lewat menu Pemasok.
     */
    public function run(): void
    {
        Supplier::updateOrCreate(
            ['code' => 'SUP-0001'],
            [
                'name' => 'Agen Surabaya',
                'contact_name' => 'Budi Santoso',
                'phone' => '081234567801',
                'email' => 'sales@agensurabaya.test',
                'address' => 'Jl. Raya Rungkut No. 45, Surabaya',
                'note' => 'Termin 30 hari, kirim setiap Senin & Kamis.',
                'is_active' => true,
            ],
        );
    }
}
