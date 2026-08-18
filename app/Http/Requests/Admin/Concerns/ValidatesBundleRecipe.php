<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Product;
use App\Rules\NotABundle;
use Illuminate\Validation\Rule;

/**
 * Aturan penyusunan resep paket bundling, dipakai form tambah dan ubah.
 */
trait ValidatesBundleRecipe
{
    /**
     * @return array<string, mixed>
     */
    protected function recipeRules(?int $productId = null): array
    {
        return [
            'type' => ['required', Rule::in([Product::TYPE_SINGLE, Product::TYPE_BUNDLE])],

            /*
                Paket tanpa isi tidak bisa dijual maupun dipecah, jadi tidak
                ada gunanya menyimpannya sebagai paket.

                Aturannya dipilih, bukan disyaratkan bersama: prepareRecipe()
                selalu menyetel components menjadi array — kosong untuk barang
                biasa — dan `min:1` tetap berjalan atas array kosong yang
                memang hadir. Menyusunnya sebagai requiredIf + min:1 karenanya
                menolak setiap penyimpanan barang biasa, bukan hanya paket
                yang belum ada isinya.
            */
            'components' => $this->wantsBundle()
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],

            'components.*.component_id' => [
                'required',
                'integer',
                // Barang yang sama tidak boleh dua baris: jumlahnya per paket
                // adalah satu angka, dan tabelnya pun unik per pasangan.
                'distinct',
                'exists:products,id',
                // Paket di dalam paket dilarang di sini, bukan hanya di
                // BundleExploder — di sana ia lapis terakhir, di sini ia
                // pesan yang bisa dibaca saat orangnya masih di formulir.
                new NotABundle('isi paket'),
                Rule::notIn(array_filter([$productId])),
            ],

            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    protected function wantsBundle(): bool
    {
        return $this->input('type') === Product::TYPE_BUNDLE;
    }

    /**
     * Baris kosong dibuang, dan resep barang biasa dikosongkan seluruhnya.
     */
    protected function prepareRecipe(): void
    {
        $components = $this->wantsBundle()
            ? collect($this->input('components', []))
                ->filter(fn ($row) => filled($row['component_id'] ?? null))
                ->values()
                ->all()
            : [];

        $this->merge([
            'type' => $this->input('type') === Product::TYPE_BUNDLE
                ? Product::TYPE_BUNDLE
                : Product::TYPE_SINGLE,
            'components' => $components,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function recipeMessages(): array
    {
        return [
            'components.required' => 'Paket harus punya isi. Tambahkan minimal satu barang.',
            'components.min' => 'Paket harus punya isi. Tambahkan minimal satu barang.',
            'components.*.component_id.required' => 'Barang pada salah satu baris isi paket belum dipilih.',
            'components.*.component_id.distinct' => 'Barang yang sama disebut dua kali. Gabungkan jumlahnya menjadi satu baris.',
            'components.*.component_id.not_in' => 'Paket tidak boleh memuat dirinya sendiri.',
            'components.*.quantity.min' => 'Jumlah per paket minimal 1.',
        ];
    }
}
