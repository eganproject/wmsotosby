<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang rusak bisa ditemukan saat menerima kiriman dan saat menghitung rak.
 *
 * Sebelumnya saldo rusak hanya bisa bertambah dari retur pelanggan. Kiriman
 * pemasok yang datang penyok terpaksa diterima seluruhnya sebagai layak jual,
 * dan opname yang menemukan barang pecah hanya bisa melaporkan angka yang lebih
 * kecil — sehingga tercatat sebagai barang hilang. Keduanya menuntut tindakan
 * yang berbeda: hilang perlu diselidiki, rusak bisa diklaim ke pemasok.
 *
 * Bawaannya nol, jadi seluruh dokumen lama tetap berarti persis seperti dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            // Bagian dari quantity yang ternyata rusak saat diterima.
            $table->unsignedInteger('damaged_quantity')->default(0)->after('quantity');
        });

        Schema::table('stock_opname_items', function (Blueprint $table) {
            // Unit rusak yang ditemukan saat menghitung; ditambahkan ke saldo
            // rusak, bukan menggantikannya — barang rusak lain bisa saja
            // tersimpan di tempat yang tidak ikut dihitung sesi ini.
            $table->unsignedInteger('damaged_quantity')->default(0)->after('counted_quantity');
            $table->integer('applied_damaged')->default(0)->after('applied_difference');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropColumn('damaged_quantity');
        });

        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->dropColumn(['damaged_quantity', 'applied_damaged']);
        });
    }
};
