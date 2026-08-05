<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\OutboundScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Verifikasi pengiriman marketplace: scan resi lalu scan barang.
 *
 * Endpoint scan mengembalikan JSON supaya panel bisa diperbarui seketika
 * tanpa memuat ulang halaman.
 */
class OutboundScanController extends Controller implements HasMiddleware
{
    public function __construct(protected OutboundScanService $scanner)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.scan'),
        ];
    }

    public function show(Outbound $outbound): View|RedirectResponse
    {
        if (! $outbound->isMarketplace()) {
            return redirect()
                ->route('admin.outbounds.show', $outbound)
                ->with('error', 'Verifikasi scan hanya berlaku untuk pengiriman marketplace.');
        }

        $outbound->load('items.product');

        return view('admin.outbounds.scan', [
            'outbound' => $outbound,
            'progress' => $this->scanner->progress($outbound),
        ]);
    }

    public function resi(Request $request, Outbound $outbound): JsonResponse
    {
        $code = $this->code($request);

        $result = $this->scanner->verifyResi($outbound, $code);

        return response()->json($result + ['progress' => $this->scanner->progress($outbound)]);
    }

    public function item(Request $request, Outbound $outbound): JsonResponse
    {
        $code = $this->code($request);

        $outbound->load('items.product');

        $result = $this->scanner->scanItem($outbound, $code);

        return response()->json($result + ['progress' => $this->scanner->progress($outbound)]);
    }

    public function reset(Outbound $outbound): RedirectResponse
    {
        $this->scanner->reset($outbound);

        return back()->with('success', 'Progres scan direset. Mulai kembali dari scan resi.');
    }

    protected function code(Request $request): string
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];
    }
}
