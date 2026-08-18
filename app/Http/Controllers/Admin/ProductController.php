<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'show', 'export']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.update', only: ['edit', 'update', 'bulkMinStock']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $products = $this->filtered($request)
            // Ketersediaan paket dihitung di basis data, sekali jalan untuk
            // seluruh halaman. Sengaja hanya di sini, bukan di filtered():
            // fungsi itu juga dipakai sebagai sasaran update massal, dan
            // kueri ber-selectSub tidak bisa dipakai untuk menulis.
            ->withBundleAvailability()
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'summary' => $this->summary($request),
        ]);
    }

    /**
     * Unduh daftar stok sesuai filter yang sedang aktif.
     */
    public function export(Request $request, ProductExportService $exporter): StreamedResponse
    {
        return $exporter->download(
            // Ketersediaan paket ikut dihitung di basis data. Tanpa ini,
            // export memuat komponen setiap paket satu per satu sambil
            // menulis barisnya — dan export adalah yang justru dijalankan
            // saat datanya sudah banyak.
            $this->filtered($request)->withBundleAvailability(),
            'stok-barang-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }

    public function create(): View
    {
        return view('admin.products.create', [
            'catalog' => $this->componentCatalog(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $components = $data['components'] ?? [];
        unset($data['components']);

        $product = DB::transaction(function () use ($data, $components) {
            $product = Product::create($data);

            $this->syncRecipe($product, $components);

            return $product;
        });

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', $product->isBundle()
                ? "Paket {$product->sku} dibuat dengan ".count($components).' barang di dalamnya.'
                : 'Barang berhasil ditambahkan.');
    }

    /**
     * Detail barang sekaligus kartu stoknya.
     */
    public function show(Product $product): View
    {
        return view('admin.products.show', [
            'product' => $product->load([
                'bundleComponents.component',
                'partOfBundles.bundle',
            ]),
            // Paket tidak pernah punya mutasi, jadi kartunya tidak dimuat sama
            // sekali — bukan dimuat lalu ditemukan kosong.
            'movements' => $product->isBundle()
                ? null
                : $product->movements()->with('user')->paginate(15),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', [
            'product' => $product->load('bundleComponents.component'),
            'catalog' => $this->componentCatalog($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $components = $data['components'] ?? [];
        unset($data['components']);

        DB::transaction(function () use ($product, $data, $components) {
            $product->update($data);

            $this->syncRecipe($product, $components);
        });

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', 'Data barang berhasil diperbarui.');
    }

    /**
     * Tulis ulang resep paket.
     *
     * Dihapus lalu dibuat kembali, bukan dicocokkan baris per baris: resepnya
     * hanya beberapa baris, tidak ada yang merujuknya, dan menulis ulang
     * seluruhnya membuat baris yang dihapus di layar benar-benar hilang tanpa
     * perlu melacak mana yang berubah.
     *
     * Barang biasa selalu berakhir tanpa resep — termasuk paket yang baru saja
     * dikembalikan menjadi barang biasa, yang resep lamanya harus ikut pergi
     * supaya tidak diam-diam hidup lagi bila jenisnya diubah kembali.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    protected function syncRecipe(Product $product, array $components): void
    {
        $product->bundleComponents()->delete();

        if (! $product->isBundle()) {
            return;
        }

        $product->bundleComponents()->createMany(
            collect($components)->map(fn (array $row) => [
                'component_id' => (int) $row['component_id'],
                'quantity' => (int) $row['quantity'],
            ])->all(),
        );
    }

    /**
     * Barang yang boleh menjadi isi paket.
     *
     * Hanya barang biasa — paket tidak boleh memuat paket. Yang sudah
     * dinonaktifkan tetap ikut bila ia memang sudah tercantum di resep, sebab
     * tanpa pilihannya barisnya jatuh ke kosong dan lenyap tanpa suara begitu
     * formulirnya disimpan ulang.
     */
    protected function componentCatalog(?Product $product = null)
    {
        return Product::singles()
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->when($product, fn (Builder $query) => $query
                    ->orWhereIn('id', $product->bundleComponents->pluck('component_id'))))
            ->when($product, fn (Builder $query) => $query->whereKeyNot($product->id))
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'unit', 'stock']);
    }

    /**
     * Ubah batas stok menipis banyak barang sekaligus.
     *
     * Yang diubah hanya batasnya, bukan stoknya. Batas minimum adalah setelan
     * kapan sebuah barang mulai disebut menipis — ia tidak pernah menambah atau
     * mengurangi saldo, dan karena itu tidak meninggalkan mutasi stok. Inilah
     * satu-satunya alasan penyuntingan massal aman dilakukan di sini: tidak ada
     * angka gudang yang bisa menjadi rancu karenanya.
     */
    public function bulkMinStock(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'min_stock' => ['required', 'integer', 'min:0', 'max:999999'],
            'scope' => ['required', 'in:selected,filtered'],
            'ids' => ['required_if:scope,selected', 'array'],
            'ids.*' => ['integer'],
        ], [], [
            'min_stock' => 'batas stok menipis',
            'ids' => 'barang yang dipilih',
        ]);

        // Sasarannya ditentukan ulang di sisi server, bukan dipercaya dari
        // layar: saringan yang sama persis dipakai daftar, export, dan di sini.
        // Batas menipis adalah setelan atas saldo di rak, dan paket bundling
        // tidak punya rak. Disaring di sini juga, bukan hanya di layar: yang
        // dicentang bisa saja paket, dan sasarannya memang ditentukan ulang
        // di sisi server.
        $target = ($data['scope'] === 'filtered'
            ? $this->filtered($request)
            : Product::query()->whereIn('id', $data['ids']))->singles();

        $total = (clone $target)->count();

        if ($total === 0) {
            return $this->backToProducts($request)
                ->with('error', 'Tidak ada barang yang cocok. Mungkin sudah diubah orang lain.');
        }

        // Baris yang nilainya sudah sama sengaja tidak disentuh supaya
        // updated_at-nya tidak ikut bergeser tanpa ada yang benar-benar berubah.
        $changed = (clone $target)
            ->where('min_stock', '!=', $data['min_stock'])
            ->update(['min_stock' => $data['min_stock']]);

        return $this->backToProducts($request)->with('success', $this->bulkSummary(
            $total,
            $changed,
            $data['min_stock'],
        ));
    }

    /**
     * Laporkan yang benar-benar terjadi, bukan sekadar "berhasil".
     */
    protected function bulkSummary(int $total, int $changed, int $minStock): string
    {
        if ($changed === 0) {
            return "Batas stok menipis {$total} barang memang sudah {$minStock}. Tidak ada yang diubah.";
        }

        $message = "Batas stok menipis {$changed} barang diubah menjadi {$minStock}.";

        return $changed === $total
            ? $message
            : $message.' '.($total - $changed).' barang lainnya sudah bernilai sama.';
    }

    /**
     * Kembali ke daftar dengan saringan yang tadi dipakai.
     *
     * Nomor halaman sengaja tidak dibawa: mengubah batas menipis mengubah pula
     * barang mana yang lolos saringan "Menipis", sehingga halaman yang sama
     * bisa saja sudah tidak ada isinya.
     */
    protected function backToProducts(Request $request): RedirectResponse
    {
        return redirect()->route('admin.products.index', array_filter(
            $request->only(['search', 'category', 'stock', 'status', 'type']),
            fn ($value) => filled($value),
        ));
    }

    /**
     * Empat angka ringkasan diambil sekali jalan, bukan empat query terpisah.
     *
     * Saringan yang sedang berlaku ikut diterapkan, kecuali saringan kondisi
     * stok — kartu Menipis dan Habis justru pemilih kondisi itu. Sebelumnya
     * angka ini dihitung atas seluruh barang sementara tabelnya sudah disaring,
     * sehingga kartu dan daftar bercerita beda.
     *
     * @return array<string, int>
     */
    protected function summary(Request $request): array
    {
        /*
            Keempat angka stok dihitung hanya atas barang biasa.

            Paket bundling tidak punya saldo maupun batas minimum, jadi ikut
            menghitungnya membuat "Stok Habis" menyebut angka yang isinya
            justru paket yang komponennya menumpuk penuh di rak. Jumlah paket
            tetap dilaporkan, hanya sebagai keterangannya sendiri — masih dalam
            satu kueri yang sama.
        */
        $single = self::sqlValue(Product::TYPE_SINGLE);
        $bundle = self::sqlValue(Product::TYPE_BUNDLE);

        $row = $this->scoped($request)
            ->selectRaw("SUM(CASE WHEN type = {$single} THEN 1 ELSE 0 END) as total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = {$single} THEN stock ELSE 0 END), 0) as units")
            ->selectRaw("SUM(CASE WHEN type = {$single} AND stock <= min_stock AND stock > 0 THEN 1 ELSE 0 END) as low")
            ->selectRaw("SUM(CASE WHEN type = {$single} AND stock <= 0 THEN 1 ELSE 0 END) as out_of_stock")
            ->selectRaw("SUM(CASE WHEN type = {$bundle} THEN 1 ELSE 0 END) as bundles")
            ->first();

        return [
            'total' => (int) $row->total,
            'units' => (int) $row->units,
            'low' => (int) $row->low,
            'out' => (int) $row->out_of_stock,
            'bundles' => (int) $row->bundles,
        ];
    }

    /**
     * Tetapan jenis barang sebagai literal SQL.
     *
     * Disisipkan langsung, bukan sebagai binding: nilainya berasal dari
     * tetapan kelas dan tidak pernah menyentuh masukan pengguna, sedangkan
     * ekspresinya muncul lima kali dalam satu kueri — urutan binding yang
     * harus dijaga sendiri di antara select dan where justru lebih mudah salah.
     */
    protected static function sqlValue(string $type): string
    {
        return "'".$type."'";
    }

    /**
     * Filter yang sama dipakai tabel di layar maupun berkas hasil export.
     */
    protected function filtered(Request $request): Builder
    {
        /*
            Saringan kondisi stok selalu menyiratkan barang biasa.

            Aman, menipis, dan habis semuanya dinilai dari kolom stok terhadap
            batas minimum — dua angka yang pada paket bundling selamanya nol.
            Tanpa ini, memilih "Habis" akan memunculkan seluruh paket yang ada,
            termasuk yang komponennya menumpuk penuh di rak.
        */
        return $this->scoped($request)
            ->when($request->input('stock') === 'low', fn ($query) => $query->lowStock()->where('stock', '>', 0))
            ->when($request->input('stock') === 'out', fn ($query) => $query->singles()->where('stock', '<=', 0))
            ->when($request->input('stock') === 'safe', fn ($query) => $query->singles()->whereColumn('stock', '>', 'min_stock'));
    }

    /**
     * Saringan di luar kondisi stok. Dipakai bersama daftar dan ringkasannya,
     * karena kartu ringkasan itulah pemilih kondisi stoknya.
     */
    protected function scoped(Request $request): Builder
    {
        return Product::query()
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->when($request->input('type') === Product::TYPE_BUNDLE, fn ($query) => $query->bundles())
            ->when($request->input('type') === Product::TYPE_SINGLE, fn ($query) => $query->singles());
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->movements()->exists()) {
            return back()->with('error', 'Barang sudah memiliki riwayat pergerakan stok dan tidak dapat dihapus. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        // Dokumen draft belum menghasilkan mutasi apa pun, jadi pemeriksaan di
        // atas melewatkannya — sementara kunci asing pada baris dokumen memakai
        // RESTRICT dan akan menolak penghapusannya sebagai galat basis data.
        if ($blocking = $product->blockingDocuments()) {
            return back()->with('error', 'Barang masih tercantum pada dokumen '
                .implode(', ', $blocking)
                .'. Hapus dulu barisnya dari dokumen tersebut, atau nonaktifkan barangnya bila tidak dipakai lagi.');
        }

        $product->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Barang berhasil dihapus.');
    }
}
