<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rentang dan saringan yang dipakai laporan stok.
 *
 * Dikumpulkan menjadi satu objek supaya tabel di layar, ringkasan di atasnya,
 * dan berkas hasil export dijamin memakai batasan yang persis sama — laporan
 * yang ketiganya bercerita beda lebih buruk daripada tidak ada laporan.
 */
class StockReportFilters
{
    /** Periode bawaan bila pengguna belum memilih tanggal. */
    public const DEFAULT_DAYS = 30;

    /**
     * Sudut pandang atas daftar barang.
     *
     * @var array<string, string>
     */
    public const VIEWS = [
        'semua' => 'Semua barang',
        'bergerak' => 'Yang bergerak',
        'mati' => 'Tidak bergerak',
        'menipis' => 'Menipis & habis',
    ];

    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'keluar' => 'Paling laku',
        'sisa' => 'Paling cepat habis',
        'masuk' => 'Paling banyak masuk',
        'stok' => 'Stok terbanyak',
        'nama' => 'Nama barang',
    ];

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?string $search = null,
        public readonly ?string $category = null,
        public readonly string $view = 'semua',
        public readonly string $sort = 'keluar',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $to = (static::date($request, 'to') ?? Carbon::now())->endOfDay();
        $from = (static::date($request, 'from') ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1))->startOfDay();

        // Tanggal terbalik lebih mungkin salah ketik daripada permintaan sungguhan.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $view = $request->string('view')->value();
        $sort = $request->string('sort')->value();

        return new self(
            from: $from,
            to: $to,
            search: $request->string('search')->trim()->value() ?: null,
            category: $request->string('category')->trim()->value() ?: null,
            view: isset(self::VIEWS[$view]) ? $view : 'semua',
            sort: isset(self::SORTS[$sort]) ? $sort : 'keluar',
        );
    }

    /**
     * Jumlah hari dalam periode. Minimal satu, supaya rata-rata harian tidak
     * pernah membagi dengan nol.
     */
    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to) + 1);
    }

    public function label(): string
    {
        return $this->from->translatedFormat('d M Y').' — '.$this->to->translatedFormat('d M Y');
    }

    public function viewLabel(): string
    {
        return self::VIEWS[$this->view];
    }

    /**
     * Tanggal dari query string, atau null bila kosong maupun tidak terbaca.
     *
     * Alamat yang disalin-tempel sering rusak sebagian; laporan lebih baik
     * jatuh ke periode bawaan daripada menampilkan halaman error.
     */
    protected static function date(Request $request, string $key): ?Carbon
    {
        if (! $request->filled($key)) {
            return null;
        }

        return rescue(fn () => $request->date($key), null, report: false);
    }
}
