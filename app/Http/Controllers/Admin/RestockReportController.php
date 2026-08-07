<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RestockExportService;
use App\Services\RestockReportService;
use App\Support\RestockFilters;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan kebutuhan restock.
 *
 * Laporan Stok menjawab "bagaimana stok bergerak". Halaman ini menjawab satu
 * pertanyaan yang lebih sempit dan lebih mendesak: hari ini perlu memesan apa,
 * berapa banyak.
 */
class RestockReportController extends Controller implements HasMiddleware
{
    public function __construct(protected RestockReportService $report)
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
        $filters = RestockFilters::fromRequest($request);

        return view('admin.reports.restock', [
            'filters' => $filters,
            'rows' => $this->report->paginate($filters),
            'summary' => $this->report->summary($filters),
            'categories' => $this->report->categories(),
        ]);
    }

    public function export(Request $request, RestockExportService $exporter): StreamedResponse
    {
        $filters = RestockFilters::fromRequest($request);

        return $exporter->download(
            $this->report,
            $filters,
            'kebutuhan-restock-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }
}
