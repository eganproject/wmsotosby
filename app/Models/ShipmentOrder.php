<?php

namespace App\Models;

use App\Support\NormalizesScanCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu pesanan hasil import Ginee, dikenali dari nomor resinya.
 */
class ShipmentOrder extends Model
{
    use NormalizesScanCode;

    protected $fillable = [
        'shipment_import_id',
        'tracking_number',
        'order_number',
        'marketplace',
        'store_name',
        'buyer_name',
        'order_status',
        'courier',
        'shipping_method',
        'buyer_note',
        'order_date',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ShipmentImport::class, 'shipment_import_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentOrderItem::class);
    }

    public function outbounds(): HasMany
    {
        return $this->hasMany(Outbound::class);
    }

    /**
     * Dokumen gudang terakhir untuk resi ini — yang menentukan tahapannya.
     */
    public function outbound(): HasOne
    {
        return $this->hasOne(Outbound::class)->latestOfMany();
    }

    /* ------------------------------------------------------------ tahap -- */

    public const STAGE_AWAITING_QC = 'belum-qc';

    public const STAGE_CHECKED = 'siap';

    public const STAGE_SHIPPED = 'dikirim';

    /**
     * @var array<string, string>
     */
    public const STAGES = [
        self::STAGE_AWAITING_QC => 'Belum QC',
        self::STAGE_CHECKED => 'Siap Dikirim',
        self::STAGE_SHIPPED => 'Dikirim',
    ];

    /**
     * Tahapan resi ini, dibaca dari dokumen gudangnya.
     *
     * QC berarti resi dan seluruh barangnya sudah discan. Selama belum, resi
     * tetap dianggap belum diperiksa — termasuk bila dokumennya sudah dibuat.
     */
    public function stage(): string
    {
        $outbound = $this->outbound;

        if (! $outbound) {
            return self::STAGE_AWAITING_QC;
        }

        if ($outbound->isPosted()) {
            return self::STAGE_SHIPPED;
        }

        return $outbound->isResiVerified() && $this->outboundFullyScanned()
            ? self::STAGE_CHECKED
            : self::STAGE_AWAITING_QC;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage()];
    }

    /**
     * Kalimat pendek yang menjelaskan kenapa resi ada di tahap itu, dan apa
     * langkah berikutnya.
     */
    public function stageDetail(): string
    {
        $outbound = $this->outbound;

        if (! $outbound) {
            return $this->isFullyMatched()
                ? 'Belum discan sama sekali'
                : 'SKU belum lengkap di master barang';
        }

        if ($outbound->isPosted()) {
            return 'Dikirim '.$outbound->posted_at?->translatedFormat('d M Y H:i');
        }

        if (! $outbound->isResiVerified()) {
            return 'Dokumen dibuat, resi belum discan';
        }

        if (! $this->outboundFullyScanned()) {
            return "Barang {$this->scannedUnits()}/{$this->orderedUnits()} unit discan";
        }

        return $outbound->isPending()
            ? 'QC selesai, menunggu persetujuan'
            : 'QC selesai, menunggu diproses';
    }

    /**
     * Unit yang sudah discan pada dokumennya. Memakai hasil withSum bila
     * tersedia supaya daftar tidak memuat baris barang satu per satu.
     */
    public function scannedUnits(): int
    {
        return (int) ($this->outbound?->items_sum_scanned_quantity ?? 0);
    }

    public function orderedUnits(): int
    {
        return (int) ($this->outbound?->items_sum_quantity ?? 0);
    }

    protected function outboundFullyScanned(): bool
    {
        return $this->orderedUnits() > 0 && $this->scannedUnits() >= $this->orderedUnits();
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * Baris yang SKU-nya belum ada di master barang. Selama masih ada,
     * dokumen gudang tidak bisa dibuat otomatis dari pesanan ini.
     */
    public function unmatchedItems()
    {
        return $this->items->whereNull('product_id');
    }

    public function isFullyMatched(): bool
    {
        return $this->items->isNotEmpty() && $this->unmatchedItems()->isEmpty();
    }

    /**
     * Cari pesanan berdasarkan nomor resi, mengabaikan spasi dan besar kecil huruf.
     */
    public static function findByTrackingNumber(?string $code): ?self
    {
        if (blank($code)) {
            return null;
        }

        $trimmed = trim($code);

        // Resi biasanya discan apa adanya, jadi coba jalur ber-indeks dulu.
        $exact = static::with('items.product')->where('tracking_number', $trimmed)->first();

        if ($exact) {
            return $exact;
        }

        $needle = strtoupper(preg_replace('/\s+/', '', $trimmed));

        return static::with('items.product')
            ->whereRaw("UPPER(REPLACE(tracking_number, ' ', '')) = ?", [$needle])
            ->first();
    }

    /**
     * Saring resi menurut tahapannya.
     *
     * Perhitungannya dilakukan di database, bukan di PHP, supaya jumlah per
     * tahap tetap benar untuk seluruh data — bukan hanya halaman yang tampil.
     */
    public function scopeAtStage(Builder $query, ?string $stage): Builder
    {
        return match ($stage) {
            self::STAGE_SHIPPED => $query->shipped(),
            self::STAGE_CHECKED => $query->qualityChecked(),
            self::STAGE_AWAITING_QC => $query->awaitingQc(),
            default => $query,
        };
    }

    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereHas('outbounds', fn (Builder $outbound) => $outbound
            ->where('status', Outbound::STATUS_POSTED));
    }

    /**
     * Sudah QC: resi terverifikasi dan seluruh barangnya discan, tetapi
     * dokumennya belum diproses.
     */
    public function scopeQualityChecked(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('outbounds', fn (Builder $outbound) => $outbound
                ->where('status', Outbound::STATUS_POSTED))
            ->whereHas('outbounds', fn (Builder $outbound) => $outbound
                ->whereNotNull('resi_verified_at')
                ->whereHas('items')
                ->whereDoesntHave('items', fn (Builder $items) => $items
                    ->whereColumn('scanned_quantity', '<', 'quantity')));
    }

    /**
     * Belum QC: belum ada dokumennya, atau ada tetapi scannya belum tuntas.
     */
    public function scopeAwaitingQc(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('outbounds', fn (Builder $outbound) => $outbound
                ->where('status', Outbound::STATUS_POSTED))
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('outbounds')
                ->orWhereHas('outbounds', fn (Builder $outbound) => $outbound
                    ->where(fn (Builder $outbound) => $outbound
                        ->whereNull('resi_verified_at')
                        ->orWhereDoesntHave('items')
                        ->orWhereHas('items', fn (Builder $items) => $items
                            ->whereColumn('scanned_quantity', '<', 'quantity')))));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('tracking_number', 'like', "%{$term}%")
                    ->orWhere('order_number', 'like', "%{$term}%")
                    ->orWhere('buyer_name', 'like', "%{$term}%")
                    ->orWhere('store_name', 'like', "%{$term}%")
                    ->orWhereHas('items', fn (Builder $items) => $items->where('sku', 'like', "%{$term}%"));
            });
        });
    }
}
