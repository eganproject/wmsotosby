<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Support\BundledLines;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat paket bundling berubah menjadi barang nyata.
 *
 * Pemecahannya sengaja terjadi saat baris dokumen dibentuk, bukan saat
 * dokumen diposting. Akibatnya outbound_items tidak pernah memuat paket —
 * ia tetap berupa daftar barang yang benar-benar punya saldo. StockService,
 * pemeriksaan stok, kartu stok, dan laporan restock karenanya tidak perlu
 * tahu bahwa paket itu ada sama sekali.
 *
 * Bila pemecahannya ditunda sampai posting, keempatnya harus diajari soal
 * paket satu per satu, dan angka "sudah dijanjikan ke pembeli" di laporan
 * restock akan salah selama dokumennya masih draft.
 */
class BundleExploder
{
    /**
     * Pecah baris yang berisi paket menjadi baris komponennya.
     *
     * Barang yang datang dari paket dan barang yang sama yang dipesan satuan
     * digabung menjadi satu baris. Ini bukan soal kerapian tampilan: stasiun
     * scan mencari baris dengan mengambil yang pertama cocok, jadi dua baris
     * untuk barang yang sama membuat scan mengisi baris pertama sampai penuh
     * lalu menolak sisanya dengan "sudah lengkap" — padahal masih ada unit
     * yang harus masuk paket.
     *
     * @param  array<int, array<string, mixed>>  $lines  baris mentah: product_id, quantity, note
     */
    public function explode(array $lines): BundledLines
    {
        $lines = collect($lines)
            ->filter(fn (array $line) => filled($line['product_id'] ?? null) && (int) ($line['quantity'] ?? 0) > 0)
            ->values();

        if ($lines->isEmpty()) {
            return BundledLines::empty();
        }

        $products = $this->load($lines->pluck('product_id'));

        $items = [];
        $bundles = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity = (int) $line['quantity'];
            $product = $products->get($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'Ada baris yang menunjuk barang yang sudah tidak ada. Muat ulang halamannya.',
                ]);
            }

            if (! $product->isBundle()) {
                $items[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'note' => $line['note'] ?? null,
                ];

                continue;
            }

            $components = $this->componentsOf($product);

            foreach ($components as $component) {
                $items[] = [
                    'product_id' => $component->component_id,
                    'quantity' => $component->quantity * $quantity,
                    // Asal-usulnya disebut pada barisnya sendiri supaya daftar
                    // barang tetap bisa dibaca tanpa membuka rincian paket.
                    'note' => $product->sku,
                ];
            }

            $bundles[] = [
                'bundle_id' => $productId,
                'quantity' => $quantity,
                'composition' => $this->snapshot($components),
            ];
        }

        return new BundledLines($this->mergeItems($items), $this->mergeBundles($bundles));
    }

    /**
     * Muat seluruh barang yang disebut sekaligus, berikut isinya bila paket.
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, Product>
     */
    protected function load(Collection $ids): Collection
    {
        return Product::query()
            ->with(['bundleComponents' => fn ($query) => $query->orderBy('id'), 'bundleComponents.component'])
            ->whereIn('id', $ids->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');
    }

    /**
     * Isi paket, setelah dipastikan benar-benar bisa dipecah.
     *
     * @return Collection<int, ProductBundleItem>
     */
    protected function componentsOf(Product $bundle): Collection
    {
        $components = $bundle->bundleComponents;

        if ($components->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => "Paket {$bundle->sku} belum ditentukan isinya. Lengkapi dulu di master barang.",
            ]);
        }

        /*
            Paket di dalam paket ditolak, bukan dipecah berulang.

            Penyusunan resep sudah melarangnya, jadi sampai di sini hanya bisa
            terjadi bila datanya berubah lewat jalan lain. Membiarkannya
            berarti memilih antara rekursi tanpa dasar atau baris dokumen yang
            berisi paket — dan yang kedua akan tertahan di posting setelah
            barangnya telanjur turun dari rak.
        */
        $nested = $components->first(fn (ProductBundleItem $item) => $item->component?->isBundle());

        if ($nested) {
            throw ValidationException::withMessages([
                'items' => "Paket {$bundle->sku} memuat paket lain ({$nested->component->sku}). Susun ulang isinya memakai barang satuan.",
            ]);
        }

        $missing = $components->first(fn (ProductBundleItem $item) => $item->component === null);

        if ($missing) {
            throw ValidationException::withMessages([
                'items' => "Isi paket {$bundle->sku} menunjuk barang yang sudah tidak ada. Perbaiki dulu di master barang.",
            ]);
        }

        return $components;
    }

    /**
     * Salinan resep untuk disimpan bersama dokumennya.
     *
     * SKU dan nama ikut disalin, bukan hanya id: dokumen lama harus tetap
     * terbaca meskipun barangnya kelak berganti nama atau dihapus.
     *
     * @param  Collection<int, ProductBundleItem>  $components
     * @return array<int, array<string, mixed>>
     */
    protected function snapshot(Collection $components): array
    {
        return $components->map(fn (ProductBundleItem $item) => [
            'product_id' => $item->component_id,
            'sku' => $item->component->sku,
            'name' => $item->component->name,
            'quantity' => $item->quantity,
        ])->values()->all();
    }

    /**
     * Satu baris per barang, jumlahnya dijumlahkan.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function mergeItems(array $items): array
    {
        return collect($items)
            ->groupBy('product_id')
            ->map(fn (Collection $group, $productId) => [
                'product_id' => (int) $productId,
                'quantity' => (int) $group->sum('quantity'),
                // Dipotong pada lebar kolomnya. Satu barang bisa datang dari
                // beberapa paket sekaligus pada satu dokumen, dan catatan
                // gabungan yang melewati batas kolom akan menggagalkan
                // penyimpanan seluruh dokumen — kegagalan keras untuk
                // keterangan yang sifatnya hanya membantu membaca.
                'note' => Str::limit($group->pluck('note')->filter()->unique()->implode(', '), 250) ?: null,
            ])
            ->values()
            ->all();
    }

    /**
     * Satu baris per paket. Paket yang sama disebut dua kali dijumlahkan,
     * karena tabelnya memang unik per dokumen dan paket.
     *
     * @param  array<int, array<string, mixed>>  $bundles
     * @return array<int, array<string, mixed>>
     */
    protected function mergeBundles(array $bundles): array
    {
        return collect($bundles)
            ->groupBy('bundle_id')
            ->map(fn (Collection $group, $bundleId) => [
                'bundle_id' => (int) $bundleId,
                'quantity' => (int) $group->sum('quantity'),
                'composition' => $group->first()['composition'],
            ])
            ->values()
            ->all();
    }
}
