<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
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

        $orders = $this->filtered($request)
            ->with([
                'items',
                'canceller',
                // Sum dipakai agar progres scan tidak perlu memuat baris barang.
                'outbound' => fn ($query) => $query
                    ->withSum('items', 'quantity')
                    ->withSum('items', 'scanned_quantity'),
            ])
            ->atStage($stage)
            ->latestFirst()
            ->paginate(20)
            ->withQueryString();

        return view('admin.imports.status', [
            'orders' => $orders,
            'stage' => $stage,
            'counts' => $this->counts($request),
            // Dropdown sengaja memuat seluruh ekspedisi, bukan hanya yang lolos
            // saringan: memilih ekspedisi lain harus tetap mungkin.
            'couriers' => ShipmentOrder::query()
                ->whereNotNull('courier')
                ->distinct()
                ->orderBy('courier')
                ->pluck('courier'),
        ]);
    }

    /**
     * Saringan yang berlaku atas daftar sekaligus atas kartu tahapnya.
     *
     * Tahap sengaja tidak termasuk: kartu-kartu itu justru pemilih tahapnya.
     */
    protected function filtered(Request $request): Builder
    {
        return ShipmentOrder::query()
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('courier'), fn (Builder $query) => $query->where('courier', $request->string('courier')))
            ->dateBetween(DateRange::fromRequest($request));
    }

    /**
     * Jumlah resi per tahap, untuk saringan yang sedang berlaku.
     *
     * Sebelumnya angka ini dihitung atas seluruh data sementara tabelnya sudah
     * disaring, sehingga kartu dan daftar bercerita beda — dan kartu "Semua
     * Resi", yang menjumlahkan keempatnya, menyebut angka yang tidak pernah
     * bisa ditemukan di halaman mana pun.
     *
     * @return array<string, int>
     */
    protected function counts(Request $request): array
    {
        return [
            ShipmentOrder::STAGE_AWAITING_QC => $this->filtered($request)->awaitingQc()->count(),
            ShipmentOrder::STAGE_CHECKED => $this->filtered($request)->qualityChecked()->count(),
            ShipmentOrder::STAGE_SHIPPED => $this->filtered($request)->shipped()->count(),
            ShipmentOrder::STAGE_CANCELLED => $this->filtered($request)->cancelled()->count(),
        ];
    }
}
