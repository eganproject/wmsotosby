<?php

namespace App\Services;

use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use App\Support\BundledLines;
use Illuminate\Validation\ValidationException;

/**
 * Jembatan antara data resi hasil import Ginee dan dokumen gudang.
 *
 * Pencocokan barang sepenuhnya bertumpu pada SKU: SKU pada baris pesanan
 * harus ada di master barang sebelum dokumen bisa dibentuk otomatis.
 *
 * Marketplace menjual sebagian barang sebagai paket, dan berkas Ginee
 * menuliskannya sebagai satu SKU utuh. Di sinilah SKU itu diterjemahkan
 * menjadi barang yang benar-benar ada di rak — sebelum satu baris dokumen
 * pun ditulis, sehingga seluruh hilir hanya pernah melihat barang nyata.
 */
class ShipmentOrderResolver
{
    public function __construct(protected BundleExploder $exploder)
    {
    }

    public function resolve(?string $trackingNumber): ?ShipmentOrder
    {
        return ShipmentOrder::findByTrackingNumber($trackingNumber);
    }

    /**
     * Baris barang untuk dokumen barang keluar, berikut paket asalnya.
     */
    public function toOutboundLines(ShipmentOrder $order): BundledLines
    {
        $this->guardAllSkusMatched($order);

        $lines = $this->exploder->explode($this->rawLines($order));

        return new BundledLines(
            array_map(fn (array $line) => $line + ['scanned_quantity' => 0], $lines->items),
            $lines->bundles,
        );
    }

    /**
     * Baris barang untuk dokumen penerimaan retur. Kondisi awal dianggap
     * layak jual dan bisa diubah operator sebelum retur diterima.
     *
     * Paket dipecah di sini juga: yang kembali ke rak adalah barang isinya,
     * dan kondisinya dinilai satu per satu — paket yang isinya sebagian utuh
     * dan sebagian penyok memang tidak bisa digambarkan sebagai satu baris.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toReturnLines(ShipmentOrder $order): array
    {
        $this->guardAllSkusMatched($order);

        return array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            // Hasil pemeriksaan diisi operator; awalnya dianggap utuh.
            'good_quantity' => $line['quantity'],
            'damaged_quantity' => 0,
            'note' => $line['note'],
        ], $this->exploder->explode($this->rawLines($order))->items);
    }

    /**
     * Isi pesanan apa adanya, sebelum paket dipecah.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rawLines(ShipmentOrder $order): array
    {
        return $order->items->map(fn (ShipmentOrderItem $item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
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
                // Paket akan dipecah menjadi barang isinya saat dokumennya
                // dibentuk, jadi apa yang tampil di sini belum tentu sama
                // dengan apa yang nanti discan.
                'is_bundle' => (bool) $item->product?->isBundle(),
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
