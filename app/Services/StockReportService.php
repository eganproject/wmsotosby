<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\StockReportFilters;
use App\Support\StockReportRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Laporan stok: saldo awal dan akhir tiap barang pada suatu periode, apa yang
 * bergerak di antaranya, dan seberapa cepat stoknya berputar.
 *
 * Semuanya dihitung di sisi basis data. Memuat seluruh barang beserta
 * mutasinya ke memori memang lebih mudah ditulis, tetapi laporan adalah
 * halaman yang justru dibuka saat datanya sudah banyak.
 *
 * Yang dilaporkan hanya saldo layak jual. Barang rusak punya saldo sendiri
 * dan tidak pernah ikut dijual, jadi mencampurnya ke perhitungan perputaran
 * hanya membuat angkanya terlihat lebih sehat daripada kenyataan.
 */
class StockReportService
{
    /**
     * Saldo pada akhir periode.
     *
     * Yang tersimpan di products.stock adalah saldo hari ini, jadi untuk
     * periode yang sudah lewat ia ditarik mundur oleh mutasi sesudahnya.
     * Untuk periode yang berakhir hari ini, koreksinya nol.
     */
    protected const CLOSING = '(p.stock - COALESCE(m.in_after, 0) + COALESCE(m.out_after, 0))';

    /** Saldo awal periode: saldo akhir ditarik mundur oleh mutasi periode itu sendiri. */
    protected const OPENING = self::CLOSING.' - COALESCE(m.incoming, 0) + COALESCE(m.outgoing, 0)';

    public function paginate(StockReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->ordered($filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row) => StockReportRow::fromQuery($row, $filters->days()));
    }

    /**
     * Seluruh baris laporan tanpa halaman — untuk export.
     *
     * @return LazyCollection<int, StockReportRow>
     */
    public function lazy(StockReportFilters $filters): LazyCollection
    {
        return $this->ordered($filters)
            ->lazy(500)
            ->map(fn (object $row) => StockReportRow::fromQuery($row, $filters->days()));
    }

    /**
     * Ringkasan seluruh barang yang lolos saringan, dalam satu query.
     *
     * @return array<string, int|float|null>
     */
    public function summary(StockReportFilters $filters): array
    {
        $row = $this->base($filters)
            ->selectRaw('COUNT(*) as products')
            ->selectRaw('COALESCE(SUM(COALESCE(m.incoming, 0)), 0) as incoming')
            ->selectRaw('COALESCE(SUM(COALESCE(m.outgoing, 0)), 0) as outgoing')
            ->selectRaw('COALESCE(SUM('.self::CLOSING.'), 0) as closing')
            ->selectRaw('COALESCE(SUM('.self::OPENING.'), 0) as opening')
            ->selectRaw('COALESCE(SUM(p.damaged_stock), 0) as damaged')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(m.outgoing, 0) = 0 AND '.self::CLOSING.' > 0 THEN 1 ELSE 0 END), 0) as idle')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::CLOSING.' <= p.min_stock THEN 1 ELSE 0 END), 0) as low')
            ->first();

        $days = $filters->days();
        $outgoing = (int) ($row->outgoing ?? 0);
        $closing = (int) ($row->closing ?? 0);
        $opening = (int) ($row->opening ?? 0);

        $average = ($opening + $closing) / 2;
        $perDay = $outgoing / $days;

        return [
            'days' => $days,
            'products' => (int) ($row->products ?? 0),
            'opening' => $opening,
            'incoming' => (int) ($row->incoming ?? 0),
            'outgoing' => $outgoing,
            'closing' => $closing,
            'damaged' => (int) ($row->damaged ?? 0),
            'idle' => (int) ($row->idle ?? 0),
            'low' => (int) ($row->low ?? 0),
            'per_day' => $perDay,
            'turnover' => $average > 0 ? $outgoing / $average : null,
            'cover' => $perDay > 0 ? $closing / $perDay : null,
        ];
    }

    /**
     * Kategori yang benar-benar dipakai, untuk isi dropdown saringan.
     *
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return Product::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    /* --------------------------------------------------------- query ----- */

    /**
     * Daftar barang lengkap dengan angka periodenya, sudah diurutkan.
     */
    protected function ordered(StockReportFilters $filters): Builder
    {
        $query = $this->base($filters)
            ->leftJoinSub($this->lastOutgoing($filters), 'lo', 'lo.product_id', '=', 'p.id')
            ->select('p.id', 'p.sku', 'p.name', 'p.category', 'p.unit', 'p.min_stock', 'p.damaged_stock')
            ->selectRaw('COALESCE(m.incoming, 0) as incoming')
            ->selectRaw('COALESCE(m.outgoing, 0) as outgoing')
            ->selectRaw(self::CLOSING.' as closing')
            ->selectRaw(self::OPENING.' as opening')
            ->selectRaw('lo.last_out_at as last_out_at');

        return match ($filters->sort) {
            'masuk' => $query->orderByRaw('COALESCE(m.incoming, 0) DESC')->orderBy('p.name'),
            'stok' => $query->orderByRaw(self::CLOSING.' DESC')->orderBy('p.name'),
            'sisa' => $query
                // Barang yang tidak bergerak tidak punya perkiraan habis sama
                // sekali; ditaruh paling belakang, bukan dianggap paling awet.
                ->orderByRaw('CASE WHEN COALESCE(m.outgoing, 0) = 0 THEN 1 ELSE 0 END')
                ->orderByRaw(self::CLOSING.' * 1.0 / NULLIF(m.outgoing, 0)')
                ->orderBy('p.name'),
            'nama' => $query->orderBy('p.name'),
            default => $query->orderByRaw('COALESCE(m.outgoing, 0) DESC')->orderBy('p.name'),
        };
    }

    /**
     * Daftar barang beserta mutasi periodenya, tanpa select dan tanpa urutan,
     * supaya bisa dipakai baik untuk tabel maupun untuk agregat ringkasan.
     */
    protected function base(StockReportFilters $filters): Builder
    {
        return DB::table('products as p')
            ->leftJoinSub($this->movements($filters), 'm', 'm.product_id', '=', 'p.id')
            /*
                Paket bundling tidak punya saldo dan tidak pernah menghasilkan
                mutasi, jadi barisnya akan berisi nol di setiap kolom sepanjang
                periode apa pun. Yang ikut hanyalah barang yang benar-benar
                bergerak — mencampurnya hanya mengencerkan angka perputaran.
            */
            ->where('p.type', Product::TYPE_SINGLE)
            ->when($filters->search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $query) => $query
                    ->where('p.sku', 'like', "%{$term}%")
                    ->orWhere('p.name', 'like', "%{$term}%")
                    ->orWhere('p.barcode', 'like', "%{$term}%")
                    ->orWhere('p.category', 'like', "%{$term}%"),
            ))
            ->when($filters->category, fn (Builder $query, string $category) => $query->where('p.category', $category))
            ->when($filters->view === 'bergerak', fn (Builder $query) => $query->whereRaw('COALESCE(m.outgoing, 0) > 0'))
            ->when($filters->view === 'mati', fn (Builder $query) => $query
                ->whereRaw('COALESCE(m.outgoing, 0) = 0')
                ->whereRaw(self::CLOSING.' > 0'))
            ->when($filters->view === 'menipis', fn (Builder $query) => $query->whereRaw(self::CLOSING.' <= p.min_stock'));
    }

    /**
     * Rekap mutasi per barang: yang terjadi di dalam periode, dan yang terjadi
     * sesudahnya.
     *
     * Keduanya diambil sekaligus karena saldo akhir periode hanya bisa
     * diketahui dengan menarik mundur saldo hari ini — satu query yang
     * memisahkan dua rentang lebih murah daripada dua query terpisah.
     */
    protected function movements(StockReportFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN created_at <= ? AND type = 'in' THEN quantity ELSE 0 END) as incoming", [$filters->to])
            ->selectRaw("SUM(CASE WHEN created_at <= ? AND type = 'out' THEN quantity ELSE 0 END) as outgoing", [$filters->to])
            ->selectRaw("SUM(CASE WHEN created_at > ? AND type = 'in' THEN quantity ELSE 0 END) as in_after", [$filters->to])
            ->selectRaw("SUM(CASE WHEN created_at > ? AND type = 'out' THEN quantity ELSE 0 END) as out_after", [$filters->to])
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('created_at', '>=', $filters->from)
            ->groupBy('product_id');
    }

    /**
     * Kapan tiap barang terakhir keluar, dibatasi sampai akhir periode supaya
     * tidak menyebut tanggal yang belum terjadi pada laporan periode lampau.
     */
    protected function lastOutgoing(StockReportFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw('MAX(created_at) as last_out_at')
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('type', 'out')
            ->where('created_at', '<=', $filters->to)
            ->groupBy('product_id');
    }
}
