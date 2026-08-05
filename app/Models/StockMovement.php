<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu baris mutasi stok.
 *
 * Setiap perubahan saldo barang meninggalkan satu baris di sini, lengkap
 * dengan saldo sesudahnya, jadi kartu stok bisa direkonstruksi dan setiap
 * angka bisa ditelusuri sampai ke dokumen asalnya.
 */
class StockMovement extends Model
{
    /**
     * Dokumen yang bisa menjadi asal mutasi, beserta cara menampilkannya.
     *
     * Kuncinya dipakai pada filter di URL supaya tautannya tetap pendek dan
     * tidak membocorkan nama kelas.
     *
     * @var array<string, array{model: class-string, label: string, route: ?string}>
     */
    public const SOURCES = [
        'inbound' => ['model' => Inbound::class, 'label' => 'Barang Masuk', 'route' => 'admin.inbounds.show'],
        'outbound' => ['model' => Outbound::class, 'label' => 'Barang Keluar', 'route' => 'admin.outbounds.show'],
        'return' => ['model' => ReturnReceipt::class, 'label' => 'Penerimaan Retur', 'route' => 'admin.returns.show'],
        'adjustment' => ['model' => StockAdjustment::class, 'label' => 'Penyesuaian Stok', 'route' => 'admin.adjustments.show'],
        'import' => ['model' => ProductImport::class, 'label' => 'Import Barang', 'route' => null],
        'disposal' => ['model' => DamagedDisposal::class, 'label' => 'Barang Rusak', 'route' => 'admin.disposals.show'],
    ];

    public const BUCKET_GOOD = 'good';

    public const BUCKET_DAMAGED = 'damaged';

    protected $fillable = [
        'product_id',
        'type',
        'bucket',
        'quantity',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIncoming(): bool
    {
        return $this->type === 'in';
    }

    /** Pergerakan pada saldo barang rusak, bukan saldo layak jual. */
    public function isDamaged(): bool
    {
        return $this->bucket === self::BUCKET_DAMAGED;
    }

    public function bucketLabel(): string
    {
        return $this->isDamaged() ? 'Rusak' : 'Layak jual';
    }

    public function scopeInBucket(Builder $query, ?string $bucket): Builder
    {
        return $query->when(
            in_array($bucket, [self::BUCKET_GOOD, self::BUCKET_DAMAGED], true),
            fn (Builder $query) => $query->where('bucket', $bucket),
        );
    }

    /* ------------------------------------------------------- asal mutasi -- */

    /**
     * Kunci sumber pada konstanta SOURCES, atau null bila tidak dikenali.
     */
    public function sourceKey(): ?string
    {
        foreach (self::SOURCES as $key => $source) {
            if ($this->reference_type === $source['model']) {
                return $key;
            }
        }

        return null;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->sourceKey()]['label'] ?? 'Lainnya';
    }

    /**
     * Tautan ke dokumen asal. Null bila dokumennya memang tidak punya halaman
     * sendiri, seperti import barang.
     */
    public function sourceUrl(): ?string
    {
        $route = self::SOURCES[$this->sourceKey()]['route'] ?? null;

        return $route && $this->reference_id ? route($route, $this->reference_id) : null;
    }

    /* ----------------------------------------------------------- filter --- */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('description', 'like', "%{$term}%")
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%"));
            });
        });
    }

    /**
     * Saring berdasarkan jenis dokumen asal, mis. hanya barang keluar.
     */
    public function scopeFromSource(Builder $query, ?string $key): Builder
    {
        return $query->when(
            $key && isset(self::SOURCES[$key]),
            fn (Builder $query) => $query->where('reference_type', self::SOURCES[$key]['model']),
        );
    }
}
