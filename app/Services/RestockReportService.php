<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\RestockFilters;
use App\Support\RestockRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Laporan kebutuhan restock: barang apa yang perlu dipesan, dan berapa.
 *
 * Tiga hal yang biasanya dilihat terpisah disatukan di sini — saldo di rak,
 * unit yang sudah terlanjur dijanjikan ke pembeli, dan laju keluarnya. Yang
 * ketiga membuat batas menipis tidak lagi menjadi satu-satunya alasan memesan:
 * barang laris bisa saja masih di atas batas hari ini dan tetap habis pekan
 * depan.
 *
 * Barang nonaktif tidak ikut dihitung — ia memang tidak dipesan lagi.
 */
class RestockReportService
{
    public function paginate(RestockFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->ordered($filters)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row) => RestockRow::fromQuery($row, $filters->days(), $filters->coverDays));
    }

    /**
     * @return LazyCollection<int, RestockRow>
     */
    public function lazy(RestockFilters $filters): LazyCollection
    {
        return $this->ordered($filters)
            ->lazy(500)
            ->map(fn (object $row) => RestockRow::fromQuery($row, $filters->days(), $filters->coverDays));
    }

    /**
     * Ringkasan seluruh barang yang lolos pencarian dan kategori.
     *
     * Sudut pandang yang sedang dibuka sengaja tidak ikut membatasi: angka di
     * kartu adalah gambaran keseluruhan, dan berpindah tab tidak seharusnya
     * mengubah gambaran itu.
     *
     * @return array<string, int>
     */
    public function summary(RestockFilters $filters): array
    {
        $sql = $this->expressions($filters);

        $row = $this->base($filters, applyView: false)
            ->selectRaw('COUNT(*) as products')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$sql['suggested']} > 0 THEN 1 ELSE 0 END), 0) as needing")
            ->selectRaw("COALESCE(SUM({$sql['suggested']}), 0) as units")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$sql['available']} <= 0 THEN 1 ELSE 0 END), 0) as empty_shelf")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$sql['available']} <= p.min_stock THEN 1 ELSE 0 END), 0) as thin")
            ->selectRaw('COALESCE(SUM(COALESCE(c.committed, 0)), 0) as committed')
            ->first();

        return [
            'products' => (int) ($row->products ?? 0),
            'needing' => (int) ($row->needing ?? 0),
            'units' => (int) ($row->units ?? 0),
            'empty' => (int) ($row->empty_shelf ?? 0),
            'thin' => (int) ($row->thin ?? 0),
            'committed' => (int) ($row->committed ?? 0),
        ];
    }

    /**
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

    protected function ordered(RestockFilters $filters): Builder
    {
        $sql = $this->expressions($filters);
        $days = $filters->days();

        $query = $this->base($filters)
            ->select('p.id', 'p.sku', 'p.name', 'p.category', 'p.location', 'p.unit', 'p.stock', 'p.damaged_stock', 'p.min_stock')
            ->selectRaw('COALESCE(m.outgoing, 0) as outgoing')
            ->selectRaw('COALESCE(c.committed, 0) as committed');

        return match ($filters->sort) {
            'jumlah' => $query->orderByRaw("{$sql['suggested']} DESC")->orderBy('p.name'),
            'laku' => $query->orderByRaw('COALESCE(m.outgoing, 0) DESC')->orderBy('p.name'),
            'nama' => $query->orderBy('p.name'),

            // Paling mendesak: yang rak-nya sudah kosong lebih dulu, lalu yang
            // paling cepat menyusul habis. Yang tidak bergerak sama sekali tidak
            // punya perkiraan habis, jadi ditaruh paling belakang.
            default => $query
                ->orderByRaw("CASE WHEN {$sql['available']} <= 0 THEN 0 WHEN COALESCE(m.outgoing, 0) > 0 THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN COALESCE(m.outgoing, 0) > 0
                    THEN {$sql['available']} * 1.0 * {$days} / COALESCE(m.outgoing, 0) ELSE NULL END")
                ->orderBy('p.name'),
        };
    }

    protected function base(RestockFilters $filters, bool $applyView = true): Builder
    {
        $sql = $this->expressions($filters);

        return DB::table('products as p')
            ->leftJoinSub($this->movements($filters), 'm', 'm.product_id', '=', 'p.id')
            ->leftJoinSub($this->committed(), 'c', 'c.product_id', '=', 'p.id')
            ->where('p.is_active', true)
            ->when($filters->search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $query) => $query
                    ->where('p.sku', 'like', "%{$term}%")
                    ->orWhere('p.name', 'like', "%{$term}%")
                    ->orWhere('p.barcode', 'like', "%{$term}%")
                    ->orWhere('p.category', 'like', "%{$term}%"),
            ))
            ->when($filters->category, fn (Builder $query, string $category) => $query->where('p.category', $category))
            ->when($applyView && $filters->view === 'perlu', fn (Builder $query) => $query->whereRaw("{$sql['suggested']} > 0"))
            ->when($applyView && $filters->view === 'menipis', fn (Builder $query) => $query->whereRaw("{$sql['available']} <= p.min_stock"));
    }

    /**
     * Potongan SQL yang dipakai berulang oleh saringan, urutan, dan ringkasan.
     *
     * Angka periode dan hari cakupan disisipkan langsung, bukan sebagai binding:
     * keduanya sudah berupa integer yang dibatasi RestockFilters, dan ekspresi
     * ini muncul belasan kali dalam satu query — urutan binding yang harus
     * dijaga sendiri di antara select, where, dan order justru lebih mudah salah
     * daripada penyisipan yang jelas-jelas aman.
     *
     * @return array<string, string>
     */
    protected function expressions(RestockFilters $filters): array
    {
        $days = $filters->days();
        $cover = $filters->coverDays;

        // Saldo yang benar-benar bebas: yang sudah masuk dokumen keluar tetapi
        // belum diproses masih ada di rak, tetapi sudah dijanjikan.
        $available = '(p.stock - COALESCE(c.committed, 0))';

        // Kebutuhan sepanjang masa cakupan, dalam pecahan hari.
        $demand = "(COALESCE(m.outgoing, 0) * {$cover})";

        /*
            Pembulatan ke atas ditulis dengan sisa bagi, bukan CEIL.

            SQLite bawaan PHP dikompilasi tanpa fungsi matematika sama sekali —
            CEIL, FLOOR, maupun GREATEST tidak ada di sana, sedangkan MySQL
            punya ketiganya. Bentuk (a - a % b) / b selalu habis dibagi, jadi
            hasilnya tepat di kedua basis data.
        */
        $forecast = "(({$demand} - ({$demand} % {$days})) / {$days}"
            ." + CASE WHEN ({$demand} % {$days}) > 0 THEN 1 ELSE 0 END)";

        $shortfall = "(CASE WHEN p.min_stock > {$available} THEN p.min_stock - {$available} ELSE 0 END)";
        $need = "(CASE WHEN {$forecast} > {$available} THEN {$forecast} - {$available} ELSE 0 END)";

        // Yang terbesar di antara keduanya: batas menipis menjaga barang jarang
        // bergerak tetap ada, ramalan laju menjaga barang laris tidak kehabisan.
        $suggested = "(CASE WHEN {$shortfall} > {$need} THEN {$shortfall} ELSE {$need} END)";

        return compact('available', 'forecast', 'shortfall', 'need', 'suggested');
    }

    /**
     * Unit yang keluar pada periode pengamatan, hanya saldo layak jual.
     */
    protected function movements(RestockFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw('SUM(quantity) as outgoing')
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('type', 'out')
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->groupBy('product_id');
    }

    /**
     * Unit yang sudah masuk dokumen barang keluar tetapi belum diproses.
     *
     * Saldonya masih utuh di gudang, tetapi sudah menjadi milik pembeli.
     * Menghitung kebutuhan tanpa mengeluarkannya berarti memesan terlambat.
     */
    protected function committed(): Builder
    {
        return DB::table('outbound_items as oi')
            ->join('outbounds as o', 'o.id', '=', 'oi.outbound_id')
            ->select('oi.product_id')
            ->selectRaw('SUM(oi.quantity) as committed')
            ->whereIn('o.status', [Outbound::STATUS_DRAFT, Outbound::STATUS_PENDING])
            ->groupBy('oi.product_id');
    }
}
