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
        'damaged_quantity',
        'counted_at',
        'counted_by',
        'applied_difference',
        'applied_damaged',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'counted_at' => 'datetime',
            'applied_difference' => 'integer',
            'applied_damaged' => 'integer',
        ];
    }

    /**
     * Unit rusak yang ditemukan pada sesi ini.
     *
     * Angkanya ditambahkan ke saldo rusak, bukan menggantikannya: barang rusak
     * lain bisa saja tersimpan di rak yang tidak ikut dihitung sesi ini, dan
     * menyamakan saldo dengan temuan satu rak akan menghapusnya diam-diam.
     */
    public function hasDamaged(): bool
    {
        return $this->damaged_quantity > 0;
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
