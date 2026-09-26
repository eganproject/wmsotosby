<?php

namespace App\Services;

use App\Support\StockContribution;
use App\Support\StockReportFilters;
use App\Support\StockReportRow;
use App\Support\StockVelocity;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export laporan stok ke Excel, sebagai buku kerja analitik.
 *
 * Penerima berkas tidak punya layar yang menjelaskan angka-angkanya, jadi
 * berkas ini membawa penjelasannya sendiri:
 * - Ringkasan      : angka utama, rekap klasifikasi, temuan, dan grafik
 * - Detail per SKU : seluruh baris sesuai saringan di layar
 * - Analisis Pareto: barang bergerak diurutkan menurut kontribusi
 * - Perlu Tindakan : restock mendesak, stok berlebih, dan stok mati
 * - Keterangan     : arti setiap kolom dan aturan klasifikasi
 *
 * Angka ditulis sebagai angka, persen sebagai pecahan berformat persen, dan
 * tanggal sebagai tanggal Excel: berkas ini paling sering diurutkan, disaring,
 * dan diolah lagi, dan itu mustahil kalau isinya "12 hari".
 */
class StockReportExportService
{
    /** Batas days cover yang dianggap perlu restock segera. */
    protected const RESTOCK_COVER_DAYS = 14;

    /** Di atas ini stok Slow Moving dianggap berlebih. */
    protected const OVERSTOCK_COVER_DAYS = 90;

    protected const INT = '#,##0';

    protected const DECIMAL = '#,##0.00';

    protected const DAYS = '#,##0.0';

    protected const PERCENT = '0.00%';

    protected const DATE = 'dd mmm yyyy';

    /** Warna kelas: [isi sel, teks]. Mengikuti warna badge di layar. */
    protected const CLASS_COLORS = [
        StockVelocity::FAST => ['E7F6EC', '166534'],
        StockVelocity::MEDIUM => ['FEF3C7', '92400E'],
        StockVelocity::SLOW => ['EEEEEE', '404040'],
        StockVelocity::NON_MOVING => ['F7F7F7', '8A8A8A'],
    ];

    /**
     * Kolom sheet detail: [judul, kolom, format angka, lebar].
     *
     * @var array<int, array{0: string, 1: string, 2: ?string, 3: int}>
     */
    protected array $columns = [
        ['No', 'no', self::INT, 6],
        ['SKU', 'sku', null, 18],
        ['Nama Barang', 'name', null, 40],
        ['Kategori', 'category', null, 16],
        ['Satuan', 'unit', null, 8],
        ['Klasifikasi', 'movement_class', null, 15],
        ['Stok Awal', 'opening', self::INT, 11],
        ['Masuk', 'incoming', self::INT, 10],
        ['Keluar (semua mutasi)', 'outgoing', self::INT, 12],
        ['Qty Keluar Operasional', 'sold', self::INT, 12],
        ['Stok Akhir', 'closing', self::INT, 11],
        ['Rata-rata Keluar/Hari', 'per_day', self::DECIMAL, 12],
        ['Kontribusi', 'share', self::PERCENT, 11],
        ['Kontribusi Kumulatif', 'cumulative', self::PERCENT, 12],
        ['Frequency (dokumen)', 'frequency', self::INT, 11],
        ['Days Cover (hari)', 'cover', self::DAYS, 11],
        ['Perputaran (kali)', 'turnover', self::DECIMAL, 11],
        ['Status Stok', 'status', null, 18],
        ['Terakhir Keluar', 'last_out', self::DATE, 14],
        ['Stok Minimum', 'min_stock', self::INT, 10],
        ['Stok Rusak', 'damaged', self::INT, 10],
    ];

    public function download(StockReportService $report, StockReportFilters $filters, string $filename): StreamedResponse
    {
        $spreadsheet = $this->build($report, $filters);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    public function build(StockReportService $report, StockReportFilters $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Laporan Stok '.$filters->label());

        // Ringkasan dan analisis selalu mencakup semua kelas; hanya sheet
        // detail yang mengikuti pilihan tampilan di layar.
        $all = $filters->with(view: 'semua', sort: 'keluar');
        $contribution = $report->contribution($filters);

        $summarySheet = $spreadsheet->getActiveSheet()->setTitle('Ringkasan');
        $detailSheet = $spreadsheet->createSheet()->setTitle('Detail per SKU');
        $paretoSheet = $spreadsheet->createSheet()->setTitle('Analisis Pareto');
        $actionSheet = $spreadsheet->createSheet()->setTitle('Perlu Tindakan');
        $notesSheet = $spreadsheet->createSheet()->setTitle('Keterangan');

        $this->writeDetail($detailSheet, $report, $filters);
        $analysis = $this->writePareto($paretoSheet, $report, $all, $filters);
        $this->writeActions($actionSheet, $analysis, $filters);
        $this->writeSummary($summarySheet, $report->summary($all), $report->classSummary($filters), $analysis, $filters, $contribution);
        $this->writeNotes($notesSheet, $filters);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /* ---------------------------------------------------- ringkasan ------ */

    /**
     * @param  array<string, int|float|null>  $summary
     * @param  array<string, array{products: int, outgoing: int, closing: int, share: float}>  $classes
     * @param  array{top: array<int, StockReportRow>, restock: array<int, StockReportRow>, overstock: array<int, StockReportRow>, dead: array<int, StockReportRow>, moving: int, closing: int}  $analysis
     */
    protected function writeSummary(Worksheet $sheet, array $summary, array $classes, array $analysis, StockReportFilters $filters, StockContribution $contribution): void
    {
        $lastColumn = 9;
        $this->title($sheet, 'LAPORAN STOK & ANALISIS PERGERAKAN BARANG', $filters, $lastColumn);

        foreach ([1 => 26, 2 => 16, 3 => 13, 4 => 15, 5 => 13, 6 => 15, 7 => 13, 8 => 15, 9 => 42] as $column => $width) {
            $sheet->getColumnDimensionByColumn($column)->setWidth($width);
        }

        // Informasi laporan.
        $row = 4;
        $this->section($sheet, $row, 'Informasi Laporan', $lastColumn);
        $info = [
            ['Periode', $filters->label().' ('.$filters->days().' hari)'],
            ['Kategori', $filters->category ?? 'Semua kategori'],
            ['Pencarian', $filters->search ?? '—'],
            ['Tampilan sheet detail', $filters->viewLabel().' · urut '.mb_strtolower(StockReportFilters::SORTS[$filters->sort])],
            ['Diunduh', now()->translatedFormat('d F Y H:i').(auth()->user() ? ' oleh '.auth()->user()->name : '')],
        ];
        foreach ($info as [$label, $value]) {
            $row++;
            $sheet->setCellValue([1, $row], $label);
            $sheet->setCellValueExplicit([2, $row], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->mergeCells([2, $row, $lastColumn, $row]);
            $sheet->getStyle([1, $row])->getFont()->getColor()->setRGB('6D6D6D');
        }

        // Angka utama.
        $row += 2;
        $this->section($sheet, $row, 'Angka Utama', $lastColumn);
        $row++;
        $this->header($sheet, $row, ['Indikator', 'Nilai', 'Keterangan']);
        $sheet->mergeCells([3, $row, $lastColumn, $row]);
        $metricsStart = $row + 1;

        $metrics = [
            ['Jumlah SKU', $summary['products'], self::INT, 'Barang satuan yang tercakup saringan (paket bundling tidak dihitung)'],
            ['Stok awal periode', $summary['opening'], self::INT, 'Unit layak jual pada awal periode'],
            ['Barang masuk', $summary['incoming'], self::INT, 'Unit layak jual yang masuk selama periode'],
            ['Qty keluar operasional', $summary['outgoing'], self::INT, 'Unit keluar lewat dokumen barang keluar'],
            ['Stok akhir periode', $summary['closing'], self::INT, 'Unit layak jual pada akhir periode'],
            ['Rata-rata keluar per hari', $summary['per_day'], self::DECIMAL, 'Qty keluar operasional ÷ '.$summary['days'].' hari'],
            ['Perputaran stok', $summary['turnover'], self::DECIMAL, 'Qty keluar ÷ rata-rata stok awal dan akhir (kali)'],
            ['Days cover keseluruhan', $summary['cover'], self::DAYS, 'Stok akhir ÷ rata-rata keluar per hari (hari)'],
            ['SKU stok menipis', $summary['low'], self::INT, 'Saldo akhir di bawah atau sama dengan stok minimum'],
            ['Unit stok rusak', $summary['damaged'], self::INT, 'Saldo rusak, terpisah dan tidak ikut dihitung di atas'],
        ];
        foreach ($metrics as [$label, $value, $format, $note]) {
            $row++;
            $sheet->setCellValue([1, $row], $label);
            $sheet->setCellValue([2, $row], $value ?? '—');
            $sheet->setCellValue([3, $row], $note);
            $sheet->mergeCells([3, $row, $lastColumn, $row]);
            $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle([2, $row])->getFont()->setBold(true);
            $sheet->getStyle([2, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle([3, $row])->getFont()->getColor()->setRGB('6D6D6D');
        }
        $this->grid($sheet, $metricsStart - 1, 1, $row, $lastColumn);

        // Rekap klasifikasi.
        $row += 2;
        $this->section($sheet, $row, 'Rekap Klasifikasi Pergerakan', $lastColumn);
        $row++;
        $this->header($sheet, $row, [
            'Klasifikasi', 'Jumlah SKU', '% dari SKU', 'Qty Keluar', 'Kontribusi',
            'Stok Akhir', '% dari Stok', 'Days Cover Kelas', 'Aturan',
        ]);
        $classHeader = $row;

        $rules = [
            StockVelocity::FAST => 'Kontribusi kumulatif awal hingga 70%',
            StockVelocity::MEDIUM => 'Lapisan kontribusi kumulatif 70% – 90%',
            StockVelocity::SLOW => 'Sisa kontribusi setelah 90%',
            StockVelocity::NON_MOVING => 'Tidak ada barang keluar operasional',
        ];
        $totalProducts = array_sum(array_column($classes, 'products'));
        $totalOutgoing = array_sum(array_column($classes, 'outgoing'));
        $totalClosing = array_sum(array_column($classes, 'closing'));

        foreach ($classes as $class => $data) {
            $row++;
            $perDay = $data['outgoing'] / $filters->days();
            $sheet->fromArray([
                StockVelocity::classes()[$class],
                $data['products'],
                $totalProducts > 0 ? $data['products'] / $totalProducts : 0,
                $data['outgoing'],
                $contribution->total > 0 ? $data['outgoing'] / $contribution->total : 0,
                $data['closing'],
                $totalClosing > 0 ? $data['closing'] / $totalClosing : 0,
                $perDay > 0 ? $data['closing'] / $perDay : null,
                $rules[$class],
            ], null, 'A'.$row, true);
            $this->paintClass($sheet, [1, $row], $class);
        }

        $row++;
        $sheet->fromArray([
            'Total', $totalProducts, $totalProducts > 0 ? 1 : 0, $totalOutgoing,
            $contribution->total > 0 ? $totalOutgoing / $contribution->total : 0,
            $totalClosing, $totalClosing > 0 ? 1 : 0,
            $totalOutgoing > 0 ? $totalClosing / ($totalOutgoing / $filters->days()) : null,
            $filters->search || $filters->category ? 'Kontribusi terhadap seluruh SKU pada periode ini' : '',
        ], null, 'A'.$row, true);
        $sheet->getStyle([1, $row, $lastColumn, $row])->getFont()->setBold(true);
        $sheet->getStyle([1, $row, $lastColumn, $row])->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        foreach ([2 => self::INT, 3 => self::PERCENT, 4 => self::INT, 5 => self::PERCENT, 6 => self::INT, 7 => self::PERCENT, 8 => self::DAYS] as $column => $format) {
            $sheet->getStyle([$column, $classHeader + 1, $column, $row])->getNumberFormat()->setFormatCode($format);
        }
        $this->grid($sheet, $classHeader, 1, $row, $lastColumn);
        $this->addClassChart($sheet, $classHeader + 1, $classHeader + 4);

        // Temuan utama.
        $row += 2;
        $this->section($sheet, $row, 'Temuan Utama', $lastColumn);
        foreach ($this->insights($summary, $classes, $analysis, $filters) as $insight) {
            $row++;
            $sheet->setCellValue([1, $row], '•  '.$insight);
            $sheet->mergeCells([1, $row, $lastColumn, $row]);
            $sheet->getStyle([1, $row])->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getRowDimension($row)->setRowHeight(28);
        }

        // Sepuluh SKU dengan kontribusi terbesar.
        $row += 2;
        $this->section($sheet, $row, '10 SKU dengan Kontribusi Terbesar', $lastColumn);
        $row++;
        $this->header($sheet, $row, ['SKU', 'Nama Barang', '', 'Klasifikasi', 'Qty Keluar', 'Kontribusi', 'Kumulatif', 'Days Cover', 'Terakhir Keluar']);
        $sheet->mergeCells([2, $row, 3, $row]);
        $topHeader = $row;

        if ($analysis['top'] === []) {
            $row++;
            $sheet->setCellValue([1, $row], 'Belum ada barang keluar operasional pada periode ini.');
            $sheet->mergeCells([1, $row, $lastColumn, $row]);
        }

        foreach ($analysis['top'] as $line) {
            $row++;
            $sheet->fromArray([
                $line->sku, $line->name, null, $line->movementBadge()['label'], $line->sold,
                $line->share() / 100, ($line->cumulativeShare() ?? 0) / 100, $line->daysOfCover(),
                $this->date($line->lastOutAt),
            ], null, 'A'.$row, true);
            $sheet->mergeCells([2, $row, 3, $row]);
            $this->paintClass($sheet, [4, $row], $line->movementClass());
        }

        foreach ([5 => self::INT, 6 => self::PERCENT, 7 => self::PERCENT, 8 => self::DAYS, 9 => self::DATE] as $column => $format) {
            $sheet->getStyle([$column, $topHeader + 1, $column, $row])->getNumberFormat()->setFormatCode($format);
        }
        $sheet->getStyle([9, $topHeader + 1, 9, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->grid($sheet, $topHeader, 1, $row, $lastColumn);

        $sheet->getSheetView()->setZoomScale(100);
        $sheet->setShowGridlines(false);
        $this->printSetup($sheet, landscape: false);
    }

    /**
     * Kalimat singkat yang langsung bisa dibaca atasan tanpa membuka tabel.
     *
     * @return array<int, string>
     */
    protected function insights(array $summary, array $classes, array $analysis, StockReportFilters $filters): array
    {
        $insights = [];
        $number = fn (int|float $value, int $decimals = 0) => number_format($value, $decimals, ',', '.');
        $fast = $classes[StockVelocity::FAST];
        $slow = $classes[StockVelocity::SLOW];
        $idle = $classes[StockVelocity::NON_MOVING];
        $moving = $analysis['moving'];
        $totalClosing = max(1, array_sum(array_column($classes, 'closing')));

        if ($moving === 0) {
            return ['Tidak ada barang keluar operasional selama '.$filters->days().' hari pada periode ini, sehingga seluruh SKU tergolong Non-Moving.'];
        }

        $insights[] = sprintf(
            '%s SKU Fast Moving (%s%% dari %s SKU yang bergerak) menyumbang %s%% seluruh qty keluar. Jaga ketersediaan kelompok ini lebih dulu.',
            $number($fast['products']), $number($fast['products'] / $moving * 100, 1), $number($moving), $number($fast['share'], 1),
        );

        if ($top = $analysis['top'][0] ?? null) {
            $insights[] = sprintf(
                'SKU paling berkontribusi: %s — %s, %s unit keluar (%s%% dari total) dalam %s dokumen.',
                $top->sku, $top->name, $number($top->sold), $number($top->share(), 1), $number($top->frequency),
            );
        }

        if ($count = count($analysis['restock'])) {
            $empty = count(array_filter($analysis['restock'], fn (StockReportRow $line) => $line->isOutOfStock()));
            $insights[] = sprintf(
                '%s SKU Fast/Medium Moving diperkirakan habis dalam %d hari atau kurang%s. Daftarnya ada di sheet "Perlu Tindakan".',
                $number($count), self::RESTOCK_COVER_DAYS, $empty ? ' — '.$number($empty).' di antaranya sudah habis' : '',
            );
        }

        if ($idle['products'] > 0) {
            $insights[] = sprintf(
                '%s SKU tidak keluar sama sekali selama %d hari dan menahan %s unit (%s%% dari stok akhir). Pertimbangkan promosi, bundling, atau retur ke pemasok.',
                $number($idle['products']), $filters->days(), $number($idle['closing']), $number($idle['closing'] / $totalClosing * 100, 1),
            );
        }

        $slowStock = ($slow['closing'] + $idle['closing']) / $totalClosing * 100;
        if ($slowStock > 0) {
            $insights[] = sprintf(
                'Slow Moving dan Non-Moving memegang %s%% stok akhir, tetapi hanya menyumbang %s%% qty keluar.',
                $number($slowStock, 1), $number($slow['share'], 1),
            );
        }

        if ($count = count($analysis['overstock'])) {
            $insights[] = sprintf(
                '%s SKU Slow Moving punya stok untuk lebih dari %d hari. Tahan pembelian ulang untuk barang-barang ini.',
                $number($count), self::OVERSTOCK_COVER_DAYS,
            );
        }

        if ($summary['low'] > 0) {
            $insights[] = $number($summary['low']).' SKU berada di bawah atau sama dengan stok minimum.';
        }

        return $insights;
    }

    protected function addClassChart(Worksheet $sheet, int $firstRow, int $lastRow): void
    {
        $title = $sheet->getTitle();
        $count = $lastRow - $firstRow + 1;
        $range = fn (string $column) => "'{$title}'!\${$column}\${$firstRow}:\${$column}\${$lastRow}";

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0, 1],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$title}'!\$C\$".($firstRow - 1), null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$title}'!\$E\$".($firstRow - 1), null, 1),
            ],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $range('A'), null, $count)],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range('C'), self::PERCENT, $count),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $range('E'), self::PERCENT, $count),
            ],
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $chart = new Chart(
            'porsi-klasifikasi',
            new Title('Porsi SKU vs Porsi Qty Keluar per Klasifikasi'),
            new Legend(Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$series]),
        );
        $chart->setTopLeftPosition('K4');
        $chart->setBottomRightPosition('S22');

        $sheet->addChart($chart);
    }

    /* ---------------------------------------------------- detail --------- */

    protected function writeDetail(Worksheet $sheet, StockReportService $report, StockReportFilters $filters): void
    {
        $lastColumn = count($this->columns);
        $this->title($sheet, 'DETAIL PER SKU — '.mb_strtoupper($filters->viewLabel()), $filters, $lastColumn);

        $headerRow = 4;
        $this->header($sheet, $headerRow, array_column($this->columns, 0));

        $row = $headerRow;
        $classColumn = array_search('movement_class', array_column($this->columns, 1), true) + 1;

        // Nomor dihitung sendiri: kunci dari lazy() kembali ke nol di tiap potongan.
        foreach ($report->lazy($filters) as $line) {
            $row++;
            $values = [];
            foreach ($this->columns as [, $field]) {
                $values[] = $this->value($line, $field, $row - $headerRow);
            }
            $sheet->fromArray($values, null, 'A'.$row, true);
            $this->paintClass($sheet, [$classColumn, $row], $line->movementClass());
        }

        if ($row === $headerRow) {
            $sheet->setCellValue([1, $headerRow + 1], 'Tidak ada barang yang cocok dengan saringan.');
            $sheet->mergeCells([1, $headerRow + 1, $lastColumn, $headerRow + 1]);

            return;
        }

        $this->formatColumns($sheet, $this->columns, $headerRow + 1, $row);
        $this->grid($sheet, $headerRow, 1, $row, $lastColumn);
        $this->zebra($sheet, $headerRow + 1, $row, $lastColumn, skip: [$classColumn]);

        $sheet->setAutoFilter([1, $headerRow, $lastColumn, $row]);
        $sheet->freezePane('D5');
        $this->printSetup($sheet, landscape: true, repeatRow: $headerRow);
    }

    protected function value(StockReportRow $line, string $field, int $number): string|int|float|null
    {
        return match ($field) {
            'no' => $number,
            'sku' => $line->sku,
            'name' => $line->name,
            'category' => $line->category ?? '-',
            'unit' => $line->unit,
            'movement_class' => $line->movementBadge()['label'],
            'opening' => $line->opening,
            'incoming' => $line->incoming,
            'outgoing' => $line->outgoing,
            'sold' => $line->sold,
            'closing' => $line->closing,
            'per_day' => round($line->perDay(), 4),
            'share' => $line->share() / 100,
            'cumulative' => $line->cumulativeShare() === null ? null : $line->cumulativeShare() / 100,
            'frequency' => $line->frequency,
            'cover' => $line->daysOfCover() === null ? null : round($line->daysOfCover(), 1),
            'turnover' => $line->turnover() === null ? null : round($line->turnover(), 2),
            'status' => $this->status($line),
            'last_out' => $this->date($line->lastOutAt),
            'min_stock' => $line->minStock,
            'damaged' => $line->damaged,
            default => null,
        };
    }

    protected function status(StockReportRow $line): string
    {
        return match ($line->urgency()) {
            'habis' => 'Habis',
            'kritis' => 'Kritis (≤ 7 hari)',
            'waspada' => 'Waspada (8–14 hari)',
            'diam' => 'Tidak bergerak',
            default => 'Aman',
        };
    }

    /* ---------------------------------------------------- pareto --------- */

    /**
     * Menulis sheet Pareto sambil mengumpulkan bahan ringkasan, supaya
     * seluruh barang cukup dibaca sekali.
     *
     * @return array{top: array<int, StockReportRow>, restock: array<int, StockReportRow>, overstock: array<int, StockReportRow>, dead: array<int, StockReportRow>, moving: int, closing: int}
     */
    protected function writePareto(Worksheet $sheet, StockReportService $report, StockReportFilters $all, StockReportFilters $filters): array
    {
        $columns = [
            ['Peringkat', 'rank', self::INT, 10],
            ['SKU', 'sku', null, 18],
            ['Nama Barang', 'name', null, 40],
            ['Kategori', 'category', null, 16],
            ['Klasifikasi', 'class', null, 15],
            ['Qty Keluar', 'sold', self::INT, 12],
            ['Kontribusi', 'share', self::PERCENT, 12],
            ['Kontribusi Kumulatif', 'cumulative', self::PERCENT, 13],
            ['Frequency (dokumen)', 'frequency', self::INT, 12],
            ['Rata-rata Keluar/Hari', 'per_day', self::DECIMAL, 12],
            ['Stok Akhir', 'closing', self::INT, 11],
            ['Days Cover (hari)', 'cover', self::DAYS, 11],
        ];
        $lastColumn = count($columns);

        $this->title($sheet, 'ANALISIS PARETO — KONTRIBUSI QTY KELUAR', $filters, $lastColumn);
        $sheet->setCellValue('A3', 'Barang bergerak diurutkan dari kontribusi terbesar. Garis batas kelas ada di kontribusi kumulatif 70% dan 90%.');
        $sheet->mergeCells([1, 3, $lastColumn, 3]);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('6D6D6D');

        $headerRow = 5;
        $this->header($sheet, $headerRow, array_column($columns, 0));

        $analysis = ['top' => [], 'restock' => [], 'overstock' => [], 'dead' => [], 'moving' => 0, 'closing' => 0];
        $row = $headerRow;
        $previousClass = null;

        foreach ($report->lazy($all) as $line) {
            $class = $line->movementClass();
            $cover = $line->daysOfCover();
            $analysis['closing'] += max(0, $line->closing);

            if ($class === StockVelocity::NON_MOVING) {
                if ($line->closing > 0) {
                    $analysis['dead'][] = $line;
                }

                continue;
            }

            $analysis['moving']++;
            if (count($analysis['top']) < 10) {
                $analysis['top'][] = $line;
            }
            if (in_array($class, [StockVelocity::FAST, StockVelocity::MEDIUM], true) && $cover !== null && $cover <= self::RESTOCK_COVER_DAYS) {
                $analysis['restock'][] = $line;
            }
            if ($class === StockVelocity::SLOW && $cover !== null && $cover > self::OVERSTOCK_COVER_DAYS) {
                $analysis['overstock'][] = $line;
            }

            $row++;
            $sheet->fromArray([
                $analysis['moving'], $line->sku, $line->name, $line->category ?? '-', $line->movementBadge()['label'],
                $line->sold, $line->share() / 100, ($line->cumulativeShare() ?? 0) / 100, $line->frequency,
                round($line->perDay(), 4), $line->closing, $cover === null ? null : round($cover, 1),
            ], null, 'A'.$row, true);

            [$fill, $text] = self::CLASS_COLORS[$class];
            $sheet->getStyle([1, $row, $lastColumn, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $sheet->getStyle([5, $row])->getFont()->setBold(true)->getColor()->setRGB($text);

            // Garis tebal di tempat kelas berganti: batas 70% dan 90%.
            if ($previousClass !== null && $previousClass !== $class) {
                $sheet->getStyle([1, $row, $lastColumn, $row])->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0A0A0A');
            }
            $previousClass = $class;
        }

        if ($row === $headerRow) {
            $sheet->setCellValue([1, $headerRow + 1], 'Belum ada barang keluar operasional pada periode ini.');
            $sheet->mergeCells([1, $headerRow + 1, $lastColumn, $headerRow + 1]);
        } else {
            $this->formatColumns($sheet, $columns, $headerRow + 1, $row);
            $this->grid($sheet, $headerRow, 1, $row, $lastColumn, keepTopBorders: true);
            $sheet->setAutoFilter([1, $headerRow, $lastColumn, $row]);
        }

        $this->widths($sheet, $columns);
        $sheet->freezePane('D6');
        $this->printSetup($sheet, landscape: true, repeatRow: $headerRow);

        usort($analysis['restock'], fn (StockReportRow $a, StockReportRow $b) => $a->daysOfCover() <=> $b->daysOfCover());
        usort($analysis['overstock'], fn (StockReportRow $a, StockReportRow $b) => $b->daysOfCover() <=> $a->daysOfCover());
        usort($analysis['dead'], fn (StockReportRow $a, StockReportRow $b) => $b->closing <=> $a->closing);

        return $analysis;
    }

    /* ---------------------------------------------------- tindakan ------- */

    protected function writeActions(Worksheet $sheet, array $analysis, StockReportFilters $filters): void
    {
        $lastColumn = 10;
        $this->title($sheet, 'PERLU TINDAKAN', $filters, $lastColumn);
        foreach ([1 => 6, 2 => 18, 3 => 40, 4 => 15, 5 => 11, 6 => 11, 7 => 12, 8 => 12, 9 => 20, 10 => 15] as $column => $width) {
            $sheet->getColumnDimensionByColumn($column)->setWidth($width);
        }

        $row = 4;

        $row = $this->actionTable(
            $sheet, $row,
            'A. Prioritas Restock — Fast & Medium Moving dengan days cover ≤ '.self::RESTOCK_COVER_DAYS.' hari',
            'Barang paling laku yang akan habis lebih dulu. Diurutkan dari days cover tersingkat.',
            ['No', 'SKU', 'Nama Barang', 'Klasifikasi', 'Stok Akhir', 'Stok Min', 'Rata-rata/Hari', 'Days Cover', 'Status', 'Terakhir Keluar'],
            $analysis['restock'],
            fn (StockReportRow $line, int $no) => [
                $no, $line->sku, $line->name, $line->movementBadge()['label'], $line->closing, $line->minStock,
                round($line->perDay(), 4), round($line->daysOfCover(), 1), $this->status($line), $this->date($line->lastOutAt),
            ],
            'Tidak ada barang Fast/Medium Moving yang akan habis dalam '.self::RESTOCK_COVER_DAYS.' hari.',
        );

        $row = $this->actionTable(
            $sheet, $row + 2,
            'B. Stok Berlebih — Slow Moving dengan days cover > '.self::OVERSTOCK_COVER_DAYS.' hari',
            'Barang yang masih bergerak tetapi stoknya jauh melebihi kebutuhan. Tahan pembelian ulang.',
            ['No', 'SKU', 'Nama Barang', 'Klasifikasi', 'Stok Akhir', 'Stok Min', 'Rata-rata/Hari', 'Days Cover', 'Frequency', 'Terakhir Keluar'],
            $analysis['overstock'],
            fn (StockReportRow $line, int $no) => [
                $no, $line->sku, $line->name, $line->movementBadge()['label'], $line->closing, $line->minStock,
                round($line->perDay(), 4), round($line->daysOfCover(), 1), $line->frequency, $this->date($line->lastOutAt),
            ],
            'Tidak ada barang Slow Moving dengan stok berlebih.',
        );

        $this->actionTable(
            $sheet, $row + 2,
            'C. Stok Mati — Non-Moving yang masih memiliki stok',
            'Tidak ada barang keluar operasional selama '.$filters->days().' hari. Diurutkan dari stok terbanyak.',
            ['No', 'SKU', 'Nama Barang', 'Kategori', 'Stok Akhir', 'Stok Min', 'Hari Tanpa Keluar', '% dari Stok Akhir', 'Keterangan', 'Terakhir Keluar'],
            $analysis['dead'],
            function (StockReportRow $line, int $no) use ($filters, $analysis) {
                $idleDays = $line->lastOutAt
                    ? (int) Carbon::parse($line->lastOutAt)->startOfDay()->diffInDays($filters->to->copy()->startOfDay())
                    : null;

                return [
                    $no, $line->sku, $line->name, $line->category ?? '-', $line->closing, $line->minStock,
                    $idleDays, $analysis['closing'] > 0 ? $line->closing / $analysis['closing'] : null, $line->lastOutAt ? 'Pernah keluar sebelum periode' : 'Belum pernah keluar',
                    $this->date($line->lastOutAt),
                ];
            },
            'Tidak ada stok mati pada periode ini.',
        );

        $sheet->setShowGridlines(false);
        $this->printSetup($sheet, landscape: true);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, StockReportRow>  $lines
     */
    protected function actionTable(Worksheet $sheet, int $row, string $title, string $description, array $headers, array $lines, callable $values, string $empty): int
    {
        $lastColumn = count($headers);

        $this->section($sheet, $row, $title.' ('.count($lines).' SKU)', $lastColumn);
        $row++;
        $sheet->setCellValue([1, $row], $description);
        $sheet->mergeCells([1, $row, $lastColumn, $row]);
        $sheet->getStyle([1, $row])->getFont()->setItalic(true)->getColor()->setRGB('6D6D6D');

        $row++;
        $this->header($sheet, $row, $headers);
        $headerRow = $row;

        if ($lines === []) {
            $row++;
            $sheet->setCellValue([1, $row], $empty);
            $sheet->mergeCells([1, $row, $lastColumn, $row]);
            $sheet->getStyle([1, $row])->getFont()->getColor()->setRGB('6D6D6D');

            return $row;
        }

        foreach ($lines as $index => $line) {
            $row++;
            $sheet->fromArray($values($line, $index + 1), null, 'A'.$row, true);

            if ($headers[3] === 'Klasifikasi') {
                $this->paintClass($sheet, [4, $row], $line->movementClass());
            }
            if ($line->isOutOfStock()) {
                $sheet->getStyle([5, $row])->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
            }
        }

        foreach ([1 => self::INT, 5 => self::INT, 6 => self::INT, 7 => self::DECIMAL, 8 => self::DAYS, 10 => self::DATE] as $column => $format) {
            $sheet->getStyle([$column, $headerRow + 1, $column, $row])->getNumberFormat()->setFormatCode($format);
        }
        if ($headers[6] === 'Hari Tanpa Keluar') {
            $sheet->getStyle([7, $headerRow + 1, 7, $row])->getNumberFormat()->setFormatCode(self::INT);
            $sheet->getStyle([8, $headerRow + 1, 8, $row])->getNumberFormat()->setFormatCode(self::PERCENT);
        }
        $sheet->getStyle([10, $headerRow + 1, 10, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->grid($sheet, $headerRow, 1, $row, $lastColumn);

        return $row;
    }

    /* ---------------------------------------------------- keterangan ----- */

    protected function writeNotes(Worksheet $sheet, StockReportFilters $filters): void
    {
        $this->title($sheet, 'KETERANGAN', $filters, 2);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(100);

        $sections = [
            'Klasifikasi Pergerakan' => [
                ['Fast Moving', 'SKU dengan kontribusi kumulatif awal hingga 70% dari seluruh qty keluar operasional.'],
                ['Medium Moving', 'SKU pada lapisan kontribusi berikutnya, dari 70% hingga 90% qty keluar.'],
                ['Slow Moving', 'SKU yang bergerak pada sisa kontribusi setelah 90% qty keluar.'],
                ['Non-Moving', 'SKU yang tidak memiliki barang keluar operasional selama periode terpilih.'],
                ['Cara menghitung', 'SKU diurutkan dari qty keluar terbanyak. Kelas ditentukan oleh kontribusi kumulatif SKU-SKU di atasnya, sehingga SKU teratas selalu Fast Moving dan SKU dengan qty keluar sama selalu satu kelas.'],
                ['Cakupan', 'Kontribusi dihitung terhadap seluruh SKU pada periode ini, tidak terpengaruh pencarian atau kategori yang dipilih.'],
            ],
            'Kolom' => [
                ['Stok Awal / Akhir', 'Saldo layak jual pada awal dan akhir periode. Stok rusak tidak termasuk.'],
                ['Keluar (semua mutasi)', 'Seluruh pengurangan stok layak jual, termasuk penyesuaian dan selisih opname. Hanya dipakai untuk saldo.'],
                ['Qty Keluar Operasional', 'Unit yang keluar lewat dokumen barang keluar yang sudah disetujui. Dasar semua perhitungan kecepatan.'],
                ['Rata-rata Keluar/Hari', 'Qty keluar operasional ÷ jumlah hari periode ('.$filters->days().' hari).'],
                ['Kontribusi', 'Qty keluar SKU ÷ total qty keluar operasional seluruh SKU.'],
                ['Kontribusi Kumulatif', 'Jumlah kontribusi SKU ini dan semua SKU di atasnya pada urutan Pareto.'],
                ['Frequency', 'Jumlah dokumen barang keluar yang memuat SKU ini selama periode.'],
                ['Days Cover', 'Stok akhir ÷ rata-rata keluar per hari: perkiraan berapa hari stok bertahan. Kosong bila tidak ada barang keluar.'],
                ['Perputaran', 'Qty keluar ÷ rata-rata stok awal dan akhir. Kosong bila tidak ada stok untuk diputar.'],
                ['Status Stok', 'Habis; Kritis bila days cover ≤ 7 hari; Waspada 8–14 hari; Aman di atasnya; Tidak bergerak bila tidak ada barang keluar.'],
                ['Terakhir Keluar', 'Tanggal barang keluar operasional terakhir sampai akhir periode, termasuk yang terjadi sebelum periode.'],
            ],
            'Catatan' => [
                ['Waktu pencatatan', 'Stok berkurang saat dokumen barang keluar disetujui, bukan saat barang selesai discan di stasiun packing.'],
                ['Paket bundling', 'Tidak dihitung; yang bergerak adalah barang isinya.'],
            ],
        ];

        $row = 3;
        foreach ($sections as $title => $lines) {
            $row++;
            $this->section($sheet, $row, $title, 2);
            foreach ($lines as [$term, $meaning]) {
                $row++;
                $sheet->setCellValue([1, $row], $term);
                $sheet->setCellValue([2, $row], $meaning);
                $sheet->getStyle([1, $row])->getFont()->setBold(true);
                $sheet->getStyle([2, $row])->getAlignment()->setWrapText(true);
                $sheet->getStyle([1, $row, 2, $row])->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

                $class = array_search($term, StockVelocity::classes(), true);
                if ($class !== false) {
                    $this->paintClass($sheet, [1, $row], $class);
                }
            }
            $row++;
        }

        $sheet->setShowGridlines(false);
        $this->printSetup($sheet, landscape: false);
    }

    /* ---------------------------------------------------- gaya ----------- */

    protected function title(Worksheet $sheet, string $title, StockReportFilters $filters, int $lastColumn): void
    {
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', sprintf('%s · periode %s (%d hari)', config('app.name'), $filters->label(), $filters->days()));
        $sheet->mergeCells([1, 1, max(2, $lastColumn), 1]);
        $sheet->mergeCells([1, 2, max(2, $lastColumn), 2]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('6D6D6D');
        $sheet->getRowDimension(1)->setRowHeight(24);
    }

    protected function section(Worksheet $sheet, int $row, string $text, int $lastColumn): void
    {
        $sheet->setCellValue([1, $row], $text);
        $sheet->mergeCells([1, $row, $lastColumn, $row]);
        $style = $sheet->getStyle([1, $row, $lastColumn, $row]);
        $style->getFont()->setBold(true)->setSize(11);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0A0A0A');
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    /**
     * @param  array<int, string>  $labels
     */
    protected function header(Worksheet $sheet, int $row, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $sheet->setCellValue([$index + 1, $row], $label);
        }

        $style = $sheet->getStyle([1, $row, count($labels), $row]);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(32);
    }

    protected function grid(Worksheet $sheet, int $fromRow, int $fromColumn, int $toRow, int $toColumn, bool $keepTopBorders = false): void
    {
        $borders = $sheet->getStyle([$fromColumn, $fromRow, $toColumn, $toRow])->getBorders();

        if ($keepTopBorders) {
            // Jangan timpa garis batas kelas pada sheet Pareto.
            $borders->getLeft()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
            $borders->getRight()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
            $borders->getVertical()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
            $borders->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');

            return;
        }

        $borders->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
    }

    /**
     * Baris berselang warna supaya tabel lebar tetap mudah diikuti mata.
     *
     * @param  array<int, int>  $skip  Kolom yang sudah punya warna sendiri.
     */
    protected function zebra(Worksheet $sheet, int $fromRow, int $toRow, int $lastColumn, array $skip = []): void
    {
        for ($row = $fromRow + 1; $row <= $toRow; $row += 2) {
            foreach ($this->ranges($lastColumn, $skip) as [$start, $end]) {
                $sheet->getStyle([$start, $row, $end, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FAFAFA');
            }
        }
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    protected function ranges(int $lastColumn, array $skip): array
    {
        $ranges = [];
        $start = null;
        for ($column = 1; $column <= $lastColumn + 1; $column++) {
            $included = $column <= $lastColumn && ! in_array($column, $skip, true);
            if ($included && $start === null) {
                $start = $column;
            } elseif (! $included && $start !== null) {
                $ranges[] = [$start, $column - 1];
                $start = null;
            }
        }

        return $ranges;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3: int}>  $columns
     */
    protected function formatColumns(Worksheet $sheet, array $columns, int $fromRow, int $toRow): void
    {
        foreach ($columns as $index => [, , $format]) {
            $range = $sheet->getStyle([$index + 1, $fromRow, $index + 1, $toRow]);

            if ($format === self::DATE) {
                $range->getNumberFormat()->setFormatCode($format);
                $range->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            } elseif ($format !== null) {
                $range->getNumberFormat()->setFormatCode($format);
                $range->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        $this->widths($sheet, $columns);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3: int}>  $columns
     */
    protected function widths(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $index => [, , , $width]) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     */
    protected function paintClass(Worksheet $sheet, array $cell, string $class): void
    {
        [$fill, $text] = self::CLASS_COLORS[$class] ?? self::CLASS_COLORS[StockVelocity::NON_MOVING];
        $style = $sheet->getStyle($cell);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        $style->getFont()->setBold(true)->getColor()->setRGB($text);
    }

    protected function printSetup(Worksheet $sheet, bool $landscape, ?int $repeatRow = null): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setOrientation($landscape ? 'landscape' : 'portrait')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0);

        if ($repeatRow) {
            $setup->setRowsToRepeatAtTopByStartAndEnd($repeatRow, $repeatRow);
        }
    }

    protected function date(?string $value): ?float
    {
        return $value ? ExcelDate::PHPToExcel(Carbon::parse($value)->startOfDay()) : null;
    }
}
