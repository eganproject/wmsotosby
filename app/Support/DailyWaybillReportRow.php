<?php

namespace App\Support;

/** Satu tanggal pada laporan resi harian. */
class DailyWaybillReportRow
{
    public function __construct(
        public readonly string $date,
        public readonly int $total,
        public readonly int $units,
        public readonly int $couriers,
        public readonly int $awaiting,
        public readonly int $checked,
        public readonly int $shipped,
        public readonly int $cancelled,
    ) {
    }

    /** Resi yang tidak lagi menunggu pekerjaan QC. */
    public function resolved(): int
    {
        return $this->checked + $this->shipped + $this->cancelled;
    }

    public function readiness(): int
    {
        return $this->total > 0
            ? (int) round($this->resolved() / $this->total * 100)
            : 100;
    }
}
