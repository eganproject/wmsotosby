<?php

namespace App\Models;

use App\Models\Concerns\FiltersByDate;
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
    use FiltersByDate, NormalizesScanCode;

    protected $fillable = [
        'shipment_import_id',
        'tracking_number',
        'order_number',
        'marketplace',
        'store_name',
        'buyer_name',
        'order_status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'courier',
        'shipping_method',
        'buyer_note',
        'order_date',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* -------------------------------------------------------- pembatalan -- */

    /**
     * Kata yang menandakan pesanan batal pada status dari marketplace.
     *
     * Dicocokkan sebagai penggalan, bukan kata utuh, supaya "Dibatalkan",
     * "Cancelled", maupun "Pembatalan Diminta" sama-sama tertangkap. Permintaan
     * pembatalan yang belum disetujui sengaja ikut dihitung: justru saat itulah
     * paketnya belum boleh dipacking.
     *
     * @var array<int, string>
     */
    public const CANCELLED_MARKERS = ['batal', 'cancel'];

    public static function looksCancelled(?string $status): bool
    {
        if (blank($status)) {
            return false;
        }

        $needle = mb_strtolower($status);

        foreach (self::CANCELLED_MARKERS as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Ditandai orang, bukan terbaca dari berkas import. */
    public function isCancelledByHand(): bool
    {
        return $this->isCancelled() && $this->cancelled_by !== null;
    }

    public function cancellationDetail(): string
    {
        if (! $this->isCancelled()) {
            return '';
        }

        return $this->isCancelledByHand()
            ? 'Ditandai batal · '.($this->cancellation_reason ?: 'tanpa alasan')
            : 'Batal menurut data import'.($this->order_status ? " ({$this->order_status})" : '');
    }

    /* ------------------------------------------------------------ tahap -- */

    public const STAGE_AWAITING_QC = 'belum-qc';

    public const STAGE_CHECKED = 'siap';

    public const STAGE_SHIPPED = 'dikirim';

    public const STAGE_CANCELLED = 'batal';

    /**
     * @var array<string, string>
     */
    public const STAGES = [
        self::STAGE_AWAITING_QC => 'Belum QC',
        self::STAGE_CHECKED => 'Siap Dikirim',
        self::STAGE_SHIPPED => 'Dikirim',
        self::STAGE_CANCELLED => 'Dibatalkan',
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

        // Paket yang sudah berangkat tetap "dikirim" meskipun pesanannya
        // kemudian dibatalkan: stoknya memang sudah keluar, dan pembatalan
        // sesudah itu urusannya penerimaan retur, bukan halaman ini.
        if ($outbound?->isPosted()) {
            return self::STAGE_SHIPPED;
        }

        if ($this->isCancelled()) {
            return self::STAGE_CANCELLED;
        }

        if (! $outbound) {
            return self::STAGE_AWAITING_QC;
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

        if ($outbound?->isPosted()) {
            return 'Dikirim '.$outbound->posted_at?->translatedFormat('d M Y H:i');
        }

        if ($this->isCancelled()) {
            // Dokumen yang sudah terlanjur dibuat perlu disebut: barangnya
            // mungkin sudah turun dari rak dan harus dikembalikan.
            return $this->cancellationDetail()
                .($outbound ? " · kembalikan barang, hapus {$outbound->code}" : '');
        }

        if (! $outbound) {
            return $this->isFullyMatched()
                ? 'Belum discan sama sekali'
                : 'SKU belum lengkap di master barang';
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
     * Unit yang sudah discan pada dokumennya.
     */
    public function scannedUnits(): int
    {
        return $this->units('scanned_quantity');
    }

    public function orderedUnits(): int
    {
        return $this->units('quantity');
    }

    /**
     * Memakai hasil withSum bila pemanggilnya menyediakannya, supaya daftar
     * tidak memuat baris barang satu per satu.
     *
     * Bila tidak ada, dihitung langsung dari barisnya. Tanpa cadangan ini
     * angkanya jatuh menjadi nol dan tahapnya terbaca "Belum QC" — jawaban
     * yang salah tetapi terlihat wajar, sehingga tidak ada yang curiga.
     */
    protected function units(string $column): int
    {
        $outbound = $this->outbound;

        if (! $outbound) {
            return 0;
        }

        $summed = $outbound->getAttribute('items_sum_'.$column);

        return (int) ($summed ?? $outbound->items()->sum($column));
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
            self::STAGE_CANCELLED => $query->cancelled(),
            default => $query,
        };
    }

    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereHas('outbounds', fn (Builder $outbound) => $outbound
            ->where('status', Outbound::STATUS_POSTED));
    }

    /**
     * Dibatalkan dan memang belum berangkat.
     *
     * Yang sudah berangkat sengaja dikeluarkan dari sini supaya jumlah tiap
     * tahap tetap bisa dijumlahkan menjadi total tanpa ada yang terhitung dua
     * kali — persis seperti yang dilakukan stage().
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query
            ->whereNotNull('cancelled_at')
            ->whereDoesntHave('outbounds', fn (Builder $outbound) => $outbound
                ->where('status', Outbound::STATUS_POSTED));
    }

    /**
     * Sudah QC: resi terverifikasi dan seluruh barangnya discan, tetapi
     * dokumennya belum diproses.
     */
    public function scopeQualityChecked(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
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
            ->whereNull('cancelled_at')
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

    /**
     * Disaring menurut tanggal pesanan.
     *
     * Eksport Ginee tidak selalu menyertakan kolom tanggal, dan pesanan tanpa
     * tanggal akan lenyap seluruhnya begitu saringan dinyalakan — jawaban yang
     * salah tanpa satu pun petunjuk. Yang kosong karena itu memakai waktu
     * berkasnya masuk ke sistem.
     */
    public function scopeDateBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        $column = 'DATE(COALESCE(order_date, shipment_orders.created_at))';

        return $query
            ->when(static::dateFilterValue($from), fn (Builder $query, string $date) => $query
                ->whereRaw("{$column} >= ?", [$date]))
            ->when(static::dateFilterValue($to), fn (Builder $query, string $date) => $query
                ->whereRaw("{$column} <= ?", [$date]));
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
