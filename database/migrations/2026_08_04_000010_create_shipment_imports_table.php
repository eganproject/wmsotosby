<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu berkas hasil eksport Ginee.
        Schema::create('shipment_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('source')->default('ginee');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('unmatched_sku_count')->default(0);
            $table->json('detected_columns')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Satu pesanan / satu nomor resi.
        Schema::create('shipment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_import_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_number')->unique();
            $table->string('order_number')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('store_name')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('order_status')->nullable();
            $table->string('courier')->nullable();
            $table->date('order_date')->nullable();
            $table->timestamps();

            $table->index('order_number');
        });

        Schema::create('shipment_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_order_id')->constrained()->cascadeOnDelete();
            // SKU adalah kunci pencocokan ke master barang.
            $table->string('sku');
            $table->string('product_name')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('sku');
        });

        // Dokumen gudang yang dibuat dari data import.
        Schema::table('outbounds', function (Blueprint $table) {
            $table->foreignId('shipment_order_id')->nullable()->after('tracking_number')->constrained()->nullOnDelete();
        });

        Schema::table('return_receipts', function (Blueprint $table) {
            $table->foreignId('shipment_order_id')->nullable()->after('tracking_number')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_receipts', function (Blueprint $table) {
            $table->dropForeign(['shipment_order_id']);
            $table->dropColumn('shipment_order_id');
        });

        Schema::table('outbounds', function (Blueprint $table) {
            $table->dropForeign(['shipment_order_id']);
            $table->dropColumn('shipment_order_id');
        });

        Schema::dropIfExists('shipment_order_items');
        Schema::dropIfExists('shipment_orders');
        Schema::dropIfExists('shipment_imports');
    }
};
