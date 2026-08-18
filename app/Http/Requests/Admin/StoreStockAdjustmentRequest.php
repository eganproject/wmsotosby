<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\RejectsBundleLines;
use App\Models\StockAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    use RejectsBundleLines;

    protected function bundleLineAction(): string
    {
        return 'penyesuaian stok';
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(fn ($validator) => $this->rejectBundleLines($validator));
    }

    public function authorize(): bool
    {
        return $this->user()->can('adjustments.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'reason' => ['required', Rule::in(StockAdjustment::reasons())],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.actual_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Baris kosong dari form dinamis dibuang sebelum divalidasi.
     */
    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => filled($item['product_id'] ?? null))
            ->map(fn ($item) => $item + ['actual_quantity' => 0])
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
            'items.required' => 'Tambahkan minimal satu barang yang dihitung.',
            'items.min' => 'Tambahkan minimal satu barang yang dihitung.',
            'items.*.product_id.required' => 'Barang pada salah satu baris belum dipilih.',
            'items.*.actual_quantity.required' => 'Isi hasil hitung fisik pada setiap baris.',
            'items.*.actual_quantity.min' => 'Hasil hitung fisik tidak boleh negatif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'tanggal',
            'reason' => 'alasan penyesuaian',
            'note' => 'catatan',
        ];
    }
}
