<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ShipmentOrderItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cocokkan baris pesanan lama ke barang yang baru didaftarkan.
 *
 * Pencocokan SKU hanya terjadi sekali, saat berkasnya diimport. Resi yang
 * masuk sebelum paket bundlingnya didaftarkan karena itu tetap menggantung
 * dengan product_id kosong, dan di halaman Status Resi terbaca "SKU belum
 * lengkap di master barang" — padahal barangnya sekarang sudah ada.
 *
 * Mengimport ulang berkas Ginee-nya menyelesaikan hal yang sama. Perintah ini
 * untuk saat berkasnya sudah tidak ada, atau saat yang perlu diperbaiki hanya
 * beberapa resi lama.
 *
 * Tanpa --apply perintah ini tidak menulis apa pun. Ini menyangkut data
 * produksi, dan mencocokkan baris pesanan berarti paketnya menjadi bisa
 * dipacking — keputusan seperti itu diambil setelah melihat, bukan sebelum.
 */
class MatchBundleSkus extends Command
{
    protected $signature = 'bundles:match-skus
                            {--apply : Tulis perubahannya. Tanpa ini hanya ditampilkan.}
                            {--all : Ikut cocokkan SKU barang biasa, bukan hanya paket.}';

    protected $description = 'Cocokkan SKU pada resi lama ke master barang yang baru didaftarkan';

    public function handle(): int
    {
        $unmatched = $this->unmatchedSkus();

        if ($unmatched->isEmpty()) {
            $this->components->info('Tidak ada baris pesanan yang SKU-nya belum tercocokkan. Data Anda sudah sesuai.');

            return self::SUCCESS;
        }

        $resolvable = $this->resolvable($unmatched);

        $this->showFindings($unmatched, $resolvable);

        if ($resolvable->isEmpty()) {
            $this->newLine();
            $this->components->warn(
                'Tidak ada yang bisa dicocokkan. Daftarkan dulu barangnya di master barang '
                .'dengan SKU yang persis sama seperti pada tabel di atas.',
            );

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('Belum ada yang diubah. Jalankan ulang dengan --apply bila daftar di atas sudah benar.');

            return self::SUCCESS;
        }

        $rows = 0;

        foreach ($resolvable as $sku => $product) {
            $rows += ShipmentOrderItem::query()
                ->whereNull('product_id')
                ->whereRaw('UPPER(TRIM(sku)) = ?', [$sku])
                ->update(['product_id' => $product->id]);
        }

        $this->components->info(
            "{$rows} baris pesanan dicocokkan ke ".$resolvable->count().' barang. '
            .'Resinya kini bisa dibuka di stasiun packing.',
        );

        return self::SUCCESS;
    }

    /**
     * SKU yang muncul pada baris pesanan tetapi belum menunjuk barang mana pun,
     * beserta berapa baris yang memakainya.
     *
     * @return Collection<string, int>
     */
    protected function unmatchedSkus(): Collection
    {
        return ShipmentOrderItem::query()
            ->whereNull('product_id')
            ->get(['sku'])
            ->groupBy(fn (ShipmentOrderItem $item) => strtoupper(trim($item->sku)))
            ->map(fn (Collection $group) => $group->count())
            ->sortDesc();
    }

    /**
     * Di antara SKU itu, mana yang kini sudah ada di master barang.
     *
     * Bawaannya hanya paket bundling — itulah yang fitur ini perkenalkan, dan
     * mempersempitnya membuat perintah ini tidak diam-diam ikut memperbaiki
     * hal lain yang mungkin sengaja dibiarkan. --all melonggarkannya.
     *
     * @param  Collection<string, int>  $unmatched
     * @return Collection<string, Product>
     */
    protected function resolvable(Collection $unmatched): Collection
    {
        return Product::query()
            ->when(! $this->option('all'), fn (Builder $query) => $query->bundles())
            ->get(['id', 'sku', 'name', 'type'])
            ->keyBy(fn (Product $product) => strtoupper(trim($product->sku)))
            ->intersectByKeys($unmatched);
    }

    /**
     * @param  Collection<string, int>  $unmatched
     * @param  Collection<string, Product>  $resolvable
     */
    protected function showFindings(Collection $unmatched, Collection $resolvable): void
    {
        $this->newLine();
        $this->line('SKU pada resi yang belum tercocokkan ke master barang:');

        $this->table(
            ['SKU', 'Baris', 'Ditemukan sebagai'],
            $unmatched->map(fn (int $count, string $sku) => [
                $sku,
                $count,
                match (true) {
                    $resolvable->has($sku) => $resolvable[$sku]->name.' ('.($resolvable[$sku]->isBundle() ? 'paket' : 'barang').')',
                    default => '—  belum ada di master barang',
                },
            ])->values(),
        );

        $rows = $unmatched->only($resolvable->keys())->sum();

        $this->newLine();
        $this->line("Akan dicocokkan: <fg=yellow>{$rows}</> baris pesanan pada <fg=yellow>{$resolvable->count()}</> SKU.");

        if (! $this->option('all')) {
            $this->line('  Hanya SKU yang terdaftar sebagai paket bundling. Pakai --all untuk ikut mencocokkan barang biasa.');
        }
    }
}
