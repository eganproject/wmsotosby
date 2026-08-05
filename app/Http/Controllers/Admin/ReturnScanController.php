<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReturnReceipt;
use App\Services\ReturnScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Verifikasi resi pada penerimaan retur, baik dari marketplace maupun
 * pengiriman biasa yang memakai kurir.
 */
class ReturnScanController extends Controller implements HasMiddleware
{
    public function __construct(protected ReturnScanService $scanner)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.scan'),
        ];
    }

    public function show(ReturnReceipt $return): View|RedirectResponse
    {
        if (! $return->requiresResiScan()) {
            return redirect()
                ->route('admin.returns.show', $return)
                ->with('error', 'Dokumen retur ini tidak memiliki nomor resi untuk diverifikasi.');
        }

        $return->load('items.product');

        return view('admin.returns.scan', [
            'return' => $return,
            'progress' => $this->scanner->progress($return),
        ]);
    }

    public function resi(Request $request, ReturnReceipt $return): JsonResponse
    {
        $code = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];

        $result = $this->scanner->verifyResi($return, $code);

        return response()->json($result + ['progress' => $this->scanner->progress($return)]);
    }

    public function reset(ReturnReceipt $return): RedirectResponse
    {
        $this->scanner->reset($return);

        return back()->with('success', 'Verifikasi resi direset. Silakan scan ulang.');
    }
}
