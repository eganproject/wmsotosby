<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa baris yang kolom stoknya dilewati karena barangnya paket bundling.
 *
 * Paket tidak punya saldo, jadi angka stok pada barisnya tidak bisa
 * dikerjakan. Melewatinya diam-diam berarti berkas yang salah tetap
 * dilaporkan "berhasil" — padahal ada angka yang diketik orang dan tidak
 * berakibat apa-apa. Jumlahnya disimpan supaya ringkasan hasil import bisa
 * menyebutkannya.
 *
 * Aditif, dan seperti kolom paket sebelumnya sengaja ditambahkan di ujung
 * tabel agar ALTER-nya instan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->unsignedInteger('bundle_skipped_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->dropColumn('bundle_skipped_count');
        });
    }
};
