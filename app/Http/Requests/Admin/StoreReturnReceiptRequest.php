<?php

namespace App\Http\Requests\Admin;

use App\Models\Outbound;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Http\Requests\Admin\Concerns\RejectsBundleLines;
use App\Models\ShipmentOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnReceiptRequest extends FormRequest
{
    use RejectsBundleLines;

    protected function bundleLineAction(): string
    {
        return 'penerimaan retur';
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(fn ($validator) => $this->rejectBundleLines($validator));
    }

    public function authorize(): bool
    {
        return $this->user()->can('returns.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isMarketplace = $this->input('type') === ReturnReceipt::TYPE_MARKETPLACE;

        return [
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in([ReturnReceipt::TYPE_REGULAR, ReturnReceipt::TYPE_MARKETPLACE])],
            'sender' => ['required', 'string', 'max:255'],

            // Retur marketplace selalu datang lewat kurir, jadi marketplace dan
            // nomor resinya wajib. Retur biasa boleh tanpa resi (diantar langsung).
            'marketplace' => [
                Rule::requiredIf($isMarketplace),
                'nullable',
                Rule::in(Outbound::marketplaces()),
            ],
            'tracking_number' => [
                Rule::requiredIf($isMarketplace),
                'nullable',
                'string',
                'max:100',
                Rule::unique('return_receipts', 'tracking_number'),
            ],

            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', Rule::in(ReturnReceipt::reasons())],
            'note' => ['nullable', 'string', 'max:1000'],

            // Baris barang boleh dikosongkan bila resinya ada di data import —
            // isinya diambil otomatis saat scan resi.
            'items' => [$this->resiExistsInImport() ? 'nullable' : 'required', 'array'],
            // Retur marketplace lewat scan resi memecah paketnya sendiri; yang
            // dijaga di sini adalah isian manual, yang barangnya dipilih orang.
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.good_quantity' => ['required', 'integer', 'min:0'],
            'items.*.damaged_quantity' => ['required', 'integer', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Nomor resi retur yang diketik sudah pernah diimport dari Ginee?
     */
    protected function resiExistsInImport(): bool
    {
        return ShipmentOrder::findByTrackingNumber($this->input('tracking_number')) !== null;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => filled($item['product_id'] ?? null))
            ->map(function ($item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $damaged = (int) ($item['damaged_quantity'] ?? 0);

                // Bila hanya jumlah rusak yang diisi, sisanya layak jual.
                return $item + [
                    'good_quantity' => max(0, $quantity - $damaged),
                    'damaged_quantity' => $damaged,
                ];
            })
            ->values()
            ->all();

        $payload = ['items' => $items];

        if ($this->input('type') !== ReturnReceipt::TYPE_MARKETPLACE) {
            $payload['marketplace'] = null;
        }

        $payload['tracking_number'] = trim((string) $this->input('tracking_number')) ?: null;

        $this->merge($payload);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu baris barang retur.',
            'items.min' => 'Tambahkan minimal satu baris barang retur.',
            'items.*.product_id.required' => 'Barang pada salah satu baris belum dipilih.',
            'items.*.quantity.min' => 'Jumlah pada setiap baris minimal 1.',
            'items.*.good_quantity.min' => 'Jumlah layak jual tidak boleh negatif.',
            'items.*.damaged_quantity.min' => 'Jumlah rusak tidak boleh negatif.',
            'marketplace.required' => 'Pilih marketplace asal retur.',
            'tracking_number.required' => 'Nomor resi retur wajib diisi untuk retur marketplace.',
            'tracking_number.unique' => 'Nomor resi ini sudah dipakai dokumen retur lain.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'tanggal',
            'type' => 'jenis retur',
            'sender' => 'pengirim',
            'marketplace' => 'marketplace',
            'tracking_number' => 'nomor resi retur',
            'reference' => 'nomor pesanan',
            'reason' => 'alasan retur',
            'note' => 'catatan',
        ];
    }
}
