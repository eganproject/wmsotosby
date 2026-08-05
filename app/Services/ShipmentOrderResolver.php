<?php

namespace App\Services;

use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use Illuminate\Validation\ValidationException;

/**
 * Jembatan antara data resi hasil import Ginee dan dokumen gudang.
 *
 * Pencocokan barang sepenuhnya bertumpu pada SKU: SKU pada baris pesanan
 * harus ada di master barang sebelum dokumen bisa dibentuk otomatis.
 */
class ShipmentOrderResolver
{
    public function resolve(?string $trackingNumber): ?ShipmentOrder
    {
        return ShipmentOrder::findByTrackingNumber($trackingNumber);
    }

    /**
     * Baris barang untuk dokumen barang keluar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOutboundLines(ShipmentOrder $order): array
    {
        $this->guardAllSkusMatched($order);

        return $order->items->map(fn (ShipmentOrderItem $item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'scanned_quantity' => 0,
            'note' => $item->sku,
        ])->all();
    }

    /**
     * Baris barang untuk dokumen penerimaan retur. Kondisi awal dianggap
     * layak jual dan bisa diubah operator sebelum retur diterima.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toReturnLines(ShipmentOrder $order): array
    {
        $this->guardAllSkusMatched($order);

        return $order->items->map(fn (ShipmentOrderItem $item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            // Hasil pemeriksaan diisi operator; awalnya dianggap utuh.
            'good_quantity' => $item->quantity,
            'damaged_quantity' => 0,
            'note' => $item->sku,
        ])->all();
    }

    /**
     * Bentuk ringkas untuk dipakai form (JSON).
     *
     * @return array<string, mixed>
     */
    public function toPayload(ShipmentOrder $order): array
    {
        return [
            'tracking_number' => $order->tracking_number,
            'order_number' => $order->order_number,
            'marketplace' => $order->marketplace,
            'store_name' => $order->store_name,
            'buyer_name' => $order->buyer_name,
            'order_status' => $order->order_status,
            'matched' => $order->isFullyMatched(),
            'items' => $order->items->map(fn (ShipmentOrderItem $item) => [
                'sku' => $item->sku,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name ?? $item->product_name,
                'quantity' => $item->quantity,
                'matched' => $item->isMatched(),
            ])->values()->all(),
        ];
    }

    /**
     * SKU yang tidak dikenali membuat dokumen tidak bisa dibentuk otomatis —
     * lebih baik gagal jelas daripada melewatkan barang.
     */
    protected function guardAllSkusMatched(ShipmentOrder $order): void
    {
        $order->loadMissing('items.product');

        $unmatched = $order->unmatchedItems();

        if ($unmatched->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => 'SKU berikut belum terdaftar di master barang: '
                .$unmatched->pluck('sku')->implode(', ')
                .'. Tambahkan barangnya terlebih dahulu.',
        ]);
    }
}
