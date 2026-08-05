<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            $table->string('type')->default('regular'); // regular | marketplace
            $table->string('sender');
            $table->string('marketplace')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('reference')->nullable(); // nomor pesanan / dokumen asal
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('resi_verified_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'date']);
            $table->index('tracking_number');
        });

        Schema::create('return_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Hanya barang berkondisi "good" yang kembali menjadi stok siap jual.
            $table->string('condition')->default('good'); // good | damaged
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipt_items');
        Schema::dropIfExists('return_receipts');
    }
};
