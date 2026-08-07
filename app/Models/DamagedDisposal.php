<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penanganan barang rusak: mengeluarkan unit dari saldo rusak.
 *
 * Barang rusak tidak boleh menguap begitu saja dari gudang. Setiap unit yang
 * keluar dari saldo rusak harus punya alasan yang tercatat — dibuang,
 * dikembalikan ke pemasok, atau diperbaiki sehingga layak jual kembali.
 */
class DamagedDisposal extends Model
{
    use FiltersByDate, HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const ACTION_DISCARD = 'dibuang';

    public const ACTION_RETURN = 'dikembalikan';

    public const ACTION_REPAIR = 'diperbaiki';

    protected $fillable = [
        'code',
        'date',
        'action',
        'supplier_id',
        'note',
        'status',
        'posted_at',
        'user_id',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DamagedDisposalItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /**
     * Barang yang diperbaiki kembali menjadi layak jual; yang lain keluar
     * dari gudang untuk selamanya.
     */
    public function returnsToSellableStock(): bool
    {
        return $this->action === self::ACTION_REPAIR;
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function actionLabel(): string
    {
        return self::actions()[$this->action] ?? $this->action;
    }

    /**
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_DISCARD => 'Dibuang / dimusnahkan',
            self::ACTION_RETURN => 'Dikembalikan ke pemasok',
            self::ACTION_REPAIR => 'Diperbaiki jadi layak jual',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $blockers = [];

        if ($this->items->isEmpty()) {
            $blockers[] = 'Dokumen belum memiliki baris barang.';
        }

        return $blockers;
    }

    public function isReadyToPost(): bool
    {
        return $this->postingBlockers() === [];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Nomor dokumen berikutnya, contoh: RSK-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'RSK-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Selesai';
    }
}
