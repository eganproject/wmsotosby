<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => Str::upper(trim((string) $this->input('sku'))),
            'barcode' => filled($this->input('barcode')) ? trim((string) $this->input('barcode')) : null,
            'min_stock' => $this->input('min_stock', 0),
            'is_active' => $this->boolean('is_active'),
        ]);
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
