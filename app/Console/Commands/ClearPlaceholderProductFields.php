<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductImportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kosongkan kembali kolom yang berisi tanda hubung karena import berkas ekspor.
 *
 * Berkas hasil ekspor menulis "-" pada kolom yang memang tidak berisi apa-apa,
 * supaya laporannya enak dibaca. Importer dulu membacanya sebagai nilai
 * sungguhan, jadi mengunggah balik berkas itu — unduh, sunting beberapa baris,
 * unggah lagi — menuliskan tanda hubung itu ke basis data.
 *
 * Importer sekarang sudah memahami tandanya. Perintah ini untuk baris yang
 * telanjur berubah sebelum itu.
 *
 * Tanpa --apply perintah ini tidak menulis apa pun. Ini menyangkut data
 * produksi, dan mungkin saja ada barang yang kategorinya memang sengaja
 * ditulis begitu — keputusannya diambil setelah melihat, bukan sebelum.
 */
class ClearPlaceholderProductFields extends Command
{
    protected $signature = 'products:clear-placeholders
                            {--apply : Tulis perubahannya. Tanpa ini hanya ditampilkan.}';

    protected $description = 'Kosongkan kolom barang yang berisi tanda hubung akibat import berkas ekspor';

    /**
     * Hanya kolom yang boleh kosong di basis data. SKU, nama, dan satuan tidak
     * pernah disentuh.
     *
     * @var array<int, string>
     */
    protected const COLUMNS = ['barcode', 'category', 'location'];

    public function handle(): int
    {
        $affected = $this->affected();

        if ($affected->every(fn (Collection $rows) => $rows->isEmpty())) {
            $this->components->info('Tidak ada kolom yang berisi tanda hubung. Data Anda sudah bersih.');

            return self::SUCCESS;
        }

        $this->showFindings($affected);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('Belum ada yang diubah. Jalankan ulang dengan --apply bila daftar di atas sudah benar.');

            return self::SUCCESS;
        }

        $changed = 0;

        foreach (self::COLUMNS as $column) {
            $changed += $this->matching($column)->update([$column => null]);
        }

        $this->components->info("{$changed} kolom dikosongkan kembali.");

        return self::SUCCESS;
    }

    /**
     * Barang yang tersentuh, dikelompokkan per kolom.
     *
     * @return Collection<string, Collection<int, Product>>
     */
    protected function affected(): Collection
    {
        return collect(self::COLUMNS)
            ->mapWithKeys(fn (string $column) => [
                $column => $this->matching($column)->orderBy('sku')->get(['id', 'sku', 'name', $column]),
            ]);
    }

    /**
     * Baris yang isinya persis salah satu tanda "kosong" yang dikenali.
     *
     * Dicocokkan utuh, bukan sebagai penggalan: kategori "Filter - Oli" jelas
     * ditulis orang dan tidak boleh ikut terhapus.
     */
    protected function matching(string $column): Builder
    {
        // Daftarnya dimiliki importer supaya keduanya tidak bisa berbeda
        // pendapat tentang apa yang dianggap "kosong".
        return Product::query()->whereIn($column, ProductImportService::PLACEHOLDERS);
    }

    /**
     * @param  Collection<string, Collection<int, Product>>  $affected
     */
    protected function showFindings(Collection $affected): void
    {
        foreach ($affected as $column => $rows) {
            if ($rows->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line("Kolom <fg=yellow>{$column}</> berisi tanda hubung pada {$rows->count()} barang:");

            $this->table(
                ['SKU', 'Nama', 'Isi sekarang'],
                $rows->take(15)->map(fn (Product $product) => [
                    $product->sku,
                    $product->name,
                    $product->{$column},
                ]),
            );

            if ($rows->count() > 15) {
                $this->line('  … dan '.($rows->count() - 15).' barang lainnya.');
            }
        }
    }
}
