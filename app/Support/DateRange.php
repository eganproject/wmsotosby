<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rentang tanggal yang sedang berlaku pada sebuah daftar.
 *
 * Bawaannya hari berjalan: pekerjaan gudang hampir selalu tentang hari ini, dan
 * daftar yang membuka seluruh riwayat memaksa orang menyaring ulang tiap kali.
 *
 * Karena itu "semua tanggal" harus diminta terang-terangan lewat penanda
 * tersendiri, bukan dengan mengosongkan kedua tanggalnya — kosong sudah berarti
 * hari ini, dan tanpa penanda itu daftar tidak akan pernah bisa dibuka penuh.
 *
 * Dipakai bersama oleh controller dan komponen saringannya supaya keduanya
 * tidak mungkin berbeda pendapat tentang rentang mana yang sedang berlaku.
 */
class DateRange
{
    /** Nilai parameter yang berarti "jangan disaring tanggalnya sama sekali". */
    public const ALL = 'semua';

    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        if ($request->input('range') === self::ALL) {
            return new self(null, null);
        }

        $from = static::date($request->input('from'));
        $to = static::date($request->input('to'));

        if ($from === null && $to === null) {
            $today = Carbon::today()->toDateString();

            return new self($today, $today);
        }

        return new self($from, $to);
    }

    public function isAll(): bool
    {
        return $this->from === null && $this->to === null;
    }

    public function isToday(): bool
    {
        $today = Carbon::today()->toDateString();

        return $this->from === $today && $this->to === $today;
    }

    /**
     * Bentuk yang bisa ditempelkan ke tautan agar rentangnya ikut terbawa.
     *
     * @return array<string, string|null>
     */
    public function toQuery(): array
    {
        return $this->isAll()
            ? ['range' => self::ALL, 'from' => null, 'to' => null]
            : ['range' => null, 'from' => $this->from, 'to' => $this->to];
    }

    /**
     * Terima hanya bentuk yang dikirim kolom tanggal HTML; sisanya diabaikan.
     *
     * Alamat yang disalin tempel sering rusak sebagian, dan daftar yang tetap
     * tampil lebih berguna daripada halaman galat.
     */
    protected static function date(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }
}
