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
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index', 'show', 'export']),
            new Middleware('can:products.create', only: ['create', 'store']),
            new Middleware('can:products.update', only: ['edit', 'update']),
            new Middleware('can:products.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $products = $this->filtered($request)
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Unduh daftar stok sesuai filter yang sedang aktif.
     */
    public function export(Request $request, ProductExportService $exporter): StreamedResponse
    {
        return $exporter->download(
            $this->filtered($request),
            'stok-barang-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }

    public function create(): View
    {
        return view('admin.products.create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', 'Barang berhasil ditambahkan.');
    }

    /**
     * Detail barang sekaligus kartu stoknya.
     */
    public function show(Product $product): View
    {
        return view('admin.products.show', [
            'product' => $product,
            'movements' => $product->movements()->with('user')->paginate(15),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', compact('product'));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('admin.products.show', $product)
            ->with('success', 'Data barang berhasil diperbarui.');
    }

    /**
     * Empat angka ringkasan diambil sekali jalan, bukan empat query terpisah.
     *
     * @return array<string, int>
     */
    protected function summary(): array
    {
        $row = Product::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(stock), 0) as units')
            ->selectRaw('SUM(CASE WHEN stock <= min_stock AND stock > 0 THEN 1 ELSE 0 END) as low')
            ->selectRaw('SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock')
            ->first();

        return [
            'total' => (int) $row->total,
            'units' => (int) $row->units,
            'low' => (int) $row->low,
            'out' => (int) $row->out_of_stock,
        ];
    }

    /**
     * Filter yang sama dipakai tabel di layar maupun berkas hasil export.
     */
    protected function filtered(Request $request): Builder
    {
        return Product::query()
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->input('stock') === 'low', fn ($query) => $query->lowStock()->where('stock', '>', 0))
            ->when($request->input('stock') === 'out', fn ($query) => $query->where('stock', '<=', 0))
            ->when($request->input('stock') === 'safe', fn ($query) => $query->whereColumn('stock', '>', 'min_stock'))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->movements()->exists()) {
            return back()->with('error', 'Barang sudah memiliki riwayat pergerakan stok dan tidak dapat dihapus. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $product->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Barang berhasil dihapus.');
    }
}
