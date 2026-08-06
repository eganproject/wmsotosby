<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\OutboundItem;
use App\Models\Product;
use App\Models\ShipmentOrder;
use App\Support\NormalizesScanCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur verifikasi pengiriman marketplace.
 *
 * Urutannya wajib: resi discan lebih dulu, baru barang boleh discan. Dokumen
 * hanya bisa diproses setelah keduanya tuntas.
 */
class OutboundScanService
{
    use NormalizesScanCode;

    public function __construct(protected ShipmentOrderResolver $resolver)
    {
    }

    /**
     * Scan resi.
     *
     * Sistem mencari nomor resi ke data import Ginee lebih dulu. Bila
     * ditemukan, daftar barang yang harus discan diambil dari sana sehingga
     * yang diverifikasi adalah isi pesanan sebenarnya, bukan input manual.
     *
     * @return array<string, mixed>
     */
    public function verifyResi(Outbound $outbound, string $code): array
    {
        $this->guardScannable($outbound);

        if (blank($outbound->tracking_number)) {
            throw ValidationException::withMessages([
                'code' => 'Dokumen ini belum memiliki nomor resi.',
            ]);
        }

        if ($outbound->isResiVerified()) {
            throw ValidationException::withMessages([
                'code' => 'Resi sudah diverifikasi sebelumnya.',
            ]);
        }

        if ($this->normalize($code) !== $this->normalize($outbound->tracking_number)) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak cocok dengan dokumen ini.',
            ]);
        }

        $message = 'Resi terverifikasi. Silakan lanjutkan scan barang.';

        if ($order = $this->resolver->resolve($code)) {
            $synced = $this->syncItemsFromImport($outbound, $order);

            $message = $synced
                ? "Resi cocok dengan data import ({$order->order_number}). {$synced} baris barang diambil dari pesanan."
                : "Resi terverifikasi dan cocok dengan data import ({$order->order_number}).";
        }

        $outbound->forceFill(['resi_verified_at' => now()])->save();

        return [
            'message' => $message,
            'resi_verified' => true,
        ];
    }

    /**
     * Ganti baris dokumen dengan isi pesanan hasil import.
     *
     * @return int Jumlah baris yang diambil, 0 bila isinya sudah sama.
     */
    protected function syncItemsFromImport(Outbound $outbound, ShipmentOrder $order): int
    {
        $lines = $this->resolver->toOutboundLines($order);

        $current = $outbound->items
            ->map(fn (OutboundItem $item) => $item->product_id.'x'.$item->quantity)
            ->sort()
            ->values()
            ->all();

        $incoming = collect($lines)
            ->map(fn (array $line) => $line['product_id'].'x'.$line['quantity'])
            ->sort()
            ->values()
            ->all();

        if ($current === $incoming) {
            return 0;
        }

        DB::transaction(function () use ($outbound, $order, $lines) {
            $outbound->items()->delete();
            $outbound->items()->createMany($lines);
            $outbound->forceFill(['shipment_order_id' => $order->id])->save();
        });

        $outbound->load('items.product');

        return count($lines);
    }

    /**
     * Scan barang. Satu scan menambah satu unit, kecuali jumlahnya disebut.
     *
     * Kode yang discan dicocokkan ke dua kolom pada master barang: barcode
     * lebih dulu, lalu SKU. Barang tanpa barcode tetap bisa discan memakai
     * SKU-nya.
     *
     * @return array<string, mixed>
     */
    public function scanItem(Outbound $outbound, string $code, int $quantity = 1): array
    {
        $this->guardScannable($outbound);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'code' => 'Jumlah scan minimal 1.',
            ]);
        }

        // Inti aturannya: barang tidak boleh discan sebelum resi diverifikasi.
        if (! $outbound->isResiVerified()) {
            throw ValidationException::withMessages([
                'code' => 'Scan resi terlebih dahulu sebelum scan barang.',
            ]);
        }

        $needle = $this->normalize($code);

        if ($needle === '') {
            throw ValidationException::withMessages([
                'code' => 'Kode tidak terbaca. Ulangi scan pada barang.',
            ]);
        }

        $matchedField = null;

        $item = $outbound->items->first(function (OutboundItem $item) use ($needle, &$matchedField) {
            $field = $this->matchField($item->product, $needle);

            if ($field !== null) {
                $matchedField = $field;
            }

            return $field !== null;
        });

        if (! $item) {
            throw ValidationException::withMessages([
                'code' => $this->explainUnknownCode($code, $needle),
            ]);
        }

        if ($item->isFullyScanned()) {
            throw ValidationException::withMessages([
                'code' => "{$item->product->name} (SKU {$item->product->sku}) sudah discan sesuai jumlah ({$item->quantity} {$item->product->unit}).",
            ]);
        }

        // Pesanan borongan discan sekali lalu jumlahnya disebut, bukan
        // dipindai seratus kali. Yang tidak boleh adalah menyebut lebih
        // banyak daripada yang diminta pesanan.
        $remaining = $item->quantity - $item->scanned_quantity;

        if ($quantity > $remaining) {
            throw ValidationException::withMessages([
                'code' => "{$item->product->name} (SKU {$item->product->sku}) sisa {$remaining} {$item->product->unit} lagi, tidak bisa menambah {$quantity}.",
            ]);
        }

        // Barang yang stoknya tidak tercatat tidak boleh keluar. Diperiksa
        // ulang di sini karena stok bisa habis oleh paket lain setelah resi
        // ini dibuka.
        if ($item->product->stock < $item->quantity) {
            throw ValidationException::withMessages([
                'code' => $this->shortageMessage($item->product, $item->quantity),
            ]);
        }

        DB::transaction(function () use ($item, $quantity) {
            $item->increment('scanned_quantity', $quantity);
        });

        $outbound->load('items.product');
        $item->refresh();

        return [
            'message' => "{$item->product->name} — {$item->scanned_quantity}/{$item->quantity} {$item->product->unit}"
                .($quantity > 1 ? " (+{$quantity})" : ''),
            'matched_by' => $matchedField,
            'sku' => $item->product->sku,
            'item_id' => $item->id,
            'completed' => $item->isFullyScanned(),
        ];
    }

    /**
     * Barang pada pesanan yang stoknya tidak mencukupi.
     *
     * Dipakai sebelum paket dibuka, supaya operator tidak terlanjur men-scan
     * paket yang tidak akan bisa dikirim.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, string>
     */
    public function stockShortages(array $lines): array
    {
        $needed = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('quantity'));

        return Product::whereIn('id', $needed->keys())
            ->get()
            ->filter(fn (Product $product) => $product->stock < $needed[$product->id])
            ->map(fn (Product $product) => $this->shortageMessage($product, $needed[$product->id]))
            ->values()
            ->all();
    }

    /**
     * Satu kalimat untuk stok yang kurang, dengan angka yang bisa ditindak.
     */
    protected function shortageMessage(Product $product, int $needed): string
    {
        if ($product->stock < 1) {
            return "Stok {$product->name} (SKU {$product->sku}) kosong. "
                .'Catat barang masuknya dulu sebelum barang ini bisa discan.';
        }

        return "Stok {$product->name} (SKU {$product->sku}) tidak mencukupi: "
            ."butuh {$needed}, tersedia {$product->stock} {$product->unit}.";
    }

    /**
     * Kolom mana pada barang yang cocok dengan kode: barcode, SKU, atau tidak
     * sama sekali. Kolom kosong tidak pernah dianggap cocok.
     */
    protected function matchField(Product $product, string $needle): ?string
    {
        foreach (['barcode', 'sku'] as $field) {
            $value = $this->normalize($product->{$field});

            if ($value !== '' && $value === $needle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Pesan yang menjelaskan kenapa kode ditolak, supaya operator tahu apa
     * yang harus dilakukan alih-alih menebak.
     */
    protected function explainUnknownCode(string $code, string $needle): string
    {
        // Dicari langsung di database agar tidak memuat seluruh master barang.
        $product = Product::findByCode($needle);

        if ($product) {
            return "{$product->name} (SKU {$product->sku}) tidak termasuk dalam pesanan ini.";
        }

        return "Kode {$code} tidak dikenali sebagai barcode maupun SKU barang mana pun.";
    }

    /**
     * Ulangi proses scan dari awal.
     */
    public function reset(Outbound $outbound): void
    {
        $this->guardScannable($outbound);

        DB::transaction(function () use ($outbound) {
            $outbound->items()->update(['scanned_quantity' => 0]);
            $outbound->forceFill(['resi_verified_at' => null])->save();
        });
    }

    /**
     * Ringkasan progres untuk ditampilkan setelah setiap scan.
     *
     * @return array<string, mixed>
     */
    public function progress(Outbound $outbound): array
    {
        $outbound->load('items.product');

        return [
            'resi_verified' => $outbound->isResiVerified(),
            'total' => $outbound->totalQuantity(),
            'scanned' => $outbound->totalScanned(),
            'percentage' => $outbound->scanPercentage(),
            'ready' => $outbound->isReadyToPost(),
            'items' => $outbound->items->map(fn (OutboundItem $item) => [
                'id' => $item->id,
                'scanned' => $item->scanned_quantity,
                'quantity' => $item->quantity,
                'completed' => $item->isFullyScanned(),
            ])->all(),
        ];
    }

    protected function guardScannable(Outbound $outbound): void
    {
        if (! $outbound->isMarketplace()) {
            throw ValidationException::withMessages([
                'code' => 'Verifikasi scan hanya berlaku untuk pengiriman marketplace.',
            ]);
        }

        if ($outbound->isPosted()) {
            throw ValidationException::withMessages([
                'code' => 'Dokumen sudah diproses dan tidak bisa discan lagi.',
            ]);
        }
    }
}
