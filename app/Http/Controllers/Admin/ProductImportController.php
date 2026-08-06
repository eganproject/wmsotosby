<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use App\Services\ProductTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import master barang beserta stoknya dari Excel.
 */
class ProductImportController extends Controller implements HasMiddleware
{
    public function __construct(protected ProductImportService $importer)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.import'),
        ];
    }

    public function create(): View
    {
        return view('admin.products.import', [
            'history' => ProductImport::with('user')->latest('id')->take(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Lihat catatan pada ShipmentImportController: pemeriksaan memakai
        // akhiran berkas karena `mimes:` bergantung pada ekstensi PHP
        // `fileinfo` yang belum tentu aktif di server.
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:20480'],
        ], [
            'file.extensions' => 'Berkas harus berakhiran .xlsx, .xls, atau .csv. Unduh templatnya bila ragu.',
            'file.max' => 'Ukuran berkas melebihi 20 MB.',
        ], ['file' => 'berkas']);

        $import = $this->importer->import($request->file('file'));

        $message = "{$import->created_count} barang baru, {$import->updated_count} barang diperbarui";

        $message .= $import->stock_adjusted_count > 0
            ? ", dan {$import->stock_adjusted_count} stok disesuaikan."
            : '.';

        return redirect()
            ->route('admin.products.index')
            ->with('success', $message);
    }

    /**
     * Berkas contoh berisi judul kolom dan petunjuk pengisian.
     */
    public function template(ProductTemplateService $template): StreamedResponse
    {
        return $template->download('template-import-barang.xlsx');
    }
}
