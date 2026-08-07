<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inbounds.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.damaged_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Yang rusak adalah bagian dari yang diterima, bukan tambahan di luarnya.
     *
     * Diperiksa di sini, bukan lewat aturan lte: pesannya jadi menyebut baris
     * yang mana, dan aturan bawaan tidak menangani rujukan antar baris berindeks
     * dengan andal.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $index => $item) {
                $damaged = (int) ($item['damaged_quantity'] ?? 0);

                if ($damaged > (int) ($item['quantity'] ?? 0)) {
                    $validator->errors()->add(
                        "items.{$index}.damaged_quantity",
                        'Jumlah rusak tidak boleh melebihi jumlah yang diterima.',
                    );
                }
            }
        });
    }

    /**
     * Baris kosong dari form dinamis dibuang sebelum divalidasi.
     */
    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => filled($item['product_id'] ?? null))
            ->values()
            ->all();

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu baris barang.',
            'items.min' => 'Tambahkan minimal satu baris barang.',
            'items.*.product_id.required' => 'Barang pada salah satu baris belum dipilih.',
            'items.*.quantity.min' => 'Jumlah pada setiap baris minimal 1.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'tanggal',
            'supplier_id' => 'pemasok',
            'reference' => 'nomor referensi',
            'note' => 'catatan',
        ];
    }
}
