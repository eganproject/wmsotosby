<?php

namespace App\Support;

/**
 * Satu baris laporan stok: saldo awal dan akhir periode, apa yang bergerak
 * di antaranya, dan angka-angka turunan yang menjawab "apakah stok ini sehat".
 *
 * Angka turunan sengaja dihitung di sini, bukan di SQL: hasilnya sama untuk
 * tabel di layar maupun berkas export, dan rumusnya bisa dibaca sekali tempat.
 */
class StockReportRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $category,
        public readonly string $unit,
        public readonly int $minStock,
        public readonly int $damaged,
        public readonly int $opening,
        public readonly int $incoming,
        public readonly int $outgoing,
        public readonly int $closing,
        public readonly ?string $lastOutAt,
        public readonly int $days,
    ) {
    }

    public static function fromQuery(object $row, int $days): self
    {
        return new self(
            id: (int) $row->id,
            sku: (string) $row->sku,
            name: (string) $row->name,
            category: $row->category ?: null,
            unit: (string) ($row->unit ?: 'pcs'),
            minStock: (int) $row->min_stock,
            damaged: (int) $row->damaged_stock,
            opening: (int) $row->opening,
            incoming: (int) $row->incoming,
            outgoing: (int) $row->outgoing,
            closing: (int) $row->closing,
            lastOutAt: $row->last_out_at ?: null,
            days: $days,
        );
    }

    /** Rata-rata unit yang keluar per hari selama periode. */
    public function perDay(): float
    {
        return $this->outgoing / $this->days;
    }

    /**
     * Rata-rata stok yang ditahan selama periode.
     *
     * Dipakai rata-rata awal dan akhir, bukan saldo hari ini: barang yang
     * sempat menumpuk lalu habis dan barang yang selalu tipis punya biaya
     * simpan yang jauh berbeda meskipun saldo akhirnya sama.
     */
    public function averageStock(): float
    {
        return ($this->opening + $this->closing) / 2;
    }

    /**
     * Berapa kali stok berputar selama periode. Null bila memang tidak ada
     * stok untuk diputar — nol kali dan "tidak ada datanya" bukan hal sama.
     */
    public function turnover(): ?float
    {
        $average = $this->averageStock();

        return $average > 0 ? $this->outgoing / $average : null;
    }

    /**
     * Perkiraan berapa hari lagi stok habis bila laju keluarnya bertahan.
     * Null untuk barang yang tidak bergerak sama sekali.
     */
    public function daysOfCover(): ?float
    {
        $perDay = $this->perDay();

        return $perDay > 0 ? $this->closing / $perDay : null;
    }

    public function isIdle(): bool
    {
        return $this->outgoing === 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->closing <= 0;
    }

    public function isLow(): bool
    {
        return $this->closing <= $this->minStock;
    }

    /**
     * Seberapa mendesak barang ini perlu diisi ulang.
     */
    public function urgency(): string
    {
        $cover = $this->daysOfCover();

        return match (true) {
            $this->isOutOfStock() => 'habis',
            $cover === null => 'diam',
            $cover <= 7 => 'kritis',
            $cover <= 14 => 'waspada',
            default => 'aman',
        };
    }

    /**
     * @return array{label: string, variant: string}
     */
    public function urgencyBadge(): array
    {
        return match ($this->urgency()) {
            'habis' => ['label' => 'Habis', 'variant' => 'danger'],
            'kritis' => ['label' => $this->coverLabel(), 'variant' => 'danger'],
            'waspada' => ['label' => $this->coverLabel(), 'variant' => 'warning'],
            'diam' => ['label' => 'Tidak bergerak', 'variant' => 'neutral'],
            default => ['label' => $this->coverLabel(), 'variant' => 'success'],
        };
    }

    /**
     * Perkiraan habis dalam bahasa manusia. Di atas tiga bulan angkanya tidak
     * lagi berarti apa-apa, jadi cukup disebut lebih dari itu.
     */
    public function coverLabel(): string
    {
        $cover = $this->daysOfCover();

        return match (true) {
            $cover === null => '—',
            $cover > 90 => '> 90 hari',
            $cover < 1 => 'kurang dari sehari',
            default => round($cover).' hari',
        };
    }

    public function turnoverLabel(): string
    {
        $turnover = $this->turnover();

        return $turnover === null ? '—' : number_format($turnover, 1, ',', '.').'×';
    }
}
