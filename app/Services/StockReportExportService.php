<?php

namespace App\Services;

use App\Support\StockReportFilters;
use App\Support\StockReportRow;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export laporan stok ke Excel.
 *
 * Angka turunan ditulis sebagai angka, bukan teks berformat: perputaran dan
 * perkiraan habis justru paling sering dipakai untuk diurutkan dan disaring
 * lagi di spreadsheet, dan itu mustahil kalau isinya "12 hari".
 */
class StockReportExportService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $columns = [
        ['SKU', 'sku'],
        ['Nama Barang', 'name'],
        ['Kategori', 'category'],
        ['Satuan', 'unit'],
        ['Stok Awal', 'opening'],
        ['Masuk', 'incoming'],
        ['Keluar', 'outgoing'],
        ['Stok Akhir', 'closing'],
        ['Rata-rata Keluar/Hari', 'per_day'],
        ['Perputaran (kali)', 'turnover'],
        ['Perkiraan Habis (hari)', 'cover'],
        ['Terakhir Keluar', 'last_out'],
        ['Stok Minimum', 'min_stock'],
        ['Stok Rusak', 'damaged'],
        ['Status', 'status'],
    ];

    public function download(StockReportService $report, StockReportFilters $filters, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Stok');

        $this->writeHeading($sheet, $filters);
        $lastRow = $this->writeRows($sheet, $report, $filters);
        $this->style($sheet, $lastRow);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    protected function writeHeading($sheet, StockReportFilters $filters): void
    {
        $sheet->setCellValue('A1', 'LAPORAN STOK & PERPUTARAN BARANG');
        $sheet->setCellValue('A2', sprintf(
            '%s — periode %s (%d hari) · %s · diunduh %s',
            config('app.name'),
            $filters->label(),
            $filters->days(),
            $filters->viewLabel(),
            now()->translatedFormat('d F Y H:i'),
        ));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }
    }

    protected function writeRows($sheet, StockReportService $report, StockReportFilters $filters): int
    {
        $row = 5;

        foreach ($report->lazy($filters) as $line) {
            foreach ($this->columns as $index => [, $field]) {
                $sheet->setCellValue([$index + 1, $row], $this->value($line, $field));
            }

            $row++;
        }

        return $row - 1;
    }

    protected function value(StockReportRow $line, string $field): string|int|float
    {
        return match ($field) {
            'sku' => $line->sku,
            'name' => $line->name,
            'category' => $line->category ?? '-',
            'unit' => $line->unit,
            'opening' => $line->opening,
            'incoming' => $line->incoming,
            'outgoing' => $line->outgoing,
            'closing' => $line->closing,
            'per_day' => round($line->perDay(), 2),
            'turnover' => $line->turnover() === null ? '-' : round($line->turnover(), 2),
            'cover' => $line->daysOfCover() === null ? '-' : round($line->daysOfCover(), 1),
            'last_out' => $line->lastOutAt ? substr($line->lastOutAt, 0, 10) : '-',
            'min_stock' => $line->minStock,
            'damaged' => $line->damaged,
            'status' => $line->urgencyBadge()['label'],
            default => '',
        };
    }

    protected function style($sheet, int $lastRow): void
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

            // Seluruh kolom angka, dari stok awal sampai stok rusak.
            foreach (range(5, 14) as $column) {
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
