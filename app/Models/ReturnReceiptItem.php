<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris barang pada penerimaan retur.
 *
 * `quantity` adalah jumlah yang tertulis pada resi, sedangkan hasil
 * pemeriksaan fisik dipecah menjadi layak jual dan rusak. Selisih keduanya
 * terhadap `quantity` berarti barangnya tidak ikut sampai — dianggap hilang.
 */
class ReturnReceiptItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'return_receipt_id',
        'product_id',
        'quantity',
        'good_quantity',
        'damaged_quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'good_quantity' => 'integer',
            'damaged_quantity' => 'integer',
        ];
    }

    public function returnReceipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Jumlah yang tidak ditemukan saat paket dibuka.
     */
    public function missingQuantity(): int
    {
        return max(0, $this->quantity - $this->good_quantity - $this->damaged_quantity);
    }

    public function hasMissing(): bool
    {
        return $this->missingQuantity() > 0;
    }

    /**
     * Sudah diperiksa bila seluruh jumlahnya terjelaskan sebagai layak jual,
     * rusak, atau hilang.
     */
    public function isChecked(): bool
    {
        return $this->good_quantity + $this->damaged_quantity > 0 || $this->quantity === 0;
    }

    /**
     * Ringkasan kondisi untuk ditampilkan pada tabel.
     */
    public function conditionSummary(): string
    {
        return collect([
            $this->good_quantity > 0 ? "{$this->good_quantity} layak jual" : null,
            $this->damaged_quantity > 0 ? "{$this->damaged_quantity} rusak" : null,
            $this->hasMissing() ? "{$this->missingQuantity()} hilang" : null,
        ])->filter()->implode(' · ') ?: 'Belum diperiksa';
    }
}
