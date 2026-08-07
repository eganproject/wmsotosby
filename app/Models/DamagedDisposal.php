<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengeluaran barang di luar penjualan.
 *
 * Barang tidak boleh menguap begitu saja dari gudang. Setiap unit yang keluar
 * tanpa melewati penjualan harus punya alasan yang tercatat — dibuang,
 * dikembalikan ke pemasok, diperbaiki, atau dipindahkan ke saldo rusak.
 *
 * Dokumen ini menyebut dari saldo mana unitnya diambil. Awalnya ia hanya
 * mengurusi saldo rusak, sehingga barang layak jual yang harus dikembalikan ke
 * pemasok atau dibuang karena kedaluwarsa tidak punya jalan keluar sama sekali.
 */
class DamagedDisposal extends Model
{
    use FiltersByDate, HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const ACTION_DISCARD = 'dibuang';

    public const ACTION_RETURN = 'dikembalikan';

    public const ACTION_REPAIR = 'diperbaiki';

    /** Barang layak jual yang ternyata rusak: pindah saldo, belum keluar gudang. */
    public const ACTION_WRITE_OFF = 'ditandai_rusak';

    protected $fillable = [
        'code',
        'date',
        'bucket',
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

    /* ------------------------------------------------------------ saldo -- */

    /** Saldo yang unitnya diambil. */
    public function sourceBucket(): string
    {
        return $this->bucket === StockMovement::BUCKET_GOOD
            ? StockMovement::BUCKET_GOOD
            : StockMovement::BUCKET_DAMAGED;
    }

    /**
     * Saldo yang menerima unitnya, bila memang tidak benar-benar keluar gudang.
     *
     * Perbaikan memulangkan barang rusak menjadi layak jual; penandaan rusak
     * melakukan kebalikannya. Selebihnya barang pergi untuk selamanya, dan
     * tidak ada saldo yang menerimanya.
     */
    public function targetBucket(): ?string
    {
        return match ($this->action) {
            self::ACTION_REPAIR => StockMovement::BUCKET_GOOD,
            self::ACTION_WRITE_OFF => StockMovement::BUCKET_DAMAGED,
            default => null,
        };
    }

    public function isFromDamaged(): bool
    {
        return $this->sourceBucket() === StockMovement::BUCKET_DAMAGED;
    }

    public function bucketLabel(): string
    {
        return $this->isFromDamaged() ? 'Stok rusak' : 'Stok layak jual';
    }

    /** Unitnya benar-benar meninggalkan gudang, bukan sekadar berpindah saldo. */
    public function leavesTheWarehouse(): bool
    {
        return $this->targetBucket() === null;
    }

    /**
     * Barang yang diperbaiki kembali menjadi layak jual.
     */
    public function returnsToSellableStock(): bool
    {
        return $this->targetBucket() === StockMovement::BUCKET_GOOD;
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
     * Seluruh tindakan yang dikenal.
     *
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_DISCARD => 'Dibuang / dimusnahkan',
            self::ACTION_RETURN => 'Dikembalikan ke pemasok',
            self::ACTION_REPAIR => 'Diperbaiki jadi layak jual',
            self::ACTION_WRITE_OFF => 'Dipindahkan ke stok rusak',
        ];
    }

    /**
     * Tindakan yang masuk akal bagi saldo tertentu.
     *
     * Memperbaiki barang yang memang layak jual tidak punya arti, begitu pula
     * menandai rusak sesuatu yang sudah tercatat rusak.
     *
     * @return array<string, string>
     */
    public static function actionsFor(?string $bucket): array
    {
        $shared = [
            self::ACTION_DISCARD => self::actions()[self::ACTION_DISCARD],
            self::ACTION_RETURN => self::actions()[self::ACTION_RETURN],
        ];

        return match ($bucket) {
            StockMovement::BUCKET_GOOD => $shared + [
                self::ACTION_WRITE_OFF => self::actions()[self::ACTION_WRITE_OFF],
            ],
            StockMovement::BUCKET_DAMAGED => $shared + [
                self::ACTION_REPAIR => self::actions()[self::ACTION_REPAIR],
            ],
            default => [],
        };
    }

    /** Kalimat yang menjelaskan apa yang terjadi pada saldo setelah diposting. */
    public function postedSummary(): string
    {
        return match ($this->targetBucket()) {
            StockMovement::BUCKET_GOOD => 'Stok rusak berkurang, stok layak jual bertambah.',
            StockMovement::BUCKET_DAMAGED => 'Stok layak jual berkurang, stok rusak bertambah.',
            default => $this->isFromDamaged()
                ? 'Stok rusak sudah berkurang.'
                : 'Stok layak jual sudah berkurang.',
        };
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

        // Penjagaan kedua, seandainya data tersimpan dari jalur lain: tindakan
        // dan saldo asal harus tetap cocok saat dokumen benar-benar diposting.
        if (! array_key_exists($this->action, static::actionsFor($this->sourceBucket()))) {
            $blockers[] = "Tindakan \"{$this->actionLabel()}\" tidak berlaku untuk {$this->bucketLabel()}.";
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
