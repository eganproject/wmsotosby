<?php

namespace App\Support;

/**
 * Satu definisi klasifikasi kecepatan barang untuk seluruh laporan.
 *
 * Jumlah keluar dinormalisasi ke 30 hari. Tanpa normalisasi, barang yang sama
 * dapat berubah kelas hanya karena pengguna mengganti rentang laporan dari
 * 30 menjadi 90 hari meskipun laju hariannya tidak berubah.
 */
class StockVelocity
{
    public const NORMALIZED_DAYS = 30;

    public const FAST = 'fast';

    public const MEDIUM = 'medium';

    public const SLOW = 'slow';

    public const NON_MOVING = 'non_moving';

    /**
     * @return array<string, string>
     */
    public static function classes(): array
    {
        return [
            self::FAST => 'Fast Moving',
            self::MEDIUM => 'Medium Moving',
            self::SLOW => 'Slow Moving',
            self::NON_MOVING => 'Non-Moving',
        ];
    }

    /**
     * Ambang selalu dijaga masuk akal meskipun nilai environment keliru.
     *
     * @return array{fast: int, medium: int}
     */
    public static function thresholds(): array
    {
        $fast = max(2, (int) config('stock_classification.fast_moving_min_30_days', 30));
        $medium = max(1, min($fast - 1, (int) config('stock_classification.medium_moving_min_30_days', 10)));

        return compact('fast', 'medium');
    }

    public static function equivalent30Days(int $outgoing, int $days): float
    {
        return $outgoing / max(1, $days) * self::NORMALIZED_DAYS;
    }

    public static function classify(int $outgoing, int $days): string
    {
        if ($outgoing <= 0) {
            return self::NON_MOVING;
        }

        $velocity = self::equivalent30Days($outgoing, $days);
        $thresholds = self::thresholds();

        return match (true) {
            $velocity >= $thresholds['fast'] => self::FAST,
            $velocity >= $thresholds['medium'] => self::MEDIUM,
            default => self::SLOW,
        };
    }

    /**
     * @return array{label: string, variant: string}
     */
    public static function badge(int $outgoing, int $days): array
    {
        $class = self::classify($outgoing, $days);

        return [
            'label' => self::classes()[$class],
            'variant' => match ($class) {
                self::FAST => 'success',
                self::MEDIUM => 'warning',
                self::SLOW => 'outline',
                default => 'neutral',
            },
        ];
    }

    public static function thresholdLabel(): string
    {
        $thresholds = self::thresholds();

        return "Fast ≥ {$thresholds['fast']}, Medium ≥ {$thresholds['medium']} sampai < {$thresholds['fast']}, "
            ."Slow > 0 sampai < {$thresholds['medium']}, dan Non-Moving 0 unit per 30 hari";
    }
}
