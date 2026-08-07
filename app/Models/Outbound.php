<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outbound extends Model
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
        'recipient',
        'marketplace',
        'tracking_number',
        'shipment_order_id',
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
        return $this->hasMany(OutboundItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shipmentOrder(): BelongsTo
    {
        return $this->belongsTo(ShipmentOrder::class);
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
     * Seluruh baris barang sudah discan sesuai jumlah yang diminta.
     */
    public function isFullyScanned(): bool
    {
        return $this->items->every(fn (OutboundItem $item) => $item->isFullyScanned());
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function totalScanned(): int
    {
        return (int) $this->items->sum('scanned_quantity');
    }

    public function scanPercentage(): int
    {
        $total = $this->totalQuantity();

        return $total > 0 ? (int) round($this->totalScanned() / $total * 100) : 0;
    }

    /**
     * Alasan dokumen belum boleh diproses. Kosong berarti siap dikirim.
     *
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $blockers = [];

        if ($this->items->isEmpty()) {
            $blockers[] = 'Dokumen belum memiliki baris barang.';
        }

        if ($this->isMarketplace()) {
            if (! $this->isResiVerified()) {
                $blockers[] = 'Resi belum discan dan diverifikasi.';
            }

            if (! $this->isFullyScanned()) {
                $blockers[] = 'Masih ada barang yang belum selesai discan.';
            }
        }

        return $blockers;
    }

    public function isReadyToPost(): bool
    {
        return $this->postingBlockers() === [];
    }

    /**
     * Paket marketplace yang sudah lengkap discan tetapi belum diproses.
     *
     * Ini isi antrean "Siap Dikirim": stasiun packing hanya memverifikasi isi
     * paket, pengirimannya diputuskan belakangan.
     */
    public function scopeReadyToShip(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_MARKETPLACE)
            ->where('status', self::STATUS_DRAFT)
            ->whereNotNull('resi_verified_at')
            ->whereHas('items')
            ->whereDoesntHave('items', fn (Builder $items) => $items->whereColumn('scanned_quantity', '<', 'quantity'));
    }

    /**
     * Kebalikannya: paket yang scannya belum tuntas.
     */
    public function scopeStillScanning(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_MARKETPLACE)
            ->where('status', self::STATUS_DRAFT)
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('items')
                ->orWhereNull('resi_verified_at')
                ->orWhereHas('items', fn (Builder $items) => $items->whereColumn('scanned_quantity', '<', 'quantity')));
    }

    /**
     * Cari dokumen dari kode yang discan: nomor resi dulu, lalu nomor dokumen.
     *
     * Sama seperti pencarian barang, spasi dan besar kecil huruf diabaikan
     * karena scanner sering menyisipkannya. Kode dokumen ikut dicoba supaya
     * paket yang labelnya rusak masih bisa diproses dari nomor cetakannya.
     */
    public static function findByScannedCode(?string $code): ?self
    {
        $trimmed = trim((string) $code);

        if ($trimmed === '') {
            return null;
        }

        // Jalur cepat: kode apa adanya masih memakai indeks. Inilah yang
        // terjadi pada hampir semua scan.
        $exact = static::query()
            ->where(fn (Builder $query) => $query
                ->where('tracking_number', $trimmed)
                ->orWhere('code', $trimmed))
            ->latest('id')
            ->first();

        if ($exact) {
            return $exact;
        }

        // Jalur lambat, hanya ditempuh bila kode mengandung spasi atau beda
        // besar kecil huruf; perbandingan ini tidak bisa memakai indeks.
        $needle = strtoupper(preg_replace('/\s+/', '', $trimmed));

        return static::query()
            ->whereRaw("UPPER(REPLACE(COALESCE(tracking_number, ''), ' ', '')) = ?", [$needle])
            ->orWhereRaw("UPPER(REPLACE(code, ' ', '')) = ?", [$needle])
            ->latest('id')
            ->first();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('recipient', 'like', "%{$term}%")
                    ->orWhere('tracking_number', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Daftar marketplace yang didukung.
     *
     * @return array<int, string>
     */
    public static function marketplaces(): array
    {
        return ['Shopee', 'Tokopedia', 'TikTok Shop', 'Lazada', 'Blibli', 'Bukalapak'];
    }

    /**
     * Nomor dokumen berikutnya, contoh: OUT-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'OUT-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Terkirim';
    }
}
