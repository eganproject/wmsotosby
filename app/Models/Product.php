<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'category',
        'unit',
        'location',
        'min_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    /**
     * Stok berada pada atau di bawah batas minimum.
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Label status stok untuk badge di tabel.
     */
    public function stockStatus(): string
    {
        return match (true) {
            $this->isOutOfStock() => 'habis',
            $this->isLowStock() => 'menipis',
            default => 'aman',
        };
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    /**
     * Jenis dokumen yang masih memakai barang ini.
     *
     * Baris dokumen memakai kunci asing RESTRICT, sedangkan penghapusan hanya
     * memeriksa mutasi stok — padahal dokumen draft belum menghasilkan mutasi
     * apa pun. Tanpa pemeriksaan ini, menghapus barang yang masih tercantum di
     * draft menabrak kunci asing dan berakhir sebagai galat basis data, bukan
     * sebagai pesan yang bisa ditindaklanjuti.
     *
     * @return array<int, string>
     */
    public function blockingDocuments(): array
    {
        $tables = [
            'inbound_items' => 'barang masuk',
            'outbound_items' => 'barang keluar',
            'return_receipt_items' => 'penerimaan retur',
            'stock_adjustment_items' => 'penyesuaian stok',
            'stock_opname_items' => 'stok opname',
            'damaged_disposal_items' => 'barang rusak',
        ];

        return collect($tables)
            ->filter(fn (string $label, string $table) => DB::table($table)->where('product_id', $this->id)->exists())
            ->values()
            ->all();
    }

    /**
     * Cari barang dari kode yang discan: barcode dulu, lalu SKU.
     *
     * Spasi dan besar kecil huruf diabaikan karena scanner sering
     * menyisipkannya. Kolom kosong tidak pernah dianggap cocok.
     */
    public static function findByCode(?string $code): ?self
    {
        $trimmed = trim((string) $code);

        if ($trimmed === '') {
            return null;
        }

        // Jalur cepat: kode apa adanya masih bisa memakai indeks unik pada
        // barcode dan sku. Inilah yang terjadi pada hampir semua scan.
        $exact = static::query()
            ->where('barcode', $trimmed)
            ->orWhere('sku', $trimmed)
            ->first();

        if ($exact) {
            return $exact;
        }

        // Jalur lambat: baru dinormalkan bila kode mengandung spasi atau
        // beda besar kecil huruf. Perbandingan ini tidak bisa memakai indeks,
        // jadi sengaja tidak dijalankan lebih dulu.
        $needle = strtoupper(preg_replace('/\s+/', '', $trimmed));

        return static::query()
            ->whereRaw("UPPER(REPLACE(COALESCE(barcode, ''), ' ', '')) = ?", [$needle])
            ->orWhereRaw("UPPER(REPLACE(sku, ' ', '')) = ?", [$needle])
            ->first();
    }

    /**
     * Pencarian bebas untuk SKU, barcode, dan nama barang.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            });
        });
    }
}
