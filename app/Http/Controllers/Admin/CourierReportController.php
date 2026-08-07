<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Beban kerja per ekspedisi pada suatu rentang tanggal.
 *
 * Halaman Status Resi menjawab "resi ini sampai mana". Halaman ini menjawab
 * pertanyaan yang ditanyakan tiap sore menjelang kurir datang: masing-masing
 * ekspedisi hari ini ada berapa, dan berapa yang belum siap.
 *
 * Yang tampil hanya ekspedisi yang benar-benar punya resi pada rentang itu.
 * Ekspedisi tanpa resi bukan baris bernilai nol — ia memang tidak ada urusannya
 * hari itu, dan menampilkannya hanya menambah baris yang harus dilewati mata.
 */
class CourierReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    public function __invoke(Request $request): View
    {
        $range = DateRange::fromRequest($request);

        $couriers = $this->couriers($range, $request->string('search')->trim()->value());

        return view('admin.imports.couriers', [
            'range' => $range,
            'couriers' => $couriers,
            'totals' => [
                'couriers' => $couriers->count(),
                'orders' => $couriers->sum('total'),
                'units' => $couriers->sum('units'),
                'awaiting' => $couriers->sum('awaiting'),
                'checked' => $couriers->sum('checked'),
                'shipped' => $couriers->sum('shipped'),
                'cancelled' => $couriers->sum('cancelled'),
            ],
        ]);
    }

    /**
     * Satu baris per ekspedisi, lengkap dengan sebaran tahapannya.
     *
     * Tiap tahap dihitung lewat scope yang sudah dipakai halaman Status Resi,
     * bukan disusun ulang sebagai satu query raksasa. Keempatnya saling lepas —
     * sebuah resi tidak pernah berada di dua tahap sekaligus — sehingga sisanya
     * bisa dihitung dengan pengurangan, dan angkanya dijamin sama persis dengan
     * yang tampil di halaman sebelah.
     *
     * @return Collection<int, object>
     */
    protected function couriers(DateRange $range, ?string $search): Collection
    {
        $base = fn () => ShipmentOrder::query()
            ->dateBetween($range)
            ->whereNotNull('courier')
            ->where('courier', '!=', '')
            ->when($search, fn (Builder $query, string $term) => $query->where('courier', 'like', "%{$term}%"));

        $totals = $this->countByCourier($base());
        $shipped = $this->countByCourier($base()->shipped());
        $checked = $this->countByCourier($base()->qualityChecked());
        $cancelled = $this->countByCourier($base()->cancelled());
        $units = $this->unitsByCourier($base());

        return collect($totals)
            ->map(function (int $total, string $courier) use ($shipped, $checked, $cancelled, $units) {
                $done = ($shipped[$courier] ?? 0) + ($checked[$courier] ?? 0) + ($cancelled[$courier] ?? 0);

                return (object) [
                    'courier' => $courier,
                    'total' => $total,
                    'units' => (int) ($units[$courier] ?? 0),
                    'shipped' => $shipped[$courier] ?? 0,
                    'checked' => $checked[$courier] ?? 0,
                    'cancelled' => $cancelled[$courier] ?? 0,
                    // Sisanya belum QC. Dihitung dengan pengurangan karena
                    // keempat tahap itu memang memilah habis seluruh resi.
                    'awaiting' => max(0, $total - $done),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @return array<string, int>
     */
    protected function countByCourier(Builder $query): array
    {
        return $query
            ->getQuery()
            ->select('courier')
            ->selectRaw('COUNT(*) as jumlah')
            ->groupBy('courier')
            ->pluck('jumlah', 'courier')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Jumlah unit yang dipesan, bukan jumlah resinya.
     *
     * @return array<string, int>
     */
    protected function unitsByCourier(Builder $query): array
    {
        return $query
            ->getQuery()
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->select('courier')
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as unit')
            ->groupBy('courier')
            ->pluck('unit', 'courier')
            ->map(fn ($value) => (int) $value)
            ->all();
    }
}
