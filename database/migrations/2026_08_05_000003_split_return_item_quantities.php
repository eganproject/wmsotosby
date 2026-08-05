<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris retur kini menyimpan rinciannya sendiri.
 *
 * quantity  = jumlah yang tercatat pada resi
 * good      = layak jual, kembali menjadi stok
 * damaged   = rusak, tercatat tetapi tidak menambah stok
 * hilang    = sisanya (quantity - good - damaged), dihitung, tidak disimpan
 *
 * Sebelumnya satu baris hanya punya satu kondisi, sehingga paket yang isinya
 * sebagian bagus dan sebagian kurang tidak bisa digambarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_receipt_items', function (Blueprint $table) {
            $table->unsignedInteger('good_quantity')->default(0)->after('quantity');
            $table->unsignedInteger('damaged_quantity')->default(0)->after('good_quantity');
        });

        // Data lama: seluruh baris masuk ke kolom sesuai kondisinya.
        DB::table('return_receipt_items')->where('condition', 'damaged')
            ->update(['damaged_quantity' => DB::raw('quantity')]);

        DB::table('return_receipt_items')->where('condition', '!=', 'damaged')
            ->update(['good_quantity' => DB::raw('quantity')]);

        Schema::table('return_receipt_items', function (Blueprint $table) {
            $table->dropColumn('condition');
        });
    }

    public function down(): void
    {
        Schema::table('return_receipt_items', function (Blueprint $table) {
            $table->string('condition')->default('good')->after('quantity');
        });

        DB::table('return_receipt_items')->whereColumn('damaged_quantity', '>=', 'good_quantity')
            ->where('damaged_quantity', '>', 0)
            ->update(['condition' => 'damaged']);

        Schema::table('return_receipt_items', function (Blueprint $table) {
            $table->dropColumn(['good_quantity', 'damaged_quantity']);
        });
    }
};
