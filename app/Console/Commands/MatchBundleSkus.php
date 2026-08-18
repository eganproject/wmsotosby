<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ShipmentSkuMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Cocokkan baris pesanan lama ke barang yang baru didaftarkan.
 *
 * Hal yang sama bisa dikerjakan dari halaman Import Resi lewat tombol
 * "Cocokkan Ulang", dan itulah jalan yang lebih lazim. Perintah ini untuk
 * pengerjaan borongan setelah banyak barang didaftarkan sekaligus, dan
 * keduanya memakai service yang sama supaya tidak bisa berbeda hasil.
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

    public function handle(ShipmentSkuMatcher $matcher): int
    {
        $bundlesOnly = ! $this->option('all');

        $unmatched = $matcher->unmatchedSkus();

        if ($unmatched->isEmpty()) {
            $this->components->info('Tidak ada baris pesanan yang SKU-nya belum tercocokkan. Data Anda sudah sesuai.');

            return self::SUCCESS;
        }

        $resolvable = $matcher->resolvable($unmatched, $bundlesOnly);
        $ambiguous = $matcher->ambiguous($unmatched);

        $this->showFindings($unmatched, $resolvable, $ambiguous, $bundlesOnly);

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

        $result = $matcher->match(bundlesOnly: $bundlesOnly);

        $this->components->info(
            "{$result['rows']} baris pesanan dicocokkan ke {$result['skus']} barang. "
            .'Resinya kini bisa dibuka di stasiun packing.',
        );

        if ($result['remaining']->isNotEmpty()) {
            $this->components->warn(
                'Masih tersisa '.$result['remaining']->count().' SKU yang belum terdaftar: '
                .$result['remaining']->keys()->take(10)->implode(', ').'.',
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, int>  $unmatched
     * @param  Collection<string, Product>  $resolvable
     * @param  Collection<string, Collection<int, Product>>  $ambiguous
     */
    protected function showFindings(
        Collection $unmatched,
        Collection $resolvable,
        Collection $ambiguous,
        bool $bundlesOnly,
    ): void {
        $this->newLine();
        $this->line('SKU pada resi yang belum tercocokkan ke master barang:');

        $this->table(
            ['SKU', 'Baris', 'Ditemukan sebagai'],
            $unmatched->map(fn (int $count, string $sku) => [
                $sku,
                $count,
                match (true) {
                    $resolvable->has($sku) => $resolvable[$sku]->name.' ('.($resolvable[$sku]->isBundle() ? 'paket' : 'barang').')',
                    $ambiguous->has($sku) => 'RANCU — ada '.$ambiguous[$sku]->count().' barang dengan SKU yang sama',
                    default => '—  belum ada di master barang',
                },
            ])->values(),
        );

        $rows = $unmatched->only($resolvable->keys())->sum();

        $this->newLine();
        $this->line("Akan dicocokkan: <fg=yellow>{$rows}</> baris pesanan pada <fg=yellow>{$resolvable->count()}</> SKU.");

        if ($bundlesOnly) {
            $this->line('  Hanya SKU yang terdaftar sebagai paket bundling. Pakai --all untuk ikut mencocokkan barang biasa.');
        }

        if ($ambiguous->isNotEmpty()) {
            $this->line('  <fg=red>'.$ambiguous->count().' SKU dilewati karena rancu</> — ada lebih dari satu barang');
            $this->line('  dengan SKU yang sama bila huruf besar dan spasi diabaikan. Samakan salah satunya dulu.');
        }
    }
}
