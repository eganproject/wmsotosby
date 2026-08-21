<?php

namespace App\Services;

use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export hasil stok opname ke Excel.
 *
 * Tiga lembar, karena hasil opname dibaca tiga orang yang berbeda: pimpinan
 * ingin satu halaman ringkasan, gudang ingin daftar lengkap untuk diarsipkan,
 * dan yang menindaklanjuti hanya peduli pada barang yang meleset.
 *
 * Angka ditulis sebagai angka, bukan teks berformat — berkas ini hampir selalu
 * diurutkan dan dijumlahkan ulang di spreadsheet. Karena itu pula baris yang
 * belum dihitung dibiarkan kosong, bukan diisi nol atau tanda hubung: nol
 * adalah temuan yang sah, dan tanda hubung merusak kolom angkanya.
 */
class OpnameExportService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $columns = [
        ['SKU', 'sku'],
        ['Nama Barang', 'name'],
        ['Kategori', 'category'],
        ['Lokasi Rak', 'location'],
        ['Satuan', 'unit'],
        ['Stok Sistem', 'system'],
        ['Hasil Hitung', 'counted'],
        ['Selisih', 'difference'],
        ['Rusak Ditemukan', 'damaged'],
        ['Selisih Dibukukan', 'applied'],
        ['Status', 'status'],
        ['Dihitung Oleh', 'counter'],
        ['Waktu Hitung', 'counted_at'],
    ];

    /** Kolom pertama dan terakhir yang berisi angka, untuk perataan kanan. */
    protected const NUMERIC_COLUMNS = [6, 10];

    public function download(StockOpname $opname, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');

        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Detail SKU');

        $variance = $spreadsheet->createSheet();
        $variance->setTitle('Selisih');

        // Satu kali baca dari database: baris berselisih dikumpulkan sambil
        // menulis detailnya, bukan diambil lewat query kedua.
        $varianceRows = $this->writeDetail($detail, $opname);

        $this->writeVariance($variance, $opname, $varianceRows);
        $this->writeSummary($summary, $opname);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /* ------------------------------------------------------- ringkasan --- */

    protected function writeSummary(Worksheet $sheet, StockOpname $opname): void
    {
        $summary = $opname->summary();

        $sheet->setCellValue('A1', 'LAPORAN STOK OPNAME');
        $sheet->setCellValue('A2', sprintf(
            '%s — %s · %s · diunduh %s',
            config('app.name'),
            $opname->code,
            $opname->scopeLabel(),
            now()->translatedFormat('d F Y H:i'),
        ));

        $row = 4;

        $row = $this->writeSection($sheet, $row, 'Informasi Sesi', [
            ['Nomor Dokumen', $opname->code],
            ['Tanggal Opname', $opname->date->translatedFormat('d F Y')],
            ['Cakupan', $opname->scopeLabel()],
            ['Status', $opname->statusLabel()],
            ['Dibuka Oleh', $opname->user?->name ?? '-'],
            ['Diajukan Oleh', $this->person($opname->submitter?->name, $opname->submitted_at)],
            ['Disetujui Oleh', $this->person($opname->approver?->name, $opname->approved_at)],
            ['Diterapkan', $opname->posted_at?->translatedFormat('d F Y H:i') ?? '-'],
            ['Catatan', $opname->note ?: '-'],
        ]);

        $row = $this->writeSection($sheet, $row + 1, 'Ringkasan Hitung', [
            ['Total SKU', $summary['total']],
            ['Sudah Dihitung', $summary['counted']],
            ['Belum Dihitung', $summary['total'] - $summary['counted']],
            ['Sesuai Catatan', $summary['matched']],
            ['Berselisih', $summary['variance']],
            ['Unit Lebih', $summary['surplus']],
            ['Unit Kurang', $summary['shortage']],
            ['Total Meleset (unit)', $opname->absoluteVariance()],
            // Pembandingnya hanya baris yang dihitung; yang belum disentuh
            // tidak boleh ikut mengencerkan angka akurasinya.
            ['Saldo Tercatat pada Baris Terhitung', $opname->countedSystemUnits()],
            ['Ditemukan di Rak', $opname->countedUnits()],
            ['Akurasi per SKU (%)', $opname->accuracyPercentage()],
            ['Akurasi per Unit (%)', $opname->unitAccuracyPercentage()],
        ]);

        $this->writeContributors($sheet, $row + 1, $opname);

        $sheet->mergeCells('A1:C1');
        $sheet->mergeCells('A2:C2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('6D6D6D');

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(20);
    }

    /**
     * @param  array<int, array{0: string, 1: string|int}>  $rows
     * @return int Baris terakhir yang terpakai.
     */
    protected function writeSection(Worksheet $sheet, int $row, string $title, array $rows): int
    {
        $sheet->setCellValue([1, $row], strtoupper($title));
        $sheet->getStyle([1, $row])->getFont()->setBold(true);
        $sheet->getStyle([1, $row, 2, $row])
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F1F1');

        $row++;

        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->setCellValue([2, $row], $value);

            if (is_int($value)) {
                $sheet->getStyle([2, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }

            $row++;
        }

        return $row;
    }

    /**
     * Rekap per petugas. Satu sesi lazim dikerjakan beberapa orang, dan
     * pertanyaan "siapa menghitung apa" adalah yang pertama muncul begitu
     * hasilnya dipersoalkan.
     */
    protected function writeContributors(Worksheet $sheet, int $row, StockOpname $opname): void
    {
        $sheet->setCellValue([1, $row], 'PETUGAS YANG MENGHITUNG');
        $sheet->getStyle([1, $row])->getFont()->setBold(true);
        $sheet->getStyle([1, $row, 4, $row])
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F1F1');

        $row++;
        $header = $row;

        foreach (['Petugas', 'SKU Dihitung', 'Berselisih', 'Hitungan Terakhir'] as $index => $label) {
            $sheet->setCellValue([$index + 1, $row], $label);
        }

        $sheet->getStyle([1, $header, 4, $header])->getFont()->setBold(true);

        $row++;

        foreach ($opname->contributors() as $contributor) {
            $sheet->setCellValue([1, $row], $contributor['name']);
            $sheet->setCellValue([2, $row], $contributor['counted']);
            $sheet->setCellValue([3, $row], $contributor['variance']);
            $sheet->setCellValue([4, $row], $contributor['last_at']
                ? Carbon::parse($contributor['last_at'])->translatedFormat('d M Y H:i')
                : '-');

            $sheet->getStyle([2, $row, 3, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $row++;
        }

        if ($row === $header + 1) {
            $sheet->setCellValue([1, $row], 'Belum ada petugas yang menghitung.');
        }
    }

    protected function person(?string $name, ?Carbon $at): string
    {
        if (! $name) {
            return '-';
        }

        return $at ? $name.' · '.$at->translatedFormat('d F Y H:i') : $name;
    }

    /* ---------------------------------------------------------- detail --- */

    /**
     * Tulis seluruh baris, sambil mengumpulkan yang berselisih.
     *
     * @return array<int, array<int, string|int|null>>
     */
    protected function writeDetail(Worksheet $sheet, StockOpname $opname): array
    {
        $this->writeHeading($sheet, $opname, 'DETAIL HASIL HITUNG PER SKU');

        $row = 5;
        $variances = [];

        $items = $opname->items()
            ->with(['product', 'counter'])
            ->join('products', 'products.id', '=', 'stock_opname_items.product_id')
            ->orderByRaw('products.location IS NULL, products.location, products.name')
            ->select('stock_opname_items.*')
            ->lazy();

        foreach ($items as $item) {
            $values = $this->values($item);

            foreach ($values as $index => $value) {
                $sheet->setCellValue([$index + 1, $row], $value);
            }

            if ($item->isCounted() && $item->difference() !== 0) {
                $variances[] = $values;
            }

            $row++;
        }

        $this->style($sheet, $row - 1);

        return $variances;
    }

    /**
     * Lembar tindak lanjut: hanya barang yang hasil hitungnya berbeda dari
     * catatan, karena itulah satu-satunya yang perlu ditelusuri.
     *
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    protected function writeVariance(Worksheet $sheet, StockOpname $opname, array $rows): void
    {
        $this->writeHeading($sheet, $opname, 'BARANG YANG BERSELISIH');

        $row = 5;

        foreach ($rows as $values) {
            foreach ($values as $index => $value) {
                $sheet->setCellValue([$index + 1, $row], $value);
            }

            $row++;
        }

        if ($rows === []) {
            $sheet->setCellValue([1, $row], 'Tidak ada selisih — seluruh hasil hitung sama dengan catatan.');
        }

        $this->style($sheet, $row - 1);
    }

    /**
     * Satu baris barang, dalam urutan kolom yang sama untuk kedua lembar.
     *
     * @return array<int, string|int|null>
     */
    protected function values(StockOpnameItem $item): array
    {
        $counted = $item->isCounted();

        return [
            $item->product->sku,
            $item->product->name,
            $item->product->category ?? '-',
            $item->product->location ?? '-',
            $item->product->unit,
            $item->system_quantity,
            // Kosong, bukan nol: nol adalah hasil hitung yang sah, dan kolom
            // ini dijumlahkan ulang orang di spreadsheet.
            $counted ? $item->counted_quantity : null,
            $counted ? $item->difference() : null,
            $item->damaged_quantity ?: null,
            $item->applied_difference,
            $this->status($item),
            $item->counter?->name ?? '-',
            $item->counted_at?->translatedFormat('d M Y H:i') ?? '-',
        ];
    }

    protected function status(StockOpnameItem $item): string
    {
        if (! $item->isCounted()) {
            // Baris ini tidak pernah menggerakkan stok — pembacanya perlu tahu
            // bahwa "0 selisih" di sini berarti tidak diperiksa, bukan cocok.
            return 'Belum dihitung';
        }

        return match (true) {
            $item->difference() > 0 => 'Lebih',
            $item->difference() < 0 => 'Kurang',
            default => 'Sesuai',
        };
    }

    /* ----------------------------------------------------------- gaya ---- */

    protected function writeHeading(Worksheet $sheet, StockOpname $opname, string $title): void
    {
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', sprintf(
            '%s · %s · %s · %s',
            $opname->code,
            $opname->scopeLabel(),
            $opname->date->translatedFormat('d F Y'),
            $opname->statusLabel(),
        ));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }
    }

    protected function style(Worksheet $sheet, int $lastRow): void
    {
        $lastColumn = count($this->columns);
        $headerRow = 4;

        $sheet->mergeCells([1, 1, $lastColumn, 1]);
        $sheet->mergeCells([1, 2, $lastColumn, 2]);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('6D6D6D');

        $header = $sheet->getStyle([1, $headerRow, $lastColumn, $headerRow]);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        if ($lastRow > $headerRow) {
            $sheet->getStyle([1, $headerRow, $lastColumn, $lastRow])
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setRGB('D1D1D1');

            foreach (range(self::NUMERIC_COLUMNS[0], self::NUMERIC_COLUMNS[1]) as $column) {
                $sheet->getStyle([$column, $headerRow + 1, $column, $lastRow])
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $sheet->setAutoFilter([1, $headerRow, $lastColumn, max($lastRow, $headerRow)]);
        $sheet->freezePane('A5');
    }
}
