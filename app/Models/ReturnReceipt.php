<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan retur: barang yang kembali dari pembeli ke gudang.
 */
class ReturnReceipt extends Model
{
    use FiltersByDate, HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const TYPE_REGULAR = 'regular';

    public const TYPE_MARKETPLACE = 'marketplace';

    protected $fillable = [
        'code',
        'date',
        'type',
        'sender',
        'marketplace',
        'tracking_number',
        'shipment_order_id',
        'reference',
        'reason',
        'note',
        'status',
        'resi_verified_at',
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
            'resi_verified_at' => 'datetime',
            'posted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnReceiptItem::class);
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

    public function isMarketplace(): bool
    {
        return $this->type === self::TYPE_MARKETPLACE;
    }

    public function isResiVerified(): bool
    {
        return $this->resi_verified_at !== null;
    }

    /**
     * Retur marketplace selalu datang lewat kurir sehingga resinya wajib
     * discan. Retur non-marketplace hanya wajib bila nomor resi diisi —
     * barang yang diantar langsung ke gudang tidak punya resi.
     */
    public function requiresResiScan(): bool
    {
        return $this->isMarketplace() || filled($this->tracking_number);
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function goodQuantity(): int
    {
        return (int) $this->items->sum('good_quantity');
    }

    public function damagedQuantity(): int
    {
        return (int) $this->items->sum('damaged_quantity');
    }

    /**
     * Barang yang tercatat pada resi tetapi tidak ditemukan di dalam paket.
     */
    public function missingQuantity(): int
    {
        return (int) $this->items->sum(fn (ReturnReceiptItem $item) => $item->missingQuantity());
    }

    public function hasMissing(): bool
    {
        return $this->missingQuantity() > 0;
    }

    /**
     * Alasan dokumen belum boleh diproses. Kosong berarti siap diterima.
     *
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $blockers = [];

        if ($this->items->isEmpty()) {
            $blockers[] = 'Dokumen belum memiliki baris barang.';
        }

        if ($this->requiresResiScan() && ! $this->isResiVerified()) {
            $blockers[] = 'Resi retur belum discan dan diverifikasi.';
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
                    ->orWhere('sender', 'like', "%{$term}%")
                    ->orWhere('tracking_number', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Alasan retur yang tersedia di form.
     *
     * @return array<int, string>
     */
    public static function reasons(): array
    {
        return [
            'Barang rusak',
            'Salah kirim',
            'Tidak sesuai pesanan',
            'Pesanan dibatalkan',
            'Pembeli berubah pikiran',
            'Lainnya',
        ];
    }

    /**
     * Nomor dokumen berikutnya, contoh: RET-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'RET-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Diterima';
    }
}
