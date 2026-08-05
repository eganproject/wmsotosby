<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen penyesuaian stok.
 *
 * Dipakai saat saldo tercatat tidak lagi sama dengan barang di rak — hasil
 * stok opname, temuan barang rusak, atau koreksi salah input. Stok baru
 * berubah setelah dokumennya disetujui, sama seperti dokumen gudang lain.
 */
class StockAdjustment extends Model
{
    use HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'code',
        'date',
        'reason',
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
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** Baris yang menambah stok. */
    public function increaseQuantity(): int
    {
        return (int) $this->items->sum(fn (StockAdjustmentItem $item) => max(0, $item->difference()));
    }

    /** Baris yang mengurangi stok. */
    public function decreaseQuantity(): int
    {
        return (int) $this->items->sum(fn (StockAdjustmentItem $item) => max(0, -$item->difference()));
    }

    /**
     * Baris yang benar-benar berubah. Baris tanpa selisih tidak menghasilkan
     * pergerakan stok apa pun.
     */
    public function changedItems()
    {
        return $this->items->filter(fn (StockAdjustmentItem $item) => $item->difference() !== 0);
    }

    /**
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $blockers = [];

        if ($this->items->isEmpty()) {
            $blockers[] = 'Dokumen belum memiliki baris barang.';
        } elseif ($this->changedItems()->isEmpty()) {
            $blockers[] = 'Tidak ada selisih pada dokumen ini — stok fisik sama dengan tercatat.';
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
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Alasan penyesuaian yang tersedia di form.
     *
     * @return array<int, string>
     */
    public static function reasons(): array
    {
        return [
            'Stok opname',
            'Barang rusak / kedaluwarsa',
            'Barang hilang',
            'Koreksi salah input',
            'Barang ditemukan kembali',
            'Lainnya',
        ];
    }

    /**
     * Nomor dokumen berikutnya, contoh: ADJ-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'ADJ-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Disesuaikan';
    }
}
