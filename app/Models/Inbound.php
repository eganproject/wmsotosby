<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inbound extends Model
{
    use FiltersByDate, HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'code',
        'date',
        'supplier_id',
        'reference',
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
        return $this->hasMany(InboundItem::class);
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

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', "%{$term}%"));
            });
        });
    }

    /**
     * Nomor dokumen berikutnya, contoh: IN-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'IN-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Diposting';
    }
}
