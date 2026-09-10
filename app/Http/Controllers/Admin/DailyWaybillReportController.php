<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DailyWaybillReportExportService;
use App\Services\DailyWaybillReportService;
use App\Support\DailyWaybillReportFilters;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Laporan jumlah dan posisi resi per tanggal masuk ke sistem. */
class DailyWaybillReportController extends Controller implements HasMiddleware
{
    public function __construct(protected DailyWaybillReportService $report)
    {
    }

    public static function middleware(): array
    {
        return [new Middleware('can:imports.view')];
    }

    public function index(Request $request): View
    {
        $filters = DailyWaybillReportFilters::fromRequest($request);
        $rows = $this->report->rows($filters);

        return view('admin.imports.daily', [
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $this->report->summary($rows),
            'couriers' => $this->report->couriers(),
        ]);
    }

    public function export(Request $request, DailyWaybillReportExportService $exporter): StreamedResponse
    {
        $filters = DailyWaybillReportFilters::fromRequest($request);

        return $exporter->download(
            $this->report->rows($filters),
            $filters,
            'laporan-resi-harian-'.now()->format('Y-m-d-Hi').'.xlsx',
        );
    }
}
