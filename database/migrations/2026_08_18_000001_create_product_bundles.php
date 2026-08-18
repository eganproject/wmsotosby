<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket bundling: satu SKU yang dijual marketplace tetapi tidak pernah ada
 * wujudnya di rak.
 *
 * Paket tidak punya saldo sendiri. Yang benar-benar ada hanyalah barang
 * isinya, dan itulah yang bergerak saat paket dikirim — jadi paket dipecah
 * menjadi komponen sejak baris dokumen dibentuk, bukan saat diposting.
 * Dengan begitu stock_movements, laporan, dan pemeriksaan stok tetap bekerja
 * atas barang nyata tanpa perlu tahu soal paket sama sekali.
 *
 * Seluruh perubahan di sini bersifat menambah. Tidak ada satu pun baris lama
 * yang ditulis ulang: barang yang sudah ada otomatis bertipe 'single' lewat
 * nilai bawaan kolom, dan kedua tabel baru dimulai dalam keadaan kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
                Sengaja tanpa ->after(), berbeda dari migrasi lain di proyek ini.

                MariaDB hanya bisa memakai ALGORITHM=INSTANT untuk kolom yang
                ditambahkan di ujung tabel. Menyisipkannya di tengah memaksa
                salin-ulang seluruh tabel, dan di shared hosting itu berarti
                products — tabel yang paling sering dibaca — terkunci selama
                penempatan berlangsung.
            */
            // single | bundle
            $table->string('type')->default('single');
        });

        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            // RESTRICT, sama seperti baris dokumen: barang yang masih menjadi
            // isi sebuah paket tidak boleh lenyap begitu saja.
            $table->foreignId('component_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['bundle_id', 'component_id']);
        });

        Schema::create('outbound_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbound_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            /*
                Resep pada saat dokumen dibentuk, disalin apa adanya.

                Jumlah yang benar-benar dikirim memang sudah beku di
                outbound_items, jadi stoknya tidak bergantung pada kolom ini.
                Yang dijaga di sini adalah rinciannya: resep paket bisa
                berubah bulan depan, dan dokumen yang sudah terkirim harus
                tetap bisa menjelaskan isinya waktu itu.
            */
            $table->json('composition');

            $table->unique(['outbound_id', 'bundle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_bundles');
        Schema::dropIfExists('product_bundle_items');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
