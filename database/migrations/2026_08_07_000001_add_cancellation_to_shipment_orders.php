<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pesanan yang dibatalkan pembeli.
 *
 * Status pesanan dari Ginee sebenarnya sudah ikut tersimpan sejak awal, tetapi
 * tidak pernah dibaca oleh apa pun — sehingga resi batal tetap bisa discan
 * sampai tuntas dan stoknya berkurang untuk pesanan yang tidak akan pernah
 * berangkat. Pembatalan kini punya kolomnya sendiri supaya bisa datang dari
 * dua arah: dibaca dari berkas import, atau ditandai petugas yang lebih dulu
 * tahu dari aplikasi marketplace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('order_status');
            // Kosong berarti pembatalan terbaca dari berkas import, bukan
            // ditandai orang.
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index('cancelled_at');
        });

        $this->relinkOrphanedOutbounds();
    }

    public function down(): void
    {
        Schema::table('shipment_orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }

    /**
     * Sambungkan kembali dokumen yang tautannya sempat terputus.
     *
     * Import ulang dulu menghapus baris resi lama lalu membuatnya dari nol.
     * Kolom shipment_order_id pada dokumen barang keluar diatur nullOnDelete,
     * jadi setiap import ulang diam-diam memutus tautan dokumen yang sudah
     * ada — termasuk yang sudah dikirim, yang lalu muncul lagi sebagai "Belum
     * QC" di halaman Status Resi.
     *
     * Import ulang sekarang memperbarui barisnya, bukan menghapusnya, jadi ini
     * hanya membereskan data yang sudah terlanjur terputus.
     */
    protected function relinkOrphanedOutbounds(): void
    {
        DB::table('shipment_orders')
            ->select('id', 'tracking_number')
            ->orderBy('id')
            ->chunk(500, function ($orders) {
                foreach ($orders as $order) {
                    if (blank($order->tracking_number)) {
                        continue;
                    }

                    DB::table('outbounds')
                        ->whereNull('shipment_order_id')
                        ->where('tracking_number', $order->tracking_number)
                        ->update(['shipment_order_id' => $order->id]);
                }
            });
    }
};
