<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok opname: sesi penghitungan fisik atas sekumpulan barang.
 *
 * Bedanya dengan penyesuaian stok — yang barisnya dipilih satu per satu —
 * opname memotret seluruh barang dalam cakupan tertentu lebih dulu, lalu
 * dihitung satu per satu di rak. Baris yang belum dihitung disimpan sebagai
 * NULL, bukan nol, supaya "belum dihitung" tidak pernah tertukar dengan
 * "dihitung dan hasilnya kosong".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            // all | category | location
            $table->string('scope')->default('all');
            $table->string('scope_value')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'date']);
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Saldo tercatat saat sesi dibuka, sebagai pembanding.
            $table->unsignedInteger('system_quantity')->default(0);
            // NULL selama barangnya belum dihitung.
            $table->unsignedInteger('counted_quantity')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            // Selisih yang benar-benar dibukukan saat sesi disetujui.
            $table->integer('applied_difference')->nullable();
            $table->string('note')->nullable();

            $table->unique(['stock_opname_id', 'product_id']);
            $table->index(['stock_opname_id', 'counted_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
