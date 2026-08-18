<?php

namespace App\Http\Requests\Admin;

use App\Models\Outbound;
use App\Models\ShipmentOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('outbounds.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isMarketplace = $this->input('type') === Outbound::TYPE_MARKETPLACE;

        return [
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in([Outbound::TYPE_REGULAR, Outbound::TYPE_MARKETPLACE])],
            'recipient' => ['required', 'string', 'max:255'],

            // Marketplace wajib punya identitas toko dan nomor resi, karena
            // keduanya yang nanti diverifikasi lewat scan.
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
                Rule::unique('outbounds', 'tracking_number'),
            ],

            'note' => ['nullable', 'string', 'max:1000'],

            // Baris barang boleh dikosongkan bila resinya sudah ada di data
            // import — isinya diambil otomatis saat scan resi — atau bila
            // dokumennya memang hanya berisi paket.
            'items' => [
                $this->resiExistsInImport() || filled($this->input('bundles')) ? 'nullable' : 'required',
                'array',
            ],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.note' => ['nullable', 'string', 'max:255'],

            // Paket bundling dipesan sebagai barisnya sendiri, lalu dipecah
            // menjadi barang saat disimpan. Barang keluar adalah satu-satunya
            // dokumen tempat paket boleh muncul.
            'bundles' => ['nullable', 'array'],
            'bundles.*.bundle_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'bundles.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * Baris yang dipesan sebagai paket harus benar-benar paket.
     *
     * Diperiksa dengan satu kueri untuk seluruh baris. Kesalahannya
     * ditempelkan pada barisnya masing-masing supaya editor menyorot baris
     * yang salah, bukan menampilkan satu pesan di kepala formulir.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $validator) {
            $ids = collect($this->input('bundles', []))
                ->pluck('bundle_id')
                ->filter()
                ->map(fn ($id) => (int) $id);

            if ($ids->isEmpty()) {
                return;
            }

            $bundles = \App\Models\Product::bundles()->whereIn('id', $ids->unique())->pluck('id');

            foreach ($ids as $index => $id) {
                if (! $bundles->contains($id)) {
                    $validator->errors()->add(
                        "bundles.{$index}.bundle_id",
                        'Baris ini bukan paket bundling. Pilih barangnya lewat baris barang biasa.',
                    );
                }
            }
        });
    }

    /**
     * Nomor resi yang diketik sudah pernah diimport dari Ginee?
     */
    protected function resiExistsInImport(): bool
    {
        return $this->input('type') === Outbound::TYPE_MARKETPLACE
            && ShipmentOrder::findByTrackingNumber($this->input('tracking_number')) !== null;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($item) => filled($item['product_id'] ?? null))
            ->values()
            ->all();

        $bundles = collect($this->input('bundles', []))
            ->filter(fn ($row) => filled($row['bundle_id'] ?? null))
            ->values()
            ->all();

        $payload = ['items' => $items, 'bundles' => $bundles];

        // Data marketplace tidak relevan untuk pengiriman reguler.
        if ($this->input('type') !== Outbound::TYPE_MARKETPLACE) {
            $payload['marketplace'] = null;
            $payload['tracking_number'] = null;
        } else {
            $payload['tracking_number'] = trim((string) $this->input('tracking_number')) ?: null;
        }

        $this->merge($payload);
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
            'marketplace.required' => 'Pilih marketplace tujuan pengiriman.',
            'tracking_number.required' => 'Nomor resi wajib diisi untuk pengiriman marketplace.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'tanggal',
            'type' => 'jenis pengiriman',
            'recipient' => 'penerima',
            'marketplace' => 'marketplace',
            'tracking_number' => 'nomor resi',
            'note' => 'catatan',
        ];
    }
}
