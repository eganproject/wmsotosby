<?php

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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
 */
class NotABundle implements ValidationRule
{
    public function __construct(protected string $action = 'dokumen ini')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $product = Product::find($value);

        if ($product?->isBundle()) {
            $fail("{$product->sku} adalah paket bundling dan tidak bisa dipakai pada {$this->action}. Pilih barang isinya.");
        }
    }
}
