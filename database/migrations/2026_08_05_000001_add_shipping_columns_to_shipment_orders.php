<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyesuaikan tabel dengan kolom yang benar-benar ada pada eksport Ginee:
 * Tanggal Pembuatan, ID Pesanan, SKU, Jumlah, Kurir, AWB/No. Tracking,
 * Metode Pengiriman, dan Catatan Pembeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->string('shipping_method')->nullable()->after('courier');
            $table->text('buyer_note')->nullable()->after('shipping_method');

            // Tanggal pembuatan pesanan menyertakan jam, jadi tanggal saja tidak cukup.
            $table->dateTime('order_date')->nullable()->change();

            $table->index('courier');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropIndex(['courier']);
            $table->dropColumn(['shipping_method', 'buyer_note']);
            $table->date('order_date')->nullable()->change();
        });
    }
};
