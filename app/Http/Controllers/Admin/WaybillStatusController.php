<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Status resi: posisi setiap nomor resi hasil import di dalam alur gudang.
 *
 * Tahapannya dibaca dari data yang sudah ada — bukan status yang diketik
 * manual, sehingga tidak mungkin melenceng dari kenyataan:
 *
 *   Belum QC   → resi atau barangnya belum tuntas discan
 *   Siap Kirim → scan lengkap, dokumen belum diproses
 *   Dikirim    → dokumen disetujui, stok sudah berkurang
 *   Dibatalkan → pesanan batal dan barangnya belum berangkat
 *
 * Kecuali yang terakhir: pembatalan memang datang dari luar gudang, entah
 * terbaca dari berkas import atau ditandai petugas.
 */
class WaybillStatusController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    public function __invoke(Request $request): View
    {
        $stage = $request->string('stage')->value();

        $orders = ShipmentOrder::query()
            ->with([
                'items',
                'canceller',
                // Sum dipakai agar progres scan tidak perlu memuat baris barang.
                'outbound' => fn ($query) => $query
                    ->withSum('items', 'quantity')
                    ->withSum('items', 'scanned_quantity'),
            ])
            ->atStage($stage)
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('courier'), fn ($query) => $query->where('courier', $request->string('courier')))
            ->dateBetween($request->input('from'), $request->input('to'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.imports.status', [
            'orders' => $orders,
            'stage' => $stage,
            'counts' => $this->counts(),
            'couriers' => ShipmentOrder::query()
                ->whereNotNull('courier')
                ->distinct()
                ->orderBy('courier')
                ->pluck('courier'),
        ]);
    }

    /**
     * Jumlah resi per tahap untuk seluruh data, bukan hanya halaman ini.
     *
     * @return array<string, int>
     */
    protected function counts(): array
    {
        return [
            ShipmentOrder::STAGE_AWAITING_QC => ShipmentOrder::awaitingQc()->count(),
            ShipmentOrder::STAGE_CHECKED => ShipmentOrder::qualityChecked()->count(),
            ShipmentOrder::STAGE_SHIPPED => ShipmentOrder::shipped()->count(),
            ShipmentOrder::STAGE_CANCELLED => ShipmentOrder::cancelled()->count(),
        ];
    }
}
