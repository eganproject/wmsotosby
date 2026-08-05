<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sesi stok opname.
 *
 * Cakupannya ditentukan di awal — seluruh gudang, satu kategori, atau satu
 * lokasi rak — lalu isinya dipotret menjadi baris hitung. Stok baru bergerak
 * setelah sesinya disetujui, sama seperti dokumen gudang lain.
 */
class StockOpname extends Model
{
    use HasApprovalWorkflow;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_LOCATION = 'location';

    /** @var array<string, int>|null */
    protected ?array $summaryCache = null;

    /** @var \Illuminate\Support\Collection<int, array<string, mixed>>|null */
    protected $contributorCache = null;

    protected $fillable = [
        'code',
        'date',
        'scope',
        'scope_value',
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
        return $this->hasMany(StockOpnameItem::class);
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

    /* ------------------------------------------------------- perhitungan -- */

    /** Baris yang sudah dihitung petugas. */
    public function countedItems()
    {
        return $this->items->filter(fn (StockOpnameItem $item) => $item->isCounted());
    }

    /** Baris yang belum disentuh — barisnya tidak akan mengubah stok. */
    public function uncountedItems()
    {
        return $this->items->reject(fn (StockOpnameItem $item) => $item->isCounted());
    }

    /** Baris yang dihitung dan hasilnya berbeda dari saldo tercatat. */
    public function varianceItems()
    {
        return $this->countedItems()->filter(fn (StockOpnameItem $item) => $item->difference() !== 0);
    }

    /* --------------------------------------------------------- ringkasan - */

    /**
     * Angka ringkas sesi ini, dihitung di database dalam satu query.
     *
     * Sengaja tidak memakai koleksi baris: sesi opname gudang penuh bisa
     * berisi ribuan SKU, dan memuat semuanya hanya untuk menampilkan beberapa
     * angka membuat halaman melambat justru saat opnamenya paling besar.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        return $this->summaryCache ??= $this->computeSummary();
    }

    /**
     * @return array<string, int>
     */
    protected function computeSummary(): array
    {
        $row = $this->items()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL THEN 1 ELSE 0 END) as counted')
            ->selectRaw('SUM(CASE WHEN counted_quantity = system_quantity THEN 1 ELSE 0 END) as matched')
            ->selectRaw('SUM(CASE WHEN counted_quantity <> system_quantity THEN 1 ELSE 0 END) as variance')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL THEN system_quantity ELSE 0 END) as system_units')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL THEN counted_quantity ELSE 0 END) as counted_units')
            ->selectRaw('SUM(CASE WHEN counted_quantity > system_quantity THEN counted_quantity - system_quantity ELSE 0 END) as surplus')
            ->selectRaw('SUM(CASE WHEN counted_quantity < system_quantity THEN system_quantity - counted_quantity ELSE 0 END) as shortage')
            ->first();

        // Perbandingan dengan NULL bernilai tidak-benar di SQL, jadi baris yang
        // belum dihitung tidak pernah ikut terhitung pada kolom mana pun.
        return [
            'total' => (int) ($row->total ?? 0),
            'counted' => (int) ($row->counted ?? 0),
            'matched' => (int) ($row->matched ?? 0),
            'variance' => (int) ($row->variance ?? 0),
            'system_units' => (int) ($row->system_units ?? 0),
            'counted_units' => (int) ($row->counted_units ?? 0),
            'surplus' => (int) ($row->surplus ?? 0),
            'shortage' => (int) ($row->shortage ?? 0),
        ];
    }

    /** Buang ringkasan yang tersimpan, mis. setelah hitungan berubah. */
    public function forgetSummary(): void
    {
        $this->summaryCache = null;
        $this->contributorCache = null;
    }

    /* --------------------------------------------------------- akurasi --- */

    /**
     * Akurasi catatan menurut jumlah SKU: berapa persen barang yang dihitung
     * ternyata angkanya sudah benar.
     */
    public function accuracyPercentage(): int
    {
        $summary = $this->summary();

        return $summary['counted'] > 0
            ? (int) round($summary['matched'] / $summary['counted'] * 100)
            : 0;
    }

    /**
     * Akurasi menurut jumlah unit.
     *
     * Satu SKU meleset 200 unit lebih berat daripada sepuluh SKU meleset satu
     * unit, dan akurasi per SKU tidak menangkap itu. Pembaginya hanya baris
     * yang dihitung, supaya barang yang belum disentuh tidak ikut mengencerkan
     * angkanya.
     */
    public function unitAccuracyPercentage(): int
    {
        $base = $this->countedSystemUnits();

        if ($base < 1) {
            // Tidak ada saldo tercatat untuk dibandingkan; temuan apa pun
            // berarti catatannya meleset sepenuhnya.
            return $this->absoluteVariance() > 0 ? 0 : 100;
        }

        return (int) max(0, round((1 - $this->absoluteVariance() / $base) * 100));
    }

    /** Saldo tercatat pada baris yang dihitung. */
    public function countedSystemUnits(): int
    {
        return $this->summary()['system_units'];
    }

    /** Unit yang benar-benar ditemukan di rak pada baris yang dihitung. */
    public function countedUnits(): int
    {
        return $this->summary()['counted_units'];
    }

    /** Total selisih tanpa memandang arah — ukuran kesalahan catatan. */
    public function absoluteVariance(): int
    {
        return $this->summary()['surplus'] + $this->summary()['shortage'];
    }

    /**
     * Rekap per petugas: satu sesi lazim dikerjakan beberapa orang, dan
     * pertanyaan "siapa menghitung apa" harus bisa dijawab tanpa membuka
     * satu-satu barisnya.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function contributors()
    {
        return $this->contributorCache ??= $this->items()
            ->toBase()
            ->leftJoin('users', 'users.id', '=', 'stock_opname_items.counted_by')
            ->whereNotNull('counted_quantity')
            ->groupBy('stock_opname_items.counted_by', 'users.name')
            ->selectRaw('users.name as name')
            ->selectRaw('COUNT(*) as counted')
            ->selectRaw('SUM(CASE WHEN counted_quantity <> system_quantity THEN 1 ELSE 0 END) as variance')
            ->selectRaw('MAX(counted_at) as last_at')
            ->orderByDesc('counted')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?? 'Petugas dihapus',
                'counted' => (int) $row->counted,
                'variance' => (int) $row->variance,
                'last_at' => $row->last_at,
            ]);
    }

    public function progressPercentage(): int
    {
        $summary = $this->summary();

        return $summary['total'] > 0
            ? (int) round($summary['counted'] / $summary['total'] * 100)
            : 0;
    }

    /** Unit yang bertambah bila sesi ini disetujui. */
    public function surplusQuantity(): int
    {
        return $this->summary()['surplus'];
    }

    /** Unit yang berkurang bila sesi ini disetujui. */
    public function shortageQuantity(): int
    {
        return $this->summary()['shortage'];
    }

    /**
     * @return array<int, string>
     */
    public function postingBlockers(): array
    {
        $summary = $this->summary();

        $blockers = [];

        if ($summary['total'] === 0) {
            $blockers[] = 'Sesi ini belum memiliki baris barang.';
        } elseif ($summary['counted'] === 0) {
            $blockers[] = 'Belum ada satu pun barang yang dihitung.';
        } elseif ($summary['variance'] === 0) {
            $blockers[] = 'Tidak ada selisih — hasil hitung sama dengan saldo tercatat.';
        }

        return $blockers;
    }

    public function isReadyToPost(): bool
    {
        return $this->postingBlockers() === [];
    }

    /* ------------------------------------------------------------ cakupan - */

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_CATEGORY => 'Kategori '.$this->scope_value,
            self::SCOPE_LOCATION => 'Lokasi '.$this->scope_value,
            default => 'Seluruh gudang',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_ALL => 'Seluruh gudang',
            self::SCOPE_CATEGORY => 'Satu kategori',
            self::SCOPE_LOCATION => 'Satu lokasi rak',
        ];
    }

    /**
     * Barang yang termasuk cakupan tertentu.
     */
    public static function productsInScope(string $scope, ?string $value): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->when($scope === self::SCOPE_CATEGORY, fn (Builder $query) => $query->where('category', $value))
            ->when($scope === self::SCOPE_LOCATION, fn (Builder $query) => $query->where('location', $value));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('scope_value', 'like', "%{$term}%")
                    ->orWhere('note', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Nomor dokumen berikutnya, contoh: OPN-202608-0007.
     */
    public static function nextCode(): string
    {
        $prefix = 'OPN-'.now()->format('Ym').'-';

        $last = static::where('code', 'like', $prefix.'%')->max('code');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function postedLabel(): string
    {
        return 'Selesai';
    }
}
