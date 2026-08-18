<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesBundleRecipe;
use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    use ValidatesBundleRecipe;

    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('product')->id;

        return [
            'sku' => ['required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:20'],
            'location' => ['nullable', 'string', 'max:100'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
        ] + $this->recipeRules($id);
    }

    /**
     * Barang yang sudah punya jejak stok tidak boleh berubah menjadi paket.
     *
     * Paket tidak punya saldo, jadi begitu jenisnya berganti, saldo yang
     * tersisa menjadi yatim: tidak bisa lagi digerakkan karena tertahan
     * penjagaan di StockService, tidak bisa dihitung saat opname, dan
     * catatannya di tabel sinkronisasi pusat membeku pada angka terakhir —
     * terus dibaca sistem seberang seolah masih benar.
     *
     * Kosongkan dulu stoknya lewat dokumen pengeluaran bila memang barang itu
     * hendak dijadikan paket. Jejak mutasinya sendiri sudah cukup menjadi
     * alasan menolak: kartu stoknya akan menggantung tanpa saldo yang
     * menjelaskannya.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $product = $this->route('product');

            if (! $this->wantsBundle() || $product->isBundle()) {
                return;
            }

            if ($product->stock > 0 || $product->damaged_stock > 0) {
                $validator->errors()->add('type',
                    "{$product->sku} masih punya stok ({$product->stock} {$product->unit}). "
                    .'Kosongkan dulu lewat dokumen pengeluaran sebelum menjadikannya paket.');

                return;
            }

            if ($product->movements()->exists()) {
                $validator->errors()->add('type',
                    "{$product->sku} sudah punya riwayat pergerakan stok dan tidak bisa dijadikan paket. "
                    .'Buat paket sebagai barang baru, lalu nonaktifkan yang lama.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => Str::upper(trim((string) $this->input('sku'))),
            'barcode' => filled($this->input('barcode')) ? trim((string) $this->input('barcode')) : null,
            'min_stock' => $this->input('min_stock', 0),
            'is_active' => $this->boolean('is_active'),
            // Jenis yang tidak dikirim berarti tidak diubah — form lama dan
            // pembaruan sebagian tidak boleh diam-diam mengembalikan paket
            // menjadi barang biasa.
            'type' => $this->input('type', $this->route('product')->type ?? Product::TYPE_SINGLE),
        ]);

        $this->prepareRecipe();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->recipeMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'barcode' => 'barcode',
            'name' => 'nama barang',
            'category' => 'kategori',
            'unit' => 'satuan',
            'location' => 'lokasi rak',
            'min_stock' => 'stok minimum',
        ];
    }
}
