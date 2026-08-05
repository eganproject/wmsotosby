<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_opname_id',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'counted_at',
        'counted_by',
        'applied_difference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'counted_at' => 'datetime',
            'applied_difference' => 'integer',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /**
     * Sudah dihitung petugas. Nol pun terhitung — yang berarti "belum" hanya
     * NULL, karena rak kosong adalah temuan, bukan ketiadaan data.
     */
    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /**
     * Selisih menurut sesi ini: hasil hitung dikurangi saldo tercatat.
     */
    public function difference(): int
    {
        return $this->isCounted() ? $this->counted_quantity - $this->system_quantity : 0;
    }

    public function isIncrease(): bool
    {
        return $this->difference() > 0;
    }

    public function differenceLabel(): string
    {
        if (! $this->isCounted()) {
            return '—';
        }

        $difference = $this->difference();

        return match (true) {
            $difference > 0 => '+'.$difference,
            $difference < 0 => (string) $difference,
            default => '0',
        };
    }

    /**
     * Saldo tercatat sempat berubah setelah dihitung, sehingga selisih yang
     * dibukukan berbeda dari yang tertulis di baris ini.
     */
    public function wasAppliedDifferently(): bool
    {
        return $this->applied_difference !== null
            && $this->applied_difference !== $this->difference();
    }
}
