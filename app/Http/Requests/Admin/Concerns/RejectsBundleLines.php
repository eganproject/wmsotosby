<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;

/**
 * Tolak paket bundling pada dokumen yang hanya mengurus barang berwujud.
 *
 * Paket tidak pernah ada di rak: ia tidak bisa diterima dari pemasok, tidak
 * bisa dihitung saat opname, tidak bisa disesuaikan saldonya, dan tidak bisa
 * dibuang. Yang bisa hanyalah barang isinya.
 *
 * Tanpa penjagaan ini dokumennya tetap bisa disusun dan baru gagal saat
 * disetujui — tertahan di StockService setelah barangnya telanjur diturunkan
 * atau dihitung. Ditolak di sini, kesalahannya ketahuan saat masih berupa
 * isian form.
 *
 * Trait ini sengaja TIDAK mendefinisikan withValidator(), melainkan menyediakan
 * satu metode yang dipanggil sendiri oleh requestnya. Sebagian request sudah
 * punya withValidator untuk keperluan lain, dan metode milik kelas mengalahkan
 * metode milik trait tanpa galat maupun peringatan — penjagaannya akan hilang
 * diam-diam, persis pada request yang paling ramai aturannya.
 */
trait RejectsBundleLines
{
    abstract protected function bundleLineAction(): string;

    /**
     * Seluruh baris diperiksa dengan satu kueri, bukan satu kueri per baris:
     * dokumen borongan lazim berisi puluhan baris, dan pemeriksaan yang tumbuh
     * mengikuti jumlah baris membuat penyimpanannya melambat justru pada
     * dokumen yang paling besar.
     */
    protected function rejectBundleLines(Validator $validator): void
    {
        $ids = collect($this->input('items', []))
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        if ($ids->isEmpty()) {
            return;
        }

        $bundles = Product::bundles()
            ->whereIn('id', $ids->unique())
            ->pluck('sku', 'id');

        if ($bundles->isEmpty()) {
            return;
        }

        // Kesalahan ditempelkan pada barisnya masing-masing supaya editor
        // menyorot baris yang salah, bukan sekadar menampilkan satu pesan
        // di kepala formulir.
        foreach ($ids as $index => $id) {
            if ($bundles->has($id)) {
                $validator->errors()->add(
                    "items.{$index}.product_id",
                    "{$bundles[$id]} adalah paket bundling dan tidak bisa dipakai pada {$this->bundleLineAction()}. Pilih barang isinya.",
                );
            }
        }
    }
}
