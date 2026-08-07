<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen pengeluaran bisa mengambil dari saldo mana pun.
 *
 * Sebelumnya dokumen ini hanya mengambil dari saldo rusak, sehingga barang
 * layak jual yang harus dikembalikan ke pemasok atau dibuang karena
 * kedaluwarsa tidak punya jalan keluar sama sekali — satu-satunya cara adalah
 * penyesuaian stok, yang mencatatnya sebagai koreksi angka, bukan sebagai
 * barang yang benar-benar pergi ke suatu tempat.
 *
 * Dokumen lama seluruhnya mengambil dari saldo rusak, jadi itulah nilai
 * bawaannya dan tidak ada baris yang perlu diperbaiki.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damaged_disposals', function (Blueprint $table) {
            // good | damaged
            $table->string('bucket')->default('damaged')->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('damaged_disposals', function (Blueprint $table) {
            $table->dropColumn('bucket');
        });
    }
};
