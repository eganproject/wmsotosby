<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rentang dan saringan laporan kebutuhan restock.
 */
class RestockFilters
{
    /** Periode pengamatan laju keluar, bila pengguna belum memilih. */
    public const DEFAULT_DAYS = 30;

    /** Berapa hari ke depan yang ingin diamankan stoknya. */
    public const DEFAULT_COVER = 30;

    /**
     * @var array<string, string>
     */
    public const VIEWS = [
        'perlu' => 'Perlu dipesan',
        'menipis' => 'Menipis & habis',
        'semua' => 'Semua barang',
    ];

    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'mendesak' => 'Paling mendesak',
        'jumlah' => 'Saran pesan terbanyak',
        'laku' => 'Paling laku',
        'nama' => 'Nama barang',
    ];

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly int $coverDays,
        public readonly ?string $search = null,
        public readonly ?string $category = null,
        public readonly string $view = 'perlu',
        public readonly string $sort = 'mendesak',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $to = (static::date($request, 'to') ?? Carbon::now())->endOfDay();
        $from = (static::date($request, 'from') ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1))->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $view = $request->string('view')->value();
        $sort = $request->string('sort')->value();

        return new self(
            from: $from,
            to: $to,
            // Dibatasi supaya satu angka salah ketik tidak melahirkan saran
            // pesan sejuta unit.
            coverDays: max(1, min(365, (int) $request->input('cover', self::DEFAULT_COVER))),
            search: $request->string('search')->trim()->value() ?: null,
            category: $request->string('category')->trim()->value() ?: null,
            view: isset(self::VIEWS[$view]) ? $view : 'perlu',
            sort: isset(self::SORTS[$sort]) ? $sort : 'mendesak',
        );
    }

    /** Jumlah hari periode pengamatan; minimal satu supaya tidak membagi nol. */
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

    protected static function date(Request $request, string $key): ?Carbon
    {
        if (! $request->filled($key)) {
            return null;
        }

        return rescue(fn () => $request->date($key), null, report: false);
    }
}
