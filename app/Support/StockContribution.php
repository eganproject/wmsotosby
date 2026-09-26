<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Klasifikasi pergerakan barang pada laporan stok berdasarkan kontribusi
 * kumulatif terhadap seluruh qty keluar operasional periode itu.
 *
 * Barang diurutkan dari yang paling banyak keluar. Kelasnya ditentukan oleh
 * titik mulai lapisannya, yaitu porsi kumulatif barang-barang di atasnya:
 * - Fast Moving   : dimulai sebelum 70%
 * - Medium Moving : dimulai dari 70% sampai sebelum 90%
 * - Slow Moving   : dimulai dari 90%
 * - Non-Moving    : tidak keluar sama sekali selama periode
 *
 * Dipakai titik mulai, bukan titik akhir, supaya barang teratas selalu Fast
 * Moving meskipun ia sendirian menyumbang lebih dari 70%. Barang dengan qty
 * keluar yang sama selalu satu kelas: yang dihitung di atasnya hanya barang
 * yang keluarnya lebih banyak, jadi urutan abjad tidak pernah menentukan kelas.
 *
 * Karena kelas naik-turun mengikuti qty keluar, hasilnya cukup diringkas
 * menjadi dua batas qty. Batas itulah yang dipakai tabel, ringkasan, dan
 * export untuk menyaring di basis data.
 */
class StockContribution
{
    public const FAST_SHARE = 0.70;

    public const MEDIUM_SHARE = 0.90;

    /**
     * @param  array<int, int>  $through  Porsi kumulatif (unit) sampai dengan tiap qty keluar, kunci = qty.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $fastMin,
        public readonly int $mediumMin,
        protected readonly array $through = [],
    ) {
    }

    /**
     * @param  Collection<int, object{quantity: int|string, products: int|string}>|iterable  $levels
     *         Setiap qty keluar yang terjadi beserta jumlah barang yang keluar sebanyak itu.
     */
    public static function fromLevels(iterable $levels): self
    {
        $levels = collect($levels)
            ->map(fn (object $level) => ['quantity' => (int) $level->quantity, 'products' => (int) $level->products])
            ->filter(fn (array $level) => $level['quantity'] > 0)
            ->sortByDesc('quantity')
            ->values();

        $total = $levels->sum(fn (array $level) => $level['quantity'] * $level['products']);

        // Tanpa barang keluar tidak ada yang bisa diklasifikasi; batas di atas
        // qty mana pun membuat semua barang jatuh ke Non-Moving.
        $fastMin = $mediumMin = PHP_INT_MAX;
        $above = 0;
        $through = [];

        foreach ($levels as $level) {
            if ($above < $total * self::FAST_SHARE) {
                $fastMin = $level['quantity'];
            }

            if ($above < $total * self::MEDIUM_SHARE) {
                $mediumMin = $level['quantity'];
            }

            $above += $level['quantity'] * $level['products'];
            $through[$level['quantity']] = $above;
        }

        return new self($total, $fastMin, $mediumMin, $through);
    }

    public function classify(int $outgoing): string
    {
        return match (true) {
            $outgoing <= 0 => StockVelocity::NON_MOVING,
            $outgoing >= $this->fastMin => StockVelocity::FAST,
            $outgoing >= $this->mediumMin => StockVelocity::MEDIUM,
            default => StockVelocity::SLOW,
        };
    }

    /**
     * @return array{label: string, variant: string}
     */
    public function badge(int $outgoing): array
    {
        return StockVelocity::badgeFor($this->classify($outgoing));
    }

    /** Porsi qty keluar barang ini terhadap seluruh qty keluar, dalam persen. */
    public function share(int $outgoing): float
    {
        return $this->total > 0 ? $outgoing / $this->total * 100 : 0.0;
    }

    /**
     * Kontribusi kumulatif sampai dengan barang ini, dalam persen.
     *
     * Barang dengan qty sama berbagi satu angka, yaitu ujung lapisan mereka
     * bersama, supaya angkanya tidak bergantung pada urutan abjad.
     */
    public function cumulativeShare(int $outgoing): ?float
    {
        if ($outgoing <= 0 || $this->total <= 0 || ! isset($this->through[$outgoing])) {
            return null;
        }

        return $this->through[$outgoing] / $this->total * 100;
    }

    public static function ruleLabel(): string
    {
        return 'Fast Moving mengisi kontribusi kumulatif awal hingga 70% qty keluar, '
            .'Medium Moving lapisan 70–90%, Slow Moving sisanya setelah 90%, '
            .'dan Non-Moving tidak punya barang keluar operasional selama periode';
    }
}
