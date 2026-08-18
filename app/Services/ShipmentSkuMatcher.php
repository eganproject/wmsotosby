<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\ShipmentOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cocokkan ulang SKU pesanan ke master barang.
 *
 * Pencocokan hanya terjadi sekali, saat berkas Ginee diunggah. Resi yang masuk
 * sebelum barangnya didaftarkan karena itu menggantung dengan product_id
 * kosong, dan tetap begitu meskipun barangnya kemudian dibuat.
 *
 * Yang dikerjakan di sini persis pencarian yang sama, dijalankan ulang. Ia
 * aman diulang berapa kali pun karena hanya mengisi yang kosong:
 *
 *   - baris yang sudah menunjuk barang tidak pernah disentuh, sehingga
 *     dokumen yang sudah dibentuk dari resi itu tidak bisa berubah arti;
 *   - stok tidak bergerak sama sekali — yang berubah hanya tautan antara
 *     baris pesanan dan master barang;
 *   - SKU yang menjadi rancu setelah dinormalkan sengaja dilewati, bukan
 *     ditebak.
 */
class ShipmentSkuMatcher
{
    /**
     * SKU yang belum menunjuk barang mana pun, beserta jumlah barisnya.
     *
     * @return Collection<string, int>
     */
    public function unmatchedSkus(?ShipmentOrder $order = null): Collection
    {
        return $this->pending($order)
            ->get(['sku'])
            ->groupBy(fn (ShipmentOrderItem $item) => $this->normalize($item->sku))
            ->map(fn (Collection $rows) => $rows->count())
            ->sortDesc();
    }

    /**
     * Di antara SKU itu, mana yang kini sudah ada di master barang.
     *
     * @param  Collection<string, int>  $unmatched
     * @return Collection<string, Product>
     */
    public function resolvable(Collection $unmatched, bool $bundlesOnly = false): Collection
    {
        if ($unmatched->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->when($bundlesOnly, fn (Builder $query) => $query->bundles())
            ->get(['id', 'sku', 'name', 'type'])
            ->groupBy(fn (Product $product) => $this->normalize($product->sku))
            /*
                SKU yang menjadi rancu setelah dinormalkan tidak dicocokkan.

                Kolom sku memang unik, tetapi keunikannya berlaku atas teks apa
                adanya — "FLT-001" dan "flt-001 " lolos berdua. Menebak salah
                satunya berarti menautkan pesanan ke barang yang mungkin bukan
                yang dimaksud, dan tautan itu kelak menjadi dokumen yang
                menurunkan barang dari rak. Lebih baik dilewati dan disebutkan.
            */
            ->reject(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group) => $group->first())
            ->intersectByKeys($unmatched);
    }

    /**
     * SKU yang ada di master barang lebih dari sekali setelah dinormalkan.
     *
     * @param  Collection<string, int>  $unmatched
     * @return Collection<string, Collection<int, Product>>
     */
    public function ambiguous(Collection $unmatched): Collection
    {
        if ($unmatched->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->get(['id', 'sku', 'name'])
            ->groupBy(fn (Product $product) => $this->normalize($product->sku))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->intersectByKeys($unmatched);
    }

    /**
     * Tulis pencocokannya.
     *
     * @return array{rows: int, skus: int, remaining: Collection<string, int>, ambiguous: Collection<string, Collection<int, Product>>}
     */
    public function match(?ShipmentOrder $order = null, bool $bundlesOnly = false): array
    {
        $unmatched = $this->unmatchedSkus($order);
        $resolvable = $this->resolvable($unmatched, $bundlesOnly);
        $ambiguous = $this->ambiguous($unmatched);

        $rows = 0;

        if ($resolvable->isNotEmpty()) {
            $rows = DB::transaction(function () use ($order, $resolvable) {
                $written = 0;

                foreach ($resolvable as $sku => $product) {
                    $written += $this->pending($order)
                        ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
                        ->update(['product_id' => $product->id]);
                }

                $this->refreshImportCounters($order);

                return $written;
            });
        }

        return [
            'rows' => $rows,
            'skus' => $resolvable->count(),
            // Dihitung ulang setelah menulis, bukan dikurangkan dari angka
            // sebelumnya: yang dilaporkan harus keadaan sesungguhnya.
            'remaining' => $this->unmatchedSkus($order),
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Baris pesanan yang masih menunggu dicocokkan.
     */
    protected function pending(?ShipmentOrder $order = null): Builder
    {
        return ShipmentOrderItem::query()
            ->whereNull('product_id')
            ->when($order, fn (Builder $query) => $query->where('shipment_order_id', $order->id));
    }

    /**
     * Samakan kembali angka SKU belum cocok pada berkas importnya.
     *
     * Angka itu didenormalisasi di shipment_imports dan dibaca kartu ringkasan
     * serta halaman riwayat berkas. Tanpa perhitungan ulang, resi yang baru
     * saja cocok tetap dilaporkan bermasalah di dua halaman lain — dan angka
     * yang berbeda antar halaman lebih membingungkan daripada angka yang salah
     * di satu tempat.
     *
     * Yang dihitung ulang hanya berkas yang benar-benar memuat pesanan
     * terdampak, bukan seluruh riwayat.
     */
    protected function refreshImportCounters(?ShipmentOrder $order = null): void
    {
        $pending = DB::table('shipment_order_items as soi')
            ->join('shipment_orders as so', 'so.id', '=', 'soi.shipment_order_id')
            ->whereNull('soi.product_id')
            ->whereNotNull('so.shipment_import_id')
            ->groupBy('so.shipment_import_id')
            ->select('so.shipment_import_id')
            ->selectRaw('COUNT(*) as pending')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->shipment_import_id => (int) $row->pending]);

        $current = ShipmentImport::query()
            ->when($order, fn (Builder $query) => $query->whereKey($order->shipment_import_id))
            ->pluck('unmatched_sku_count', 'id');

        // Berkas yang angkanya memang sudah benar sengaja tidak disentuh,
        // supaya updated_at-nya tidak bergeser tanpa ada yang berubah.
        foreach ($current as $id => $stored) {
            $fresh = $pending[(int) $id] ?? 0;

            if ((int) $stored !== $fresh) {
                ShipmentImport::whereKey($id)->update(['unmatched_sku_count' => $fresh]);
            }
        }
    }

    /**
     * Bentuk baku sebuah SKU: huruf besar, tanpa spasi di ujung.
     *
     * Sama persis dengan yang dipakai importer saat menyusun petanya, supaya
     * keduanya tidak pernah berbeda pendapat tentang dua SKU yang sama.
     */
    protected function normalize(?string $sku): string
    {
        return strtoupper(trim((string) $sku));
    }
}
