<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Membaca berkas eksport pesanan Ginee (xlsx / csv) menjadi data resi.
 *
 * Nama kolom Ginee berbeda-beda antar versi dan bahasa, jadi kolom dicari
 * lewat daftar alias, bukan lewat posisi tetap.
 */
class GineeImportService
{
    /**
     * Alias nama kolom, semuanya dibandingkan dalam huruf kecil tanpa spasi.
     *
     * @var array<string, array<int, string>>
     */
    protected array $aliases = [
        'tracking_number' => [
            'nomorresi', 'noresi', 'resi', 'trackingnumber', 'trackingno', 'awb', 'awbnumber',
            'nomorawb', 'nomorresipengiriman', 'shippingtrackingnumber',
            // Eksport Ginee memakai judul gabungan "AWB/No. Tracking".
            'awbnotracking', 'awbnotrackingnumber', 'nomortracking', 'notracking', 'awbtracking',
        ],
        'order_number' => [
            'nomorpesanan', 'nopesanan', 'ordernumber', 'orderno', 'orderid', 'nomororder',
            'kodepesanan', 'channelordernumber', 'nomorpesanantoko',
            'idpesanan', 'idorder', 'idpesananchannel',
        ],
        'marketplace' => [
            'channel', 'saluran', 'marketplace', 'platform', 'namachannel', 'channelname',
        ],
        'store_name' => [
            'toko', 'namatoko', 'store', 'storename', 'shopname', 'namashop',
        ],
        'buyer_name' => [
            'pembeli', 'namapembeli', 'buyer', 'buyername', 'customer', 'customername', 'penerima',
            'namapenerima',
        ],
        'buyer_note' => [
            'catatanpembeli', 'catatan', 'buyernote', 'buyerremark', 'notepembeli', 'pesanpembeli',
            'catatanpesanan', 'remark',
        ],
        'order_status' => [
            'statuspesanan', 'status', 'orderstatus', 'statusorder',
        ],
        'courier' => [
            'kurir', 'jasakirim', 'courier', 'shippingprovider', 'logistik', 'ekspedisi',
            'namakurir', 'jasapengiriman', 'logisticprovider',
        ],
        'shipping_method' => [
            'metodepengiriman', 'shippingmethod', 'metodekirim', 'opsipengiriman', 'tipepengiriman',
            'layananpengiriman',
        ],
        'order_date' => [
            'tanggalpembuatan', 'tanggalpesanan', 'tanggalorder', 'orderdate', 'createdtime',
            'tanggal', 'waktupesanan', 'ordercreatedtime', 'paidtime', 'waktupembuatan',
        ],
        'sku' => [
            'sku', 'skuinduk', 'nomorsku', 'kodesku', 'sellersku', 'skupenjual', 'skuproduk',
            'productsku', 'variantsku', 'skuvariasi', 'mastersku',
        ],
        'product_name' => [
            'namaproduk', 'produk', 'productname', 'product', 'namabarang', 'itemname', 'namavariasi',
        ],
        'quantity' => [
            'jumlah', 'qty', 'quantity', 'kuantitas', 'jumlahproduk', 'itemquantity', 'jumlahbarang',
        ],
    ];

    /**
     * Proses berkas dan simpan hasilnya.
     */
    public function import(UploadedFile $file): ShipmentImport
    {
        $rows = $this->readRows($file);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak berisi data apa pun.',
            ]);
        }

        [$header, $headerIndex] = $this->locateHeader($rows);

        foreach (['tracking_number', 'sku'] as $required) {
            if (! isset($header[$required])) {
                throw ValidationException::withMessages([
                    'file' => $required === 'sku'
                        ? 'Kolom SKU tidak ditemukan pada berkas. Pastikan eksport Ginee menyertakan kolom SKU.'
                        : 'Kolom nomor resi tidak ditemukan pada berkas. Pastikan eksport Ginee menyertakan kolom Nomor Resi.',
                ]);
            }
        }

        // Baris judul atau keterangan di atas header ikut dibuang.
        $rows = $rows->slice($headerIndex + 1)->values();

        $orders = $this->groupRows($rows, $header);

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'Tidak ada baris dengan nomor resi dan SKU yang bisa diproses.',
            ]);
        }

        return $this->persist($file, $header, $rows->count(), $orders);
    }

    /* ------------------------------------------------------ membaca ------ */

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readRows(UploadedFile $file): Collection
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $extension === 'csv' || $extension === 'txt'
            ? $this->readCsv($file)
            : $this->readSpreadsheet($file);
    }

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readCsv(UploadedFile $file): Collection
    {
        $contents = file_get_contents($file->getRealPath());

        // Buang BOM UTF-8 agar judul kolom pertama tetap terbaca.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        $delimiter = $this->detectDelimiter($contents);

        $rows = collect();
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows->push(array_map(fn ($value) => trim((string) $value), $row));
        }

        fclose($handle);

        return $rows->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())->values();
    }

    protected function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @return Collection<int, array<int, string>>
     */
    protected function readSpreadsheet(UploadedFile $file): Collection
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tidak bisa dibaca. Pastikan formatnya .xlsx, .xls, atau .csv dari eksport Ginee.',
            ]);
        }

        return collect($sheet->toArray(null, true, false, false))
            ->map(fn (array $row) => array_map(fn ($value) => trim((string) $value), $row))
            ->filter(fn (array $row) => collect($row)->filter(fn ($v) => $v !== '')->isNotEmpty())
            ->values();
    }

    /* ------------------------------------------------------ pemetaan ----- */

    /**
     * Cari baris mana yang menjadi judul kolom.
     *
     * Eksport Ginee kadang diawali baris judul laporan atau keterangan
     * periode, jadi baris pertama tidak selalu berisi nama kolom. Beberapa
     * baris awal dicoba, lalu yang paling banyak dikenali dipakai.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @return array{0: array<string, int>, 1: int}
     */
    protected function locateHeader(Collection $rows): array
    {
        $best = [[], 0];
        $bestScore = -1;

        foreach ($rows->take(10) as $index => $row) {
            $header = $this->mapHeader($row);

            // Baris tanpa resi atau tanpa SKU jelas bukan judul kolom.
            if (! isset($header['tracking_number'], $header['sku'])) {
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
     * Cocokkan judul kolom ke field yang kita butuhkan.
     *
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    protected function mapHeader(array $headerRow): array
    {
        $map = [];
        $usedColumns = [];

        // Putaran pertama: nama kolom yang persis sama.
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
                    $usedColumns[$index] = true;
                    continue 2;
                }
            }
        }

        // Putaran kedua: cocokkan sebagian untuk judul seperti "Nomor Resi (AWB)".
        // Kolom yang sudah terpakai dilewati, supaya "Catatan Pembeli" tidak
        // ikut terbaca sebagai nama pembeli hanya karena mengandung "pembeli".
        foreach ($headerRow as $index => $title) {
            if (isset($usedColumns[$index])) {
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
                        $usedColumns[$index] = true;
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

    /* ------------------------------------------------------ pengelompokan */

    /**
     * Satu pesanan bisa terdiri dari beberapa baris produk, jadi baris
     * digabungkan berdasarkan nomor resi.
     *
     * @param  Collection<int, array<int, string>>  $rows
     * @param  array<string, int>  $header
     * @return Collection<string, array<string, mixed>>
     */
    protected function groupRows(Collection $rows, array $header): Collection
    {
        $orders = collect();

        foreach ($rows as $row) {
            $tracking = $this->value($row, $header, 'tracking_number');
            $sku = $this->value($row, $header, 'sku');

            // Baris tanpa resi atau tanpa SKU tidak bisa dipakai untuk scan.
            if (blank($tracking) || blank($sku)) {
                continue;
            }

            $key = strtoupper(preg_replace('/\s+/', '', $tracking));

            if (! $orders->has($key)) {
                $orders->put($key, [
                    'tracking_number' => $tracking,
                    'order_number' => $this->value($row, $header, 'order_number'),
                    'marketplace' => $this->normalizeMarketplace($this->value($row, $header, 'marketplace'))
                        ?? $this->marketplaceFromCourier($this->value($row, $header, 'courier')),
                    'store_name' => $this->value($row, $header, 'store_name'),
                    'buyer_name' => $this->value($row, $header, 'buyer_name'),
                    'order_status' => $this->value($row, $header, 'order_status'),
                    'courier' => $this->value($row, $header, 'courier'),
                    'shipping_method' => $this->value($row, $header, 'shipping_method'),
                    'buyer_note' => $this->value($row, $header, 'buyer_note'),
                    'order_date' => $this->parseDate($this->value($row, $header, 'order_date')),
                    'items' => [],
                ]);
            }

            $order = $orders->get($key);
            $skuKey = strtoupper(trim($sku));
            $quantity = max(1, (int) preg_replace('/[^0-9]/', '', (string) $this->value($row, $header, 'quantity')) ?: 1);

            // SKU yang sama dalam satu resi dijumlahkan.
            if (isset($order['items'][$skuKey])) {
                $order['items'][$skuKey]['quantity'] += $quantity;
            } else {
                $order['items'][$skuKey] = [
                    'sku' => trim($sku),
                    'product_name' => $this->value($row, $header, 'product_name'),
                    'quantity' => $quantity,
                ];
            }

            $orders->put($key, $order);
        }

        return $orders;
    }

    protected function normalizeMarketplace(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $known = ['Shopee', 'Tokopedia', 'TikTok Shop', 'Lazada', 'Blibli', 'Bukalapak'];

        foreach ($known as $marketplace) {
            if (Str::contains($this->slug($value), $this->slug($marketplace))) {
                return $marketplace;
            }
        }

        return Str::limit($value, 50, '');
    }

    protected function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // Ginee menulis tanggal sebagai hari-bulan-tahun, mis. 04-08-2026 09:34.
        foreach (['d-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            try {
                return \Illuminate\Support\Carbon::createFromFormat($format, $value)->toDateTimeString();
            } catch (\Throwable) {
                // Coba format berikutnya.
            }
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sebagian eksport tidak memuat kolom channel. Nama kurir bawaan
     * marketplace masih bisa dipakai untuk menyimpulkannya.
     */
    protected function marketplaceFromCourier(?string $courier): ?string
    {
        $key = $this->slug($courier);

        return match (true) {
            $key === '' => null,
            str_contains($key, 'spx'), str_contains($key, 'shopee') => 'Shopee',
            str_contains($key, 'tiktok') => 'TikTok Shop',
            str_contains($key, 'tokopedia') => 'Tokopedia',
            str_contains($key, 'lazada'), str_contains($key, 'lex') => 'Lazada',
            default => null,
        };
    }

    /* ------------------------------------------------------ penyimpanan -- */

    /**
     * @param  array<string, int>  $header
     * @param  Collection<string, array<string, mixed>>  $orders
     */
    protected function persist(UploadedFile $file, array $header, int $rowCount, Collection $orders): ShipmentImport
    {
        // SKU adalah kunci pencocokan ke master barang.
        $products = Product::pluck('id', 'sku')
            ->mapWithKeys(fn ($id, $sku) => [strtoupper(trim($sku)) => $id]);

        return DB::transaction(function () use ($file, $header, $rowCount, $orders, $products) {
            $import = ShipmentImport::create([
                'filename' => $file->getClientOriginalName(),
                'source' => 'ginee',
                'row_count' => $rowCount,
                'detected_columns' => array_keys($header),
                'user_id' => auth()->id(),
            ]);

            $itemCount = 0;
            $unmatched = 0;

            foreach ($orders as $data) {
                $items = $data['items'];
                unset($data['items']);

                $order = $this->upsertOrder($import, $data);

                // Isi pesanan selalu diambil ulang dari berkas terbaru.
                $order->items()->delete();

                foreach ($items as $item) {
                    $productId = $products[strtoupper(trim($item['sku']))] ?? null;

                    $order->items()->create($item + ['product_id' => $productId]);

                    $itemCount++;
                    $productId ?: $unmatched++;
                }
            }

            $import->update([
                'order_count' => $orders->count(),
                'item_count' => $itemCount,
                'unmatched_sku_count' => $unmatched,
            ]);

            return $import->refresh();
        });
    }

    /**
     * Perbarui baris resi yang sudah ada, atau buat baru.
     *
     * Dulu baris lama dihapus lalu dibuat dari nol. Kolom shipment_order_id
     * pada dokumen barang keluar diatur nullOnDelete, sehingga setiap import
     * ulang diam-diam memutus tautan dokumen yang sudah ada — termasuk yang
     * sudah dikirim, yang lalu muncul lagi sebagai "Belum QC" di halaman
     * Status Resi padahal stoknya sudah berkurang.
     *
     * @param  array<string, mixed>  $data
     */
    protected function upsertOrder(ShipmentImport $import, array $data): ShipmentOrder
    {
        $order = ShipmentOrder::firstOrNew(['tracking_number' => $data['tracking_number']]);

        // Import terbaru yang menyebut resi ini menjadi pemiliknya.
        $order->fill($data + ['shipment_import_id' => $import->id]);

        // Berkas import hanya boleh menambahkan pembatalan, tidak pernah
        // mencabutnya: petugas yang menandai batal dari aplikasi marketplace
        // biasanya tahu lebih dulu daripada berkas yang diekspor belakangan.
        if (ShipmentOrder::looksCancelled($data['order_status'] ?? null) && ! $order->isCancelled()) {
            $order->cancelled_at = now();
            $order->cancelled_by = null;
            $order->cancellation_reason = null;
        }

        $order->save();

        return $order;
    }
}
