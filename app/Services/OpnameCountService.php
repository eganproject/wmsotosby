<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Validation\ValidationException;

/**
 * Stasiun hitung stok opname.
 *
 * Alur daftar mengharuskan petugas menemukan barisnya lebih dulu — menggulir
 * puluhan halaman untuk satu SKU. Di sini urutannya dibalik: kode yang discan
 * atau diketik yang memanggil barangnya, satu baris disimpan seketika, lalu
 * kolomnya siap untuk barang berikutnya. Tidak ada perpindahan halaman di
 * antara dua rak.
 *
 * Dua aturan yang membentuk seluruh kelas ini:
 *
 * 1. Saldo tercatat tidak pernah ikut keluar dari sini. Petugas yang melihat
 *    angka sistem cenderung menyalinnya alih-alih menghitung, dan opname
 *    kehilangan gunanya. Selisih tetap dihitung — hanya tidak diperlihatkan
 *    kepada yang menghitung.
 *
 * 2. Satu sesi lazim dikerjakan beberapa orang sekaligus. Karena itu setiap
 *    penyimpanan membawa nilai awal yang dilihat petugas, dan baris yang sudah
 *    berubah di database dilaporkan sebagai bentrokan — bukan ditimpa
 *    diam-diam.
 */
class OpnameCountService
{
    /**
     * Ketik cepat tanpa scanner: "FLT-OLI-STD 12", atau "FLT-OLI-STD 12 r2"
     * bila dua di antaranya rusak.
     *
     * Bagian kodenya sengaja tidak rakus, sehingga SKU yang mengandung spasi
     * dan angka — "FLT OLI 001 12" — tetap terbaca sebagai kode "FLT OLI 001"
     * berjumlah 12, bukan kode "FLT" berjumlah 001.
     */
    protected const QUICK_ENTRY = '/^(?<code>.+?)\s+(?<counted>\d{1,6})(?:\s*[rR](?<damaged>\d{1,6}))?$/';

    /**
     * Panggil satu barang dari kode yang discan atau diketik.
     *
     * @return array<string, mixed>
     */
    public function lookup(StockOpname $opname, string $code): array
    {
        $this->guardEditable($opname);

        $code = trim($code);
        $quick = ['counted' => null, 'damaged' => 0];

        // Kode utuh selalu dicoba lebih dulu. Baru bila tidak ada yang cocok,
        // angka di ekornya boleh dianggap sebagai jumlah — supaya SKU yang
        // memang berakhiran angka tidak pernah terpotong.
        $product = Product::findByCode($code);

        if (! $product && preg_match(self::QUICK_ENTRY, $code, $matches)) {
            $product = Product::findByCode($matches['code']);

            if ($product) {
                $quick = [
                    'counted' => (int) $matches['counted'],
                    'damaged' => (int) ($matches['damaged'] ?? 0),
                ];
            }
        }

        if (! $product) {
            throw ValidationException::withMessages([
                'code' => "{$code} tidak dikenali. Periksa SKU atau barcode-nya.",
            ]);
        }

        $this->guardCountable($product);

        $item = $opname->items()->with('counter')->where('product_id', $product->id)->first();

        if ($item) {
            return [
                'status' => 'ready',
                'message' => $item->isCounted()
                    ? "{$product->sku} sudah dihitung sebelumnya — angka baru menggantikannya."
                    : "{$product->sku} siap dihitung.",
                'item' => $this->card($product, $item),
                'quick' => $quick,
            ];
        }

        // Barang ada di katalog tetapi tidak ikut terpotret saat sesi dibuka:
        // produk baru, atau barang kategori lain yang nyasar di rak ini.
        // Temuannya tidak dibuang — barisnya boleh ditambahkan menyusul.
        return [
            'status' => 'out_of_scope',
            'message' => "{$product->sku} di luar cakupan sesi ini ({$opname->scopeLabel()}).",
            'item' => $this->card($product, null),
            'quick' => $quick,
        ];
    }

    /**
     * Keadaan terkini satu baris, tanpa mengubah apa pun.
     *
     * Dipakai penyegaran berkala untuk memeriksa apakah barang yang sedang
     * dipegang petugas keburu dihitung rekannya.
     *
     * @return array<string, mixed>|null
     */
    public function state(StockOpname $opname, int $productId): ?array
    {
        $item = $opname->items()
            ->with(['product', 'counter'])
            ->where('product_id', $productId)
            ->first();

        return $item ? $this->card($item->product, $item) : null;
    }

    /**
     * Simpan hasil hitung satu barang.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function record(StockOpname $opname, array $input): array
    {
        $this->guardEditable($opname);

        $product = Product::findOrFail($input['product_id']);

        $this->guardCountable($product);

        $item = $opname->items()->where('product_id', $product->id)->first()
            ?? $this->adopt($opname, $product, (bool) ($input['adopt'] ?? false));

        if ($conflict = $this->conflict($item, $input)) {
            return $conflict;
        }

        $counted = $this->normalize($input['counted'] ?? null);

        // Hitungan yang dibatalkan ikut membatalkan temuan rusaknya: keduanya
        // berasal dari pemeriksaan rak yang sama.
        $damaged = $counted === null ? 0 : max(0, (int) ($input['damaged'] ?? 0));

        $item->update([
            'counted_quantity' => $counted,
            'damaged_quantity' => $damaged,
            'counted_at' => $counted === null ? null : now(),
            'counted_by' => $counted === null ? null : auth()->id(),
        ]);

        $item->load('counter');

        return [
            'saved' => true,
            'conflict' => false,
            'message' => $counted === null
                ? "{$product->sku} · hitungan dikosongkan"
                : "{$product->sku} · {$counted} {$product->unit}".($damaged > 0 ? " · {$damaged} rusak" : ''),
            'item' => $this->card($product, $item),
        ];
    }

    /**
     * Baris untuk barang yang belum terpotret. Saldo tercatatnya diambil saat
     * ini juga — itulah pembanding yang jujur bagi barang yang baru masuk
     * cakupan menyusul.
     */
    protected function adopt(StockOpname $opname, Product $product, bool $allowed): StockOpnameItem
    {
        if (! $allowed) {
            throw ValidationException::withMessages([
                'code' => "{$product->sku} di luar cakupan sesi ini. Tambahkan dulu ke sesi bila memang ditemukan di rak.",
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'code' => "{$product->sku} berstatus non-aktif, jadi tidak bisa ditambahkan ke sesi.",
            ]);
        }

        // Dua petugas bisa menemukan barang yang sama pada saat bersamaan;
        // yang kedua memakai baris yang sudah dibuat, bukan gagal.
        return StockOpnameItem::createOrFirst(
            ['stock_opname_id' => $opname->id, 'product_id' => $product->id],
            ['system_quantity' => $product->stock],
        );
    }

    /**
     * Baris yang berubah sejak kartunya dibuka milik petugas lain.
     *
     * Yang dikembalikan bukan galat melainkan keadaan terbarunya, supaya layar
     * bisa menawarkan pilihan: pakai angka rekan, atau timpa dengan sengaja.
     * Menimpa diam-diam adalah satu-satunya yang tidak boleh.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    protected function conflict(StockOpnameItem $item, array $input): ?array
    {
        if (($input['force'] ?? false) || ! array_key_exists('baseline', $input)) {
            return null;
        }

        $baseline = $this->normalize($input['baseline']);

        if ($item->counted_quantity === $baseline) {
            return null;
        }

        $item->load('counter', 'product');

        $by = $item->counter?->name ?? 'petugas lain';
        $at = $item->counted_at?->format('H:i');

        return [
            'saved' => false,
            'conflict' => true,
            'message' => $item->isCounted()
                ? "Baru saja dihitung {$by}".($at ? " pukul {$at}" : '')." menjadi {$item->counted_quantity}. Timpa dengan hitungan Anda?"
                : "Hitungan baris ini baru saja dikosongkan {$by}. Simpan lagi untuk memakai angka Anda.",
            'item' => $this->card($item->product, $item),
        ];
    }

    /**
     * Isi kartu barang di layar hitung.
     *
     * Saldo tercatat dan selisihnya sengaja tidak ada di sini: apa yang tidak
     * pernah dikirim tidak bisa bocor ke layar petugas.
     *
     * @return array<string, mixed>
     */
    protected function card(Product $product, ?StockOpnameItem $item): array
    {
        return [
            'item_id' => $item?->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit,
            'location' => $product->location,
            'category' => $product->category,
            'counted' => $item?->counted_quantity,
            'damaged' => (int) ($item?->damaged_quantity ?? 0),
            'counted_at' => $item?->counted_at?->format('H:i'),
            'counted_by' => $item?->counter?->name,
            'counted_by_me' => $item?->counted_by !== null && $item->counted_by === auth()->id(),
        ];
    }

    /**
     * Kemajuan sesi, dibaca semua petugas yang sedang mengerjakan batch yang
     * sama. Angkanya sengaja netral — jumlah baris, bukan selisih.
     *
     * @return array<string, mixed>
     */
    public function progress(StockOpname $opname): array
    {
        $row = $opname->items()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL THEN 1 ELSE 0 END) as counted')
            ->selectRaw('SUM(CASE WHEN counted_by = ? THEN 1 ELSE 0 END) as mine', [auth()->id()])
            ->first();

        $total = (int) ($row->total ?? 0);
        $counted = (int) ($row->counted ?? 0);

        return [
            'total' => $total,
            'counted' => $counted,
            'mine' => (int) ($row->mine ?? 0),
            'remaining' => max(0, $total - $counted),
            'percentage' => $total > 0 ? (int) round($counted / $total * 100) : 0,
            /*
                Siapa lagi yang sedang mengerjakan batch ini. Tanpa ini dua
                petugas bisa menyisir rak yang sama sepanjang pagi tanpa
                pernah tahu. Jumlah selisih per orang sengaja tidak ikut:
                selama menghitung, tidak seorang pun boleh tahu angka mana
                yang "salah".
            */
            'counters' => $opname->contributors()
                ->map(fn (array $contributor) => [
                    'name' => $contributor['name'],
                    'counted' => $contributor['counted'],
                ])
                ->values()
                ->all(),
        ];
    }

    /** Kolom kosong berarti belum dihitung; nol adalah hasil hitung yang sah. */
    protected function normalize(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function guardEditable(StockOpname $opname): void
    {
        if (! $opname->isEditable()) {
            throw ValidationException::withMessages([
                'code' => 'Sesi ini tidak lagi bisa dihitung.',
            ]);
        }
    }

    /**
     * Paket bundling tidak berwujud di rak, jadi tidak ada yang bisa dihitung —
     * sama seperti saat sesinya dipotret.
     */
    protected function guardCountable(Product $product): void
    {
        if ($product->isBundle()) {
            throw ValidationException::withMessages([
                'code' => "{$product->sku} adalah paket bundling; yang dihitung barang isinya.",
            ]);
        }
    }
}
