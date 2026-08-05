<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian stok: menyelaraskan saldo tercatat dengan hasil hitung fisik.
 *
 * Setiap baris menyimpan stok tercatat saat dokumen dibuat dan hasil
 * hitungnya. Selisih yang benar-benar diterapkan dicatat terpisah supaya
 * jejaknya tetap jelas meski stok sempat berubah sebelum disetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            $table->string('reason');
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

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Saldo tercatat saat dokumen disusun, sebagai pembanding.
            $table->unsignedInteger('system_quantity')->default(0);
            $table->unsignedInteger('actual_quantity')->default(0);
            // Selisih yang benar-benar dibukukan saat dokumen disetujui.
            $table->integer('applied_difference')->nullable();
            $table->string('note')->nullable();

            $table->unique(['stock_adjustment_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
