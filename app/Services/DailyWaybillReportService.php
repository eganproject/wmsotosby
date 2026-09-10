<?php

namespace App\Services;

use App\Models\ShipmentOrder;
use App\Support\DailyWaybillReportFilters;
use App\Support\DailyWaybillReportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap resi per tanggal masuk ke sistem.
 *
 * Tahap memakai scope ShipmentOrder yang sama dengan halaman Status Resi.
 * Dengan begitu angka harian dan daftar rinci tidak bisa memiliki definisi
 * Belum QC, Siap Dikirim, Dikirim, atau Dibatalkan yang berbeda.
 */
class DailyWaybillReportService
{
    /**
     * @return Collection<int, DailyWaybillReportRow>
     */
    public function rows(DailyWaybillReportFilters $filters): Collection
    {
        $base = fn () => $this->base($filters);

        $totals = $this->aggregate($base(), 'COUNT(*)');
        $units = $this->units($base());
        $couriers = $this->aggregate($base(), "COUNT(DISTINCT NULLIF(courier, ''))");
        $awaiting = $this->aggregate($base()->awaitingQc(), 'COUNT(*)');
        $checked = $this->aggregate($base()->qualityChecked(), 'COUNT(*)');
        $shipped = $this->aggregate($base()->shipped(), 'COUNT(*)');
        $cancelled = $this->aggregate($base()->cancelled(), 'COUNT(*)');

        return collect($totals)
            ->map(fn (int $total, string $date) => new DailyWaybillReportRow(
                date: $date,
                total: $total,
                units: $units[$date] ?? 0,
                couriers: $couriers[$date] ?? 0,
                awaiting: $awaiting[$date] ?? 0,
                checked: $checked[$date] ?? 0,
                shipped: $shipped[$date] ?? 0,
                cancelled: $cancelled[$date] ?? 0,
            ))
            ->sortByDesc('date')
            ->values();
    }

    /**
     * @param  Collection<int, DailyWaybillReportRow>  $rows
     * @return array<string, int|float>
     */
    public function summary(Collection $rows, DailyWaybillReportFilters $filters): array
    {
        $orders = (int) $rows->sum('total');
        $periodDays = $this->periodDays($rows, $filters);

        return [
            'days' => $rows->count(),
            'period_days' => $periodDays,
            'orders' => $orders,
            'average_per_day' => $periodDays > 0 ? (float) $orders / $periodDays : 0.0,
            'units' => $rows->sum('units'),
            'awaiting' => $rows->sum('awaiting'),
            'checked' => $rows->sum('checked'),
            'shipped' => $rows->sum('shipped'),
            'cancelled' => $rows->sum('cancelled'),
        ];
    }

    /**
     * Jumlah hari kalender pada saringan, termasuk tanggal tanpa resi.
     *
     * Untuk rentang terbuka maupun seluruh riwayat, sisi yang kosong diambil
     * dari tanggal pertama atau terakhir yang benar-benar lolos saringan.
     *
     * @param  Collection<int, DailyWaybillReportRow>  $rows
     */
    protected function periodDays(Collection $rows, DailyWaybillReportFilters $filters): int
    {
        $from = $filters->range->from ?? $rows->min('date');
        $to = $filters->range->to ?? $rows->max('date');

        if (! $from || ! $to) {
            return 0;
        }

        return rescue(function () use ($from, $to) {
            $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();

            return $start->greaterThan($end) ? 0 : (int) $start->diffInDays($end) + 1;
        }, 0, report: false);
    }

    public function couriers(): Collection
    {
        return ShipmentOrder::query()
            ->whereNotNull('courier')
            ->where('courier', '!=', '')
            ->distinct()
            ->orderBy('courier')
            ->pluck('courier');
    }

    protected function base(DailyWaybillReportFilters $filters): Builder
    {
        return ShipmentOrder::query()
            ->search($filters->search)
            ->when($filters->courier, fn (Builder $query, string $courier) => $query->where('courier', $courier))
            ->dateBetween($filters->range);
    }

    /**
     * @return array<string, int>
     */
    protected function aggregate(Builder $query, string $expression): array
    {
        return $query
            ->getQuery()
            ->selectRaw('DATE(shipment_orders.created_at) as report_date')
            ->selectRaw("{$expression} as aggregate")
            ->groupByRaw('DATE(shipment_orders.created_at)')
            ->pluck('aggregate', 'report_date')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function units(Builder $query): array
    {
        return $query
            ->getQuery()
            ->join('shipment_order_items as soi', 'soi.shipment_order_id', '=', 'shipment_orders.id')
            ->selectRaw('DATE(shipment_orders.created_at) as report_date')
            ->selectRaw('COALESCE(SUM(soi.quantity), 0) as aggregate')
            ->groupByRaw('DATE(shipment_orders.created_at)')
            ->pluck('aggregate', 'report_date')
            ->map(fn ($value) => (int) $value)
            ->all();
    }
}
