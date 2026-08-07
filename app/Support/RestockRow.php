<?php

namespace App\Support;

/**
 * Satu baris kebutuhan restock.
 *
 * Menjawab satu pertanyaan saja: barang ini perlu dipesan berapa. Angkanya
 * disusun dari tiga hal yang biasanya dilihat terpisah — saldo di rak, yang
 * sudah terlanjur dijanjikan ke pembeli, dan laju keluarnya.
 */
class RestockRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $category,
        public readonly ?string $location,
        public readonly string $unit,
        public readonly int $stock,
        public readonly int $damaged,
        public readonly int $committed,
        public readonly int $minStock,
        public readonly int $outgoing,
        public readonly int $days,
        public readonly int $coverDays,
    ) {
    }

    public static function fromQuery(object $row, int $days, int $coverDays): self
    {
        return new self(
            id: (int) $row->id,
            sku: (string) $row->sku,
            name: (string) $row->name,
            category: $row->category ?: null,
            location: $row->location ?: null,
            unit: (string) ($row->unit ?: 'pcs'),
            stock: (int) $row->stock,
            damaged: (int) $row->damaged_stock,
            committed: (int) $row->committed,
            minStock: (int) $row->min_stock,
            outgoing: (int) $row->outgoing,
            days: $days,
            coverDays: $coverDays,
        );
    }

    /**
     * Saldo yang benar-benar bebas dipakai.
     *
     * Unit yang sudah masuk dokumen barang keluar tetapi belum diproses masih
     * terhitung di saldo gudang, padahal sudah dijanjikan ke pembeli. Menghitung
     * kebutuhan tanpa mengeluarkannya berarti memesan terlambat.
     */
    public function available(): int
    {
        return $this->stock - $this->committed;
    }

    /** Rata-rata unit keluar per hari selama periode yang diamati. */
    public function perDay(): float
    {
        return $this->outgoing / max(1, $this->days);
    }

    /** Berapa hari lagi yang tersedia akan habis pada laju sekarang. */
    public function daysOfCover(): ?float
    {
        $perDay = $this->perDay();

        return $perDay > 0 ? max(0, $this->available()) / $perDay : null;
    }

    /** Kekurangan terhadap batas menipis, dihitung dari saldo yang bebas. */
    public function shortfall(): int
    {
        return max(0, $this->minStock - $this->available());
    }

    /**
     * Kebutuhan untuk menutup sekian hari ke depan pada laju sekarang.
     */
    public function forecastNeed(): int
    {
        return max(0, (int) ceil($this->perDay() * $this->coverDays) - $this->available());
    }

    /**
     * Saran jumlah pesan: yang lebih besar antara mengembalikan saldo ke batas
     * menipis dan menutup kebutuhan sekian hari ke depan.
     *
     * Keduanya menjawab kekhawatiran berbeda — batas menipis menjaga barang
     * yang jarang bergerak tetap ada, ramalan laju menjaga barang laris tidak
     * kehabisan — dan mengambil yang terbesar memenuhi keduanya sekaligus.
     */
    public function suggested(): int
    {
        return max($this->shortfall(), $this->forecastNeed());
    }

    public function needsRestock(): bool
    {
        return $this->suggested() > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->available() <= 0;
    }

    public function isBelowMinimum(): bool
    {
        return $this->available() <= $this->minStock;
    }

    /** Barang tanpa laju keluar sama sekali pada periode yang diamati. */
    public function isIdle(): bool
    {
        return $this->outgoing === 0;
    }

    public function urgency(): string
    {
        $cover = $this->daysOfCover();

        return match (true) {
            $this->isOutOfStock() => 'habis',
            $cover !== null && $cover <= 7 => 'kritis',
            $this->isBelowMinimum() => 'menipis',
            $cover !== null && $cover <= $this->coverDays => 'waspada',
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
            'menipis' => ['label' => 'Menipis', 'variant' => 'warning'],
            'waspada' => ['label' => $this->coverLabel(), 'variant' => 'warning'],
            default => ['label' => 'Aman', 'variant' => 'success'],
        };
    }

    public function coverLabel(): string
    {
        $cover = $this->daysOfCover();

        return match (true) {
            $cover === null => 'Tidak bergerak',
            $cover < 1 => 'Habis hari ini',
            $cover > 90 => '> 90 hari',
            default => round($cover).' hari lagi',
        };
    }

    /**
     * Kenapa jumlah ini yang disarankan — supaya angkanya bisa dipercaya, bukan
     * sekadar muncul.
     */
    public function reason(): string
    {
        if (! $this->needsRestock()) {
            return 'Cukup';
        }

        return $this->forecastNeed() >= $this->shortfall()
            ? "Menutup {$this->coverDays} hari ke depan"
            : 'Mengembalikan ke batas menipis';
    }
}
