<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Saringan laporan resi harian.
 *
 * Berbeda dari daftar operasional yang dibuka pada hari ini saja, laporan
 * harian perlu memperlihatkan beberapa tanggal sekaligus. Karena itu bawaan
 * laporan adalah awal bulan berjalan sampai hari ini.
 */
class DailyWaybillReportFilters
{
    public function __construct(
        public readonly DateRange $range,
        public readonly ?string $search = null,
        public readonly ?string $courier = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $range = $request->hasAny(['range', 'from', 'to'])
            ? DateRange::fromRequest($request)
            : new DateRange(Carbon::now()->startOfMonth()->toDateString(), Carbon::today()->toDateString());

        return new self(
            range: $range,
            search: $request->string('search')->trim()->value() ?: null,
            courier: $request->string('courier')->trim()->value() ?: null,
        );
    }

    public function label(): string
    {
        if ($this->range->isAll()) {
            return 'Semua tanggal';
        }

        $format = fn (?string $date) => rescue(
            fn () => filled($date) ? Carbon::createFromFormat('Y-m-d', $date)->translatedFormat('d M Y') : null,
            null,
            report: false,
        );

        $from = $format($this->range->from);
        $to = $format($this->range->to);

        return match (true) {
            $from && $to && $from === $to => $from,
            $from && $to => "{$from} — {$to}",
            (bool) $from => "Mulai {$from}",
            (bool) $to => "Sampai {$to}",
            default => 'Semua tanggal',
        };
    }
}
