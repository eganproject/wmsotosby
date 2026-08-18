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

    /** Barang biasa: punya wujud di rak dan punya saldo sendiri. */
    public const TYPE_SINGLE = 'single';

    /** Paket bundling: dijual sebagai satu SKU, tetapi tidak pernah ada di rak. */
    public const TYPE_BUNDLE = 'bundle';

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'category',
        'unit',
        'location',
        'min_stock',
        'is_active',
        'type',
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

    /* ------------------------------------------------------------ paket -- */

    /**
     * Isi paket ini. Kosong untuk barang biasa.
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id');
    }

    /**
     * Paket-paket yang memuat barang ini sebagai isinya.
     */
    public function partOfBundles(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'component_id');
    }

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    /**
     * Berapa paket yang bisa dibentuk dari saldo komponennya saat ini.
     *
     * Selalu dihitung, tidak pernah disimpan. Menyimpannya berarti ada dua
     * sumber kebenaran atas satu barang yang sama — dan yang satu pasti
     * ketinggalan begitu komponennya bergerak lewat dokumen lain.
     *
     * Paket tanpa isi bernilai nol: ia belum bisa dijual, bukan bisa dijual
     * tanpa batas.
     */
    public function bundleAvailability(): int
    {
        if (! $this->isBundle()) {
            return (int) $this->stock;
        }

        /*
            Hasil withBundleAvailability() dipakai bila kuerinya memang
            memintanya, supaya daftar berisi puluhan paket tidak berubah
            menjadi puluhan kueri komponen.

            Keberadaan kuncinya yang diperiksa, bukan nilainya: paket tanpa isi
            menghasilkan null, dan `??` akan menganggapnya belum dihitung lalu
            memuat komponennya — persis N+1 yang scope itu hindari.
        */
        if (array_key_exists('bundle_availability', $this->attributes)) {
            return (int) $this->attributes['bundle_availability'];
        }

        $components = $this->relationLoaded('bundleComponents')
            ? $this->bundleComponents
            : $this->bundleComponents()->with('component')->get();

        if ($components->isEmpty()) {
            return 0;
        }

        return (int) $components->min(fn (ProductBundleItem $item) => $item->availableSets());
    }

    /**
     * Ketersediaan paket sebagai kolom hasil kueri, untuk daftar dan laporan.
     *
     * Dihitung di basis data supaya satu halaman berisi puluhan paket tidak
     * berubah menjadi puluhan kueri komponen.
     *
     * Pembagiannya ditulis dengan sisa bagi, bukan FLOOR: SQLite bawaan PHP
     * dikompilasi tanpa fungsi matematika sama sekali, sedangkan MySQL
     * punya. Bentuk (a - a % b) / b selalu habis dibagi, jadi hasilnya tepat
     * di kedua basis data — sama seperti yang dipakai laporan restock.
     */
    public function scopeWithBundleAvailability(Builder $query): Builder
    {
        $sets = '(c.stock - (c.stock % pbi.quantity)) / pbi.quantity';

        $availability = DB::table('product_bundle_items as pbi')
            ->join('products as c', 'c.id', '=', 'pbi.component_id')
            ->whereColumn('pbi.bundle_id', 'products.id')
            ->selectRaw("MIN(CASE WHEN c.is_active = 0 OR pbi.quantity < 1 THEN 0 ELSE {$sets} END)");

        return $query
            ->select('products.*')
            ->selectSub($availability, 'bundle_availability');
    }

    public function scopeBundles(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_BUNDLE);
    }

    public function scopeSingles(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SINGLE);
    }

    /**
     * Angka yang layak ditampilkan sebagai "berapa yang bisa dikirim".
     *
     * Untuk barang biasa itu saldo di rak. Untuk paket itu hasil hitungan dari
     * komponennya — satu-satunya angka yang punya arti, karena kolom stoknya
     * memang selamanya nol.
     */
    public function availableStock(): int
    {
        return $this->isBundle() ? $this->bundleAvailability() : (int) $this->stock;
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
     *
     * Paket hanya mengenal dua keadaan: masih bisa dirakit, atau tidak.
     * "Menipis" tidak berlaku baginya — batas minimum adalah setelan atas
     * saldo di rak, dan paket tidak punya rak.
     */
    public function stockStatus(): string
    {
        if ($this->isBundle()) {
            return $this->availableStock() > 0 ? 'aman' : 'habis';
        }

        return match (true) {
            $this->isOutOfStock() => 'habis',
            $this->isLowStock() => 'menipis',
            default => 'aman',
        };
    }

    /**
     * Barang yang stoknya menyentuh batas minimum.
     *
     * Paket tidak pernah ikut: kolom stoknya selamanya nol dan batas
     * minimumnya nol pula, sehingga 0 <= 0 akan membuat setiap paket terbaca
     * menipis selama-lamanya — menaikkan hitungan di dasbor dan mendesak
     * pemesanan barang yang memang tidak pernah dipesan.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->singles()->whereColumn('stock', '<=', 'min_stock');
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

        $blocking = collect($tables)
            ->filter(fn (string $label, string $table) => DB::table($table)->where('product_id', $this->id)->exists())
            ->values()
            ->all();

        /*
            Resep paket diperiksa terpisah karena kolomnya bukan product_id.

            Kedua arah dihitung: barang yang menjadi isi paket lain, dan paket
            yang isinya sudah tersusun. Keduanya memakai kunci asing yang
            berbeda perlakuannya — isi bersifat RESTRICT, resep bersifat
            CASCADE — tetapi bagi yang menghapus, keduanya sama-sama alasan
            untuk berhenti dan melihat dulu.
        */
        if ($this->partOfBundles()->exists()) {
            $blocking[] = 'isi paket bundling';
        }

        if ($this->isBundle() && $this->bundleComponents()->exists()) {
            $blocking[] = 'resep paket bundling';
        }

        /*
            Paket yang tercantum pada dokumen barang keluar.

            Kolomnya bundle_id, bukan product_id, sehingga pemeriksaan di atas
            melewatinya — sementara kunci asingnya RESTRICT dan akan menolak
            penghapusannya. Tanpa baris ini, menghapus paket yang masih ada di
            satu draft berakhir sebagai galat basis data, bukan sebagai kalimat
            yang bisa ditindaklanjuti. Persis kekeliruan yang sudah dijelaskan
            di atas, hanya untuk tabel yang lebih baru.
        */
        if ($this->isBundle() && DB::table('outbound_bundles')->where('bundle_id', $this->id)->exists()) {
            $blocking[] = 'barang keluar (sebagai paket)';
        }

        return $blocking;
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
