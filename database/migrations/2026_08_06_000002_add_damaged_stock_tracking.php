<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo barang rusak.
 *
 * Sebelumnya barang rusak hanya tercatat pada dokumen returnya lalu hilang
 * dari sistem: tidak ada saldonya, tidak bisa ditelusuri, dan tidak ada
 * catatan saat akhirnya dibuang atau diklaimkan ke pemasok. Sekarang tiap
 * barang punya dua saldo, dan setiap pergerakan menyebut saldo mana yang
 * disentuhnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('damaged_stock')->default(0)->after('stock');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // good | damaged
            $table->string('bucket')->default('good')->after('type');
            $table->index(['product_id', 'bucket', 'created_at']);
        });

        Schema::create('damaged_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            // dibuang | dikembalikan | diperbaiki
            $table->string('action');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
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

        Schema::create('damaged_disposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damaged_disposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('note')->nullable();

            $table->unique(['damaged_disposal_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damaged_disposal_items');
        Schema::dropIfExists('damaged_disposals');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'bucket', 'created_at']);
            $table->dropColumn('bucket');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('damaged_stock');
        });
    }
};
