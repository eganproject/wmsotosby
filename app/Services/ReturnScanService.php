<?php

namespace App\Services;

use App\Models\ReturnReceipt;
use App\Models\ShipmentOrder;
use App\Support\NormalizesScanCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi resi pada penerimaan retur.
 *
 * Retur hanya perlu satu tahap: memastikan paket yang datang benar-benar
 * milik dokumen ini sebelum barangnya diterima kembali ke stok.
 */
class ReturnScanService
{
    use NormalizesScanCode;

    public function __construct(protected ShipmentOrderResolver $resolver)
    {
    }

    /**
     * Scan resi retur.
     *
     * Nomor resi dicari lebih dulu ke data import Ginee. Bila ketemu, isi
     * paket diambil otomatis dari pesanan aslinya; bila tidak, baris barang
     * tetap memakai apa yang diinput operator.
     *
     * @return array<string, mixed>
     */
    public function verifyResi(ReturnReceipt $return, string $code): array
    {
        $this->guardScannable($return);

        if (blank($return->tracking_number)) {
            throw ValidationException::withMessages([
                'code' => 'Dokumen ini tidak memiliki nomor resi retur.',
            ]);
        }

        if ($return->isResiVerified()) {
            throw ValidationException::withMessages([
                'code' => 'Resi sudah diverifikasi sebelumnya.',
            ]);
        }

        if ($this->normalize($code) !== $this->normalize($return->tracking_number)) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak cocok dengan dokumen retur ini.',
            ]);
        }

        $message = 'Resi terverifikasi. Barang retur siap diterima.';

        if ($order = $this->resolver->resolve($code)) {
            $taken = $this->adoptItemsFromImport($return, $order);

            $message = $taken > 0
                ? "Resi cocok dengan data import ({$order->order_number}). {$taken} baris barang diambil otomatis dari pesanan."
                : "Resi terverifikasi dan cocok dengan data import ({$order->order_number}).";
        }

        $return->forceFill(['resi_verified_at' => now()])->save();

        return [
            'message' => $message,
            'resi_verified' => true,
        ];
    }

    /**
     * Ambil isi pesanan dari data import.
     *
     * Baris yang sudah diinput operator tidak ditimpa — retur sering hanya
     * sebagian dari pesanan, jadi input manual dianggap lebih tahu.
     *
     * @return int Jumlah baris yang diambil, 0 bila dokumen sudah berisi.
     */
    protected function adoptItemsFromImport(ReturnReceipt $return, ShipmentOrder $order): int
    {
        $return->loadMissing('items');

        if ($return->items->isNotEmpty()) {
            $return->forceFill(['shipment_order_id' => $order->id])->save();

            return 0;
        }

        $lines = $this->resolver->toReturnLines($order);

        DB::transaction(function () use ($return, $order, $lines) {
            $return->items()->createMany($lines);
            $return->forceFill(['shipment_order_id' => $order->id])->save();
        });

        $return->load('items.product');

        return count($lines);
    }

    public function reset(ReturnReceipt $return): void
    {
        $this->guardScannable($return);

        $return->forceFill(['resi_verified_at' => null])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(ReturnReceipt $return): array
    {
        $return->load('items');

        return [
            'resi_verified' => $return->isResiVerified(),
            'total' => $return->totalQuantity(),
            'scanned' => $return->isResiVerified() ? $return->totalQuantity() : 0,
            'percentage' => $return->isResiVerified() ? 100 : 0,
            'ready' => $return->isReadyToPost(),
            'items' => [],
        ];
    }

    protected function guardScannable(ReturnReceipt $return): void
    {
        if (! $return->requiresResiScan()) {
            throw ValidationException::withMessages([
                'code' => 'Dokumen retur ini tidak memerlukan verifikasi resi.',
            ]);
        }

        if ($return->isPosted()) {
            throw ValidationException::withMessages([
                'code' => 'Dokumen sudah diproses dan tidak bisa discan lagi.',
            ]);
        }
    }
}
