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

    /* ----------------------------------------------------------- tahapan -- */

    /** Resi paket belum dicocokkan di stasiun packing. */
    public const STAGE_NEED_RESI = 'perlu_resi';

    /** Resi sudah cocok, tetapi dokumennya tidak punya baris barang. */
    public const STAGE_NEED_ITEMS = 'perlu_barang';

    /** Isi paket sedang diperiksa satu per satu. */
    public const STAGE_SCANNING = 'scan_barang';

    /** Lengkap discan, menunggu diserahkan ke kurir. */
    public const STAGE_READY = 'siap_kirim';

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

    /**
     * Paket bundling yang dipesan pada dokumen ini.
     *
     * Sekadar penjelasan asal-usul baris barang — stoknya tetap bergerak
     * lewat items, yang isinya barang nyata.
     */
    public function bundles(): HasMany
    {
        return $this->hasMany(OutboundBundle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shipmentOrder(): BelongsTo
    {
        return $this->belongsTo(ShipmentOrder::class);
    }

    /**
     * Baris barang yang bukan berasal dari paket.
     *
     * Baris dokumen tidak menyimpan asal-usulnya — satu barang bisa datang
     * dari paket sekaligus dipesan satuan, dan memisahkannya menjadi dua baris
     * akan merusak stasiun scan. Sisanya karena itu dihitung: unit yang
     * dijanjikan paket dikurangkan dari tiap baris, dan yang tersisa memang
     * yang dipesan lepas.
     *
     * Dipakai editor dokumen, supaya menyunting paket tidak berubah menjadi
     * menyunting isinya satu per satu.
     *
     * Dikembalikan sebagai baris yang belum tersimpan, bukan larik, supaya
     * editor baris dokumen menerimanya persis seperti baris biasa.
     *
     * @return \Illuminate\Support\Collection<int, OutboundItem>
     */
    public function looseItems(): \Illuminate\Support\Collection
    {
        $this->loadMissing('items', 'bundles');

        $fromBundles = [];

        foreach ($this->bundles as $bundle) {
            foreach ($bundle->composition as $line) {
                $id = (int) $line['product_id'];
                $fromBundles[$id] = ($fromBundles[$id] ?? 0) + ((int) $line['quantity'] * $bundle->quantity);
            }
        }

        return $this->items
            ->map(fn (OutboundItem $item) => new OutboundItem([
                'product_id' => $item->product_id,
                // Tidak pernah negatif: bila resepnya berubah setelah dokumen
                // dibentuk, sisanya bisa terhitung minus — dan yang benar saat
                // itu adalah "tidak ada yang lepas", bukan utang.
                'quantity' => max(0, $item->quantity - ($fromBundles[$item->product_id] ?? 0)),
                'note' => $item->note,
            ]))
            ->filter(fn (OutboundItem $item) => $item->quantity > 0)
            ->values();
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

    /**
     * Unit yang diminta dokumen.
     *
     * Hasil withSum dipakai bila ada, supaya daftar tidak perlu memuat baris
     * barang satu per satu hanya untuk menghitung badge.
     */
    public function totalQuantity(): int
    {
        return $this->summed('items_sum_quantity', 'quantity');
    }

    public function totalScanned(): int
    {
        return $this->summed('items_sum_scanned_quantity', 'scanned_quantity');
    }

    /**
     * Baca hasil withSum bila kuerinya memang memintanya.
     *
     * Keberadaan kuncinya yang diperiksa, bukan nilainya: dokumen tanpa baris
     * barang menghasilkan null, dan `??` akan menganggapnya belum dihitung lalu
     * memuat relasinya satu per satu — persis N+1 yang withSum hindari.
     */
    protected function summed(string $aggregate, string $column): int
    {
        if (array_key_exists($aggregate, $this->attributes)) {
            return (int) $this->attributes[$aggregate];
        }

        return (int) $this->items->sum($column);
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

    /* ----------------------------------------------------------- tahapan -- */

    /**
     * Tahap yang sedang dijalani dokumen — bukan sekadar nilai kolom status.
     *
     * Kolom status hanya mengenal draft, diajukan, ditolak, dan diposting.
     * Bagi paket marketplace itu terlalu kasar: satu kata "draft" dipakai
     * bersama oleh paket yang belum disentuh sama sekali dan paket yang sudah
     * selesai QC dan sedang mengantre di Siap Dikirim. Keduanya menuntut
     * tindakan yang sama sekali berbeda, jadi keduanya harus punya nama sendiri.
     *
     * Urutannya penting: keputusan penyetuju selalu didahulukan, supaya
     * penolakan tidak tertutup oleh tahap pemindaian.
     */
    public function stage(): string
    {
        return match (true) {
            $this->isPosted() => self::STATUS_POSTED,
            $this->isPending() => self::STATUS_PENDING,
            $this->isRejected() => self::STATUS_REJECTED,
            ! $this->isMarketplace() => self::STATUS_DRAFT,
            ! $this->isResiVerified() => self::STAGE_NEED_RESI,
            $this->totalQuantity() < 1 => self::STAGE_NEED_ITEMS,
            $this->totalScanned() < $this->totalQuantity() => self::STAGE_SCANNING,
            default => self::STAGE_READY,
        };
    }

    /**
     * Nama, warna, dan ikon setiap tahap.
     *
     * @return array<string, array{label: string, variant: string, icon: string}>
     */
    public static function stages(): array
    {
        return [
            self::STATUS_DRAFT => ['label' => 'Draft', 'variant' => 'neutral', 'icon' => 'document'],
            self::STAGE_NEED_RESI => ['label' => 'Perlu scan resi', 'variant' => 'neutral', 'icon' => 'key'],
            self::STAGE_NEED_ITEMS => ['label' => 'Belum ada barang', 'variant' => 'danger', 'icon' => 'warning'],
            self::STAGE_SCANNING => ['label' => 'Scan barang', 'variant' => 'warning', 'icon' => 'key'],
            self::STAGE_READY => ['label' => 'Siap dikirim', 'variant' => 'dark', 'icon' => 'box'],
            self::STATUS_PENDING => ['label' => 'Menunggu persetujuan', 'variant' => 'warning', 'icon' => 'clock'],
            self::STATUS_REJECTED => ['label' => 'Ditolak', 'variant' => 'danger', 'icon' => 'x-circle'],
            self::STATUS_POSTED => ['label' => 'Terkirim', 'variant' => 'success', 'icon' => 'check-circle'],
        ];
    }

    public function stageLabel(): string
    {
        return self::stages()[$this->stage()]['label'];
    }

    public function stageVariant(): string
    {
        return self::stages()[$this->stage()]['variant'];
    }

    public function stageIcon(): string
    {
        return self::stages()[$this->stage()]['icon'];
    }

    /**
     * Saring menurut tahap.
     *
     * Setiap pilihan saringan menunjuk tepat satu badge, tanpa tumpang tindih —
     * "Draft" tidak lagi ikut memuat paket marketplace yang sebenarnya sudah
     * siap dikirim.
     */
    public function scopeAtStage(Builder $query, ?string $stage): Builder
    {
        return match ($stage) {
            self::STATUS_DRAFT => $query->where('status', self::STATUS_DRAFT)
                ->where('type', self::TYPE_REGULAR),
            self::STAGE_NEED_RESI => $query->stillScanning()->whereNull('resi_verified_at'),
            self::STAGE_NEED_ITEMS => $query->stillScanning()
                ->whereNotNull('resi_verified_at')
                ->whereDoesntHave('items'),
            self::STAGE_SCANNING => $query->stillScanning()
                ->whereNotNull('resi_verified_at')
                ->whereHas('items'),
            self::STAGE_READY => $query->readyToShip(),
            self::STATUS_PENDING, self::STATUS_REJECTED, self::STATUS_POSTED => $query->where('status', $stage),
            default => $query,
        };
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
