<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Services\GineeImportService;
use App\Services\ShipmentOrderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Import nomor resi dari berkas eksport Ginee.
 *
 * Data ini menjadi rujukan saat scan resi pada barang keluar marketplace
 * dan pada penerimaan retur.
 */
class ShipmentImportController extends Controller implements HasMiddleware
{
    public function __construct(
        protected GineeImportService $importer,
        protected ShipmentOrderResolver $resolver,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view', only: ['index', 'show', 'batches', 'lookup']),
            new Middleware('can:imports.create', only: ['create', 'store']),
            new Middleware('can:imports.delete', only: ['destroy']),
        ];
    }

    /**
     * Daftar resi hasil import — tampilan harian yang paling sering dipakai.
     */
    public function index(Request $request): View
    {
        $orders = ShipmentOrder::query()
            ->with(['items.product', 'import'])
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('marketplace'), fn ($query) => $query->where('marketplace', $request->string('marketplace')))
            ->when($request->filled('courier'), fn ($query) => $query->where('courier', $request->string('courier')))
            ->when($request->input('match') === 'unmatched', fn ($query) => $query->whereHas('items', fn ($items) => $items->whereNull('product_id')))
            ->when($request->input('match') === 'matched', fn ($query) => $query->whereDoesntHave('items', fn ($items) => $items->whereNull('product_id')))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.imports.index', [
            'orders' => $orders,
            'marketplaces' => ShipmentOrder::query()->whereNotNull('marketplace')->distinct()->orderBy('marketplace')->pluck('marketplace'),
            'couriers' => ShipmentOrder::query()->whereNotNull('courier')->distinct()->orderBy('courier')->pluck('courier'),
            'summary' => [
                'orders' => ShipmentOrder::count(),
                'items' => \App\Models\ShipmentOrderItem::count(),
                'unmatched' => \App\Models\ShipmentOrderItem::whereNull('product_id')->count(),
                'batches' => ShipmentImport::count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.imports.create');
    }

    public function store(Request $request): RedirectResponse
    {
        // Diperiksa lewat akhiran berkas, bukan tebakan isi.
        //
        // Aturan `mimes:` menebak jenis berkas dari isinya lewat ekstensi PHP
        // `fileinfo`. Bila ekstensi itu tidak aktif — lazim di shared hosting —
        // tebakannya selalu gagal dan setiap berkas ditolak, termasuk xlsx yang
        // sah. Pembaca spreadsheet di bawah tetap menjadi penjaga sebenarnya:
        // berkas yang isinya bukan spreadsheet ditolak di sana dengan pesan
        // yang menjelaskan.
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:20480'],
        ], [
            'file.extensions' => 'Berkas harus berakhiran .xlsx, .xls, atau .csv — hasil eksport langsung dari Ginee.',
            'file.max' => 'Ukuran berkas melebihi 20 MB.',
        ], ['file' => 'berkas']);

        try {
            $import = $this->importer->import($request->file('file'));
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $message = "{$import->order_count} resi dan {$import->item_count} baris barang berhasil diimport.";

        if ($import->unmatched_sku_count > 0) {
            $message .= " {$import->unmatched_sku_count} baris memiliki SKU yang belum terdaftar di master barang.";
        }

        return redirect()
            ->route('admin.imports.show', $import)
            ->with($import->unmatched_sku_count > 0 ? 'error' : 'success', $message);
    }

    /**
     * Detail satu berkas import.
     */
    public function show(ShipmentImport $import): View
    {
        $import->load('user');

        return view('admin.imports.show', [
            'import' => $import,
            'orders' => $import->orders()->with('items.product')->latest('id')->paginate(15),
        ]);
    }

    /**
     * Riwayat seluruh berkas yang pernah diimport.
     */
    public function batches(): View
    {
        return view('admin.imports.batches', [
            'imports' => ShipmentImport::with('user')->latest('id')->paginate(10),
        ]);
    }

    public function destroy(ShipmentImport $import): RedirectResponse
    {
        $import->delete();

        return redirect()
            ->route('admin.imports.batches')
            ->with('success', 'Data import beserta resinya berhasil dihapus.');
    }

    /**
     * Dipakai form barang keluar / retur untuk menarik isi pesanan
     * berdasarkan nomor resi yang diketik.
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = $request->validate([
            'resi' => ['required', 'string', 'max:191'],
        ])['resi'];

        $order = $this->resolver->resolve($code);

        if (! $order) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true] + $this->resolver->toPayload($order));
    }
}
