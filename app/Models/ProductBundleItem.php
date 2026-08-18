<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris resep paket: barang apa, dan berapa banyak per satu paket.
 */
class ProductBundleItem extends Model
{
    protected $fillable = [
        'bundle_id',
        'component_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_id');
    }

    /**
     * Berapa paket yang bisa dibentuk dari saldo komponen ini saja.
     *
     * Komponen nonaktif dianggap tidak tersedia: ia memang tidak dipesan lagi,
     * jadi paket yang memuatnya tidak boleh terlihat masih bisa dijual.
     */
    public function availableSets(): int
    {
        if ($this->quantity < 1 || ! $this->component || ! $this->component->is_active) {
            return 0;
        }

        return intdiv((int) $this->component->stock, $this->quantity);
    }
}
