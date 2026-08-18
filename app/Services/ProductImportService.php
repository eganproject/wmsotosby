<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import master barang beserta stoknya dari berkas Excel.
 *
 * SKU adalah kuncinya: baris dengan SKU yang sudah ada memperbarui barang
 * tersebut, sisanya dibuat baru. Kolom stok bersifat opsional — bila diisi,
 * selisihnya dicatat sebagai pergerakan stok lewat StockService.
 */
class ProductImportService
{
    /**
     * Alias nama kolom, dibandingkan dalam huruf kecil tanpa spasi.
     *
     * @var array<string, array<int, string>>
     */
    protected array $aliases = [
        'sku' => ['sku', 'kodesku', 'kodebarang', 'kode', 'skubarang', 'itemcode', 'productcode'],
        'name' => ['namabarang', 'nama', 'namaproduk', 'productname', 'itemname', 'deskripsi'],
        'barcode' => ['barcode', 'kodebarcode', 'ean', 'upc', 'barcodebarang'],
        'category' => ['kategori', 'category', 'jenis', 'kelompok', 'grup'],
        'unit' => ['satuan', 'unit', 'uom', 'satuanbarang'],
        'location' => ['lokasirak', 'lokasi', 'rak', 'location', 'binlocation', 'shelf'],
        'min_stock' => ['stokminimum', 'stokmin', 'minstock', 'minimumstock', 'batasminimum', 'minimal'],
        'stock' => ['stok', 'stock', 'stokawal', 'jumlahstok', 'qty', 'quantity', 'saldostok', 'stokakhir'],
        'is_active' => ['status', 'statusbarang', 'aktif', 'isactive'],
    ];

    /**
     * Kolom yang wajib ada pada berkas. Dipakai bersama oleh pemeriksaan
     * import dan pewarnaan judul kolom pada template.
     *
     * @var array<int, string>
     */
    public const REQUIRED_COLUMNS = ['sku', 'name'];

    /**
     * Judul kolom pada berkas template.
     *
     * @var array<string, string>
     */
    public const TEMPLATE_COLUMNS = [
        'sku' => 'SKU',
        'name' => 'Nama Barang',
        'barcode' => 'Barcode',
        'category' => 'Kategori',
        'unit' => 'Satuan',
        'location' => 'Lokasi Rak',
        'min_stock' => 'Stok Minimum',
        'stock' => 'Stok',
    ];

    public function __construct(protected StockService $stock)
    {
    }

    public function import(UploadedFile $file): ProductImport
    {
        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak berisi data apa pun.',
            ]);
        }

        [$header, $headerIndex] = $this->locateHeader($rows);

        foreach (self::REQUIRED_COLUMNS as $field) {
            if (! isset($header[$field])) {
                $label = self::TEMPLATE_COLUMNS[$field];

                throw ValidationException::withMessages([
                    'file' => "Kolom {$label} tidak ditemukan pada berkas. Unduh template untuk melihat susunan kolom yang benar.",
                ]);
            }
        }

        $rows = $rows->slice($headerIndex + 1)->values();

        $entries = $this->collectRows($rows, $header);

        if ($entries->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Tidak ada baris dengan SKU dan nama barang yang bisa diproses.',
            ]);
        }

        return $this->persist($file, $header, $rows->count(), $entries);
    }

    /* ------------------------------------------------------ membaca ------ */

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readRows(UploadedFile $file): Collection
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv' || $extension === 'txt') {
            return $this->readCsv($file);
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak bisa dibaca. Pastikan formatnya .xlsx, .xls, atau .csv.',
            ]);
        }

        return $this->clean(collect($sheet->toArray(null, true, false, false)));
    }

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readCsv(UploadedFile $file): Collection
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file->getRealPath()));

        $firstLine = strtok($contents, "\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        $rows = collect();
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, array_key_first($counts), '"', '\\')) !== false) {
            $rows->push($row);
        }

        fclose($handle);

        return $this->clean($rows);
    }

    /**
     * @param  Collection<int, array<int, mixed>>  $rows
     * @return Collection<int, array<int, string>>
     */
    protected function clean(Collection $rows): Collection
    {
        return $rows
            ->map(fn (array $row) => array_map(fn ($value) => trim((string) $value), $row))
            ->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())
            ->values();
    }

    /* ------------------------------------------------------ pemetaan ----- */

    /**
     * @param  Collection<int, array<int, string>>  $rows
     * @return array{0: array<string, int>, 1: int}
     */
    protected function locateHeader(Collection $rows): array
    {
        $best = [[], 0];
        $bestScore = -1;

        foreach ($rows->take(10) as $index => $row) {
            $header = $this->mapHeader($row);

            if (! isset($header['sku'], $header['name'])) {
                continue;
            }

            if (count($header) > $bestScore) {
                $bestScore = count($header);
                $best = [$header, $index];
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    protected function mapHeader(array $headerRow): array
    {
        $map = [];
        $used = [];

        foreach ($headerRow as $index => $title) {
            $key = $this->slug($title);

            if ($key === '') {
                continue;
            }

            foreach ($this->aliases as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }

                if (in_array($key, $aliases, true)) {
                    $map[$field] = $index;
                    $used[$index] = true;
                    continue 2;
                }
            }
        }

        // Kolom yang sudah terpakai tidak boleh dipakai ulang oleh field lain.
        foreach ($headerRow as $index => $title) {
            if (isset($used[$index])) {
                continue;
            }

            $key = $this->slug($title);

            if ($key === '') {
                continue;
            }

            foreach ($this->aliases as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }

                foreach ($aliases as $alias) {
                    if (strlen($alias) >= 4 && str_contains($key, $alias)) {
                        $map[$field] = $index;
                        $used[$index] = true;
                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    protected function slug(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $header
     */
    protected function value(array $row, array $header, string $field): ?string
    {
        if (! isset($header[$field])) {
            return null;
        }

        $value = $row[$header[$field]] ?? null;

        return blank($value) ? null : trim((string) $value);
    }

    /**
     * Tanda yang dipakai berkas kita sendiri untuk menyatakan "kosong".
     *
     * @var array<int, string>
     */
    public const PLACEHOLDERS = ['-', '–', '—', 'n/a', 'na', '(kosong)'];

    /**
     * Sama seperti value(), tetapi tanda hubung tunggal dibaca sebagai kosong.
     *
     * Berkas hasil export kita menulis "-" pada kolom yang memang tidak
     * berisi apa-apa, supaya laporannya enak dibaca. Tanpa pemahaman ini,
     * mengimport balik berkas itu — alur paling lazim: unduh, sunting
     * beberapa baris, unggah lagi — menuliskan tanda hubung itu sebagai
     * nilai sungguhan. Setiap barang yang kategorinya kosong berubah menjadi
     * berkategori "-", dan barang tanpa barcode mendapat barcode "-" yang
     * lalu bentrok dengan barang berikutnya yang juga tanpa barcode.
     *
     * Hanya berlaku untuk kolom yang boleh kosong di basis data. SKU, nama,
     * dan satuan tidak pernah melewati sini, jadi barang yang memang bernama
     * "-" tetap terbaca apa adanya.
     *
     * @param  array<int, string>  $row
     * @param  array<string, int>  $header
     */
    protected function optional(array $row, array $header, string $field): ?string
    {
        $value = $this->value($row, $header, $field);

        return in_array(mb_strtolower((string) $value), self::PLACEHOLDERS, true) ? null : $value;
    }

    /* --------------------------------------------------- pengumpulan ----- */

    /**
     * Baris dikumpulkan per SKU; SKU yang muncul dua kali memakai baris terakhir.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @param  array<string, int>  $header
     * @return Collection<string, array<string, mixed>>
     */
    protected function collectRows(Collection $rows, array $header): Collection
    {
        $entries = collect();

        foreach ($rows as $row) {
            $sku = $this->value($row, $header, 'sku');
            $name = $this->value($row, $header, 'name');

            if (blank($sku) || blank($name)) {
                continue;
            }

            $entries->put(Str::upper(trim($sku)), [
                'sku' => Str::upper(trim($sku)),
                'name' => $name,
                'barcode' => $this->optional($row, $header, 'barcode'),
                'category' => $this->optional($row, $header, 'category'),
                'unit' => $this->value($row, $header, 'unit') ?: 'pcs',
                'location' => $this->optional($row, $header, 'location'),
                // Kolom ini tidak boleh null di database, jadi kosong berarti 0.
                'min_stock' => $this->number($this->value($row, $header, 'min_stock')) ?? 0,
                'stock' => isset($header['stock'])
                    ? $this->number($this->value($row, $header, 'stock'))
                    : null,
                'is_active' => $this->boolean($this->value($row, $header, 'is_active')),
            ]);
        }

        return $entries;
    }

    protected function number(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) round((float) str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', $value)));
    }

    protected function boolean(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        return ! in_array($this->slug($value), ['nonaktif', 'tidakaktif', 'inactive', 'no', 'tidak', '0', 'false'], true);
    }

    /* ------------------------------------------------------ penyimpanan -- */

    /**
     * @param  array<string, int>  $header
     * @param  Collection<string, array<string, mixed>>  $entries
     */
    protected function persist(UploadedFile $file, array $header, int $rowCount, Collection $entries): ProductImport
    {
        $this->guardDuplicateBarcodes($entries);

        // Seluruh berkas diproses sebagai satu kesatuan: bila satu baris gagal,
        // tidak ada barang yang setengah tersimpan.
        return DB::transaction(function () use ($file, $header, $rowCount, $entries) {
            $import = ProductImport::create([
                'filename' => $file->getClientOriginalName(),
                'row_count' => $rowCount,
                'detected_columns' => array_keys($header),
                'user_id' => auth()->id(),
            ]);

            $created = 0;
            $updated = 0;
            $adjusted = 0;
            $bundlesSkipped = 0;

            foreach ($entries as $entry) {
                $stock = $entry['stock'];
                unset($entry['stock']);

                $product = Product::where('sku', $entry['sku'])->first();

                if ($product) {
                    /*
                        Kolom yang hanya masuk akal bagi barang berwujud tidak
                        pernah ditulis ke paket bundling.

                        Barcode yang paling penting: paket tidak punya wujud
                        yang bisa ditempeli label, dan satu kode yang menempel
                        padanya membuat scan di stasiun packing menunjuk baris
                        yang tidak pernah ada di dokumen. Lokasi rak dan batas
                        menipis sama saja — keduanya setelan atas saldo, dan
                        paket tidak punya saldo.

                        Barang baru selalu lahir sebagai barang biasa, jadi
                        pemeriksaannya cukup di jalur pembaruan.
                    */
                    $product->update($product->isBundle()
                        ? collect($entry)->except(['barcode', 'location', 'min_stock'])->all()
                        : $entry);

                    $updated++;
                } else {
                    $product = Product::create($entry);
                    $created++;
                }

                /*
                    Paket bundling tidak punya saldo yang bisa disetel.

                    Tanpa penjagaan ini, satu angka stok pada baris paket akan
                    tertahan di StockService dan — karena seluruh berkas
                    diproses sebagai satu transaksi — menggagalkan import
                    seluruhnya, termasuk ratusan baris lain yang tidak
                    bermasalah. Barisnya tetap diperbarui seperti biasa; hanya
                    kolom stoknya yang dilewati, dan itu dilaporkan.

                    Ikut dilaporkan pula saat berkasnya berasal dari export
                    kita sendiri: di sana kolom stok paket berisi ketersediaan
                    turunannya — angka yang tampak seperti bisa disetel padahal
                    tidak. Justru itu yang paling perlu disebut.
                */
                if ($stock !== null && $product->isBundle()) {
                    $bundlesSkipped++;

                    continue;
                }

                // Stok tidak pernah ditulis langsung; selisihnya jadi pergerakan stok.
                if ($stock !== null && $this->stock->setStock($product, $stock, $import, "Import stok dari {$import->filename}")) {
                    $adjusted++;
                }
            }

            $import->update([
                'created_count' => $created,
                'updated_count' => $updated,
                'stock_adjusted_count' => $adjusted,
                'bundle_skipped_count' => $bundlesSkipped,
            ]);

            return $import->refresh();
        });
    }

    /**
     * Barcode wajib unik antar barang, jadi bentrokan dihentikan sebelum
     * sebagian baris terlanjur tersimpan.
     *
     * @param  Collection<string, array<string, mixed>>  $entries
     */
    protected function guardDuplicateBarcodes(Collection $entries): void
    {
        $barcodes = $entries->pluck('barcode')->filter();

        $duplicated = $barcodes->duplicates();

        if ($duplicated->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Barcode berikut muncul lebih dari sekali pada berkas: '.$duplicated->implode(', ').'.',
            ]);
        }

        $taken = Product::whereIn('barcode', $barcodes)
            ->whereNotIn('sku', $entries->pluck('sku'))
            ->pluck('barcode');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Barcode berikut sudah dipakai barang lain: '.$taken->implode(', ').'.',
            ]);
        }
    }
}
