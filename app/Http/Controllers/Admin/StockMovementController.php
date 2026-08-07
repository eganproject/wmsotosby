<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockMovementExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mutasi stok: seluruh pergerakan barang dari semua dokumen, dalam satu daftar.
 *
 * Halaman kartu stok per barang menjawab "apa yang terjadi pada barang ini".
 * Halaman ini menjawab "apa yang terjadi di gudang" — termasuk saat yang
 * dicari belum diketahui barangnya.
 */
class StockMovementController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:movements.view', only: ['index']),
            new Middleware('can:movements.export', only: ['export']),
        ];
    }

    public function index(Request $request): View
    {
        $movements = $this->filtered($request)
            ->with(['product', 'user'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.movements.index', [
            'movements' => $movements,
            'summary' => $this->summary($request),
            'sources' => StockMovement::SOURCES,
            // Dipakai dropdown pencarian barang; hanya yang pernah bergerak.
            'products' => Product::query()
                ->whereHas('movements')
                ->orderBy('name')
                ->get(['id', 'sku', 'name']),
        ]);
    }

    /**
     * Unduh mutasi sesuai filter yang sedang aktif.
     */
    public function export(Request $request, StockMovementExportService $exporter): StreamedResponse
    {
        return $exporter->download(
            $this->filtered($request)->with(['product', 'user']),
            'mutasi-stok-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }

    /**
     * Filter yang sama dipakai tabel di layar, ringkasannya, maupun berkas
     * hasil export — supaya ketiganya tidak pernah bercerita beda.
     */
    protected function filtered(Request $request): Builder
    {
        // Rentangnya lewat resolusi yang sama dengan daftar lain: bawaannya
        // hari berjalan, dan masukan yang tidak terbaca diabaikan alih-alih
        // menjatuhkan halaman.
        $range = DateRange::fromRequest($request);

        return StockMovement::query()
            ->search($request->string('search')->trim()->value())
            ->fromSource($request->string('source')->value())
            ->inBucket($request->string('bucket')->value())
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when(in_array($request->input('type'), ['in', 'out'], true), fn ($query) => $query->where('type', $request->input('type')))
            ->when($range->from, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($range->to, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
    }

    /**
     * Ringkasan periode yang sedang dilihat, dihitung dalam satu query.
     *
     * @return array<string, int>
     */
    protected function summary(Request $request): array
    {
        $row = $this->filtered($request)
            ->toBase()
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0) as incoming")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) as outgoing")
            ->selectRaw('COUNT(DISTINCT product_id) as products')
            ->first();

        return [
            'entries' => (int) ($row->entries ?? 0),
            'incoming' => (int) ($row->incoming ?? 0),
            'outgoing' => (int) ($row->outgoing ?? 0),
            'net' => (int) ($row->incoming ?? 0) - (int) ($row->outgoing ?? 0),
            'products' => (int) ($row->products ?? 0),
        ];
    }
}
