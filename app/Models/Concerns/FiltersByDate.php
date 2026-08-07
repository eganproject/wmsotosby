<?php

namespace App\Models\Concerns;

use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saringan rentang tanggal yang dipakai bersama seluruh daftar dokumen.
 *
 * Ditaruh di satu tempat, bukan diulang di tiap controller, karena bagian yang
 * gampang salah bukan penulisan query-nya melainkan penanganan tepinya:
 * batasnya harus inklusif di kedua ujung, dan masukan yang tidak terbaca harus
 * diabaikan alih-alih menjatuhkan halaman.
 */
trait FiltersByDate
{
    /**
     * Saring menurut rentang tanggal. Kedua ujungnya inklusif.
     *
     * Rentangnya diterima sebagai objek, bukan sepasang string, supaya setiap
     * daftar melewati resolusi yang sama — termasuk bawaan hari berjalan dan
     * penanda "semua tanggal". Dua daftar yang menafsirkan alamat yang sama
     * secara berbeda adalah jenis kesalahan yang sangat sulit disadari.
     */
    public function scopeDateBetween(Builder $query, DateRange $range): Builder
    {
        $column = $this->dateFilterColumn();

        return $query
            ->when(static::dateFilterValue($range->from), fn (Builder $query, string $date) => $query->whereDate($column, '>=', $date))
            ->when(static::dateFilterValue($range->to), fn (Builder $query, string $date) => $query->whereDate($column, '<=', $date));
    }

    /**
     * Kolom tanggal yang mewakili dokumen ini. Ditimpa model yang tanggal
     * pentingnya bukan bernama "date".
     */
    protected function dateFilterColumn(): string
    {
        return 'date';
    }

    /**
     * Terima hanya bentuk yang dikirim kolom tanggal HTML; sisanya null.
     *
     * Namanya sengaja panjang: Eloquent sudah punya asDate() sendiri untuk
     * casting atribut, dan menimpanya membuat model gagal dimuat sama sekali.
     */
    protected static function dateFilterValue(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
