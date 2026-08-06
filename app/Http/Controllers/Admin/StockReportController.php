<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StockReportExportService;
use App\Services\StockReportService;
use App\Support\StockReportFilters;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan stok.
 *
 * Mutasi stok menjawab "apa yang terjadi", laporan ini menjawab "bagaimana
 * hasilnya": barang mana yang berputar cepat, mana yang menumpuk tanpa pernah
 * keluar, dan mana yang akan habis lebih dulu.
 */
class StockReportController extends Controller implements HasMiddleware
{
    public function __construct(protected StockReportService $report)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:reports.view', only: ['index']),
            new Middleware('can:reports.export', only: ['export']),
        ];
    }

    public function index(Request $request): View
    {
        $filters = StockReportFilters::fromRequest($request);

        return view('admin.reports.stock', [
            'filters' => $filters,
            'rows' => $this->report->paginate($filters),
            'summary' => $this->report->summary($filters),
            'categories' => $this->report->categories(),
        ]);
    }

    /**
     * Unduh laporan sesuai saringan yang sedang aktif.
     */
    public function export(Request $request, StockReportExportService $exporter): StreamedResponse
    {
        $filters = StockReportFilters::fromRequest($request);

        return $exporter->download(
            $this->report,
            $filters,
            'laporan-stok-'.$filters->from->format('Ymd').'-'.$filters->to->format('Ymd').'.xlsx',
        );
    }
}
