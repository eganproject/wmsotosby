<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'system_quantity',
        'actual_quantity',
        'applied_difference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'actual_quantity' => 'integer',
            'applied_difference' => 'integer',
        ];
    }

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Selisih menurut dokumen: hasil hitung fisik dikurangi saldo tercatat.
     */
    public function difference(): int
    {
        return $this->actual_quantity - $this->system_quantity;
    }

    public function isIncrease(): bool
    {
        return $this->difference() > 0;
    }

    /**
     * Selisih ditulis dengan tanda supaya arahnya langsung terbaca.
     */
    public function differenceLabel(): string
    {
        $difference = $this->difference();

        return match (true) {
            $difference > 0 => '+'.$difference,
            $difference < 0 => (string) $difference,
            default => '0',
        };
    }

    /**
     * Saldo tercatat sempat berubah setelah dokumen disusun, sehingga selisih
     * yang dibukukan berbeda dari yang tertulis di baris ini.
     */
    public function wasAppliedDifferently(): bool
    {
        return $this->applied_difference !== null
            && $this->applied_difference !== $this->difference();
    }
}
