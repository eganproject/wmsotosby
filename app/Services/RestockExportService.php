<?php

namespace App\Services;

use App\Support\RestockFilters;
use App\Support\RestockRow;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export kebutuhan restock ke Excel.
 *
 * Berkas ini biasanya berakhir sebagai daftar pesanan yang dikirim ke pemasok,
 * jadi alasan tiap angka ikut dituliskan — penerima berkas tidak punya layar
 * yang menjelaskan dari mana saran jumlahnya berasal.
 */
class RestockExportService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $columns = [
        ['SKU', 'sku'],
        ['Nama Barang', 'name'],
        ['Kategori', 'category'],
        ['Lokasi', 'location'],
        ['Satuan', 'unit'],
        ['Stok', 'stock'],
        ['Terikat Pesanan', 'committed'],
        ['Tersedia', 'available'],
        ['Batas Menipis', 'min_stock'],
        ['Keluar/Hari', 'per_day'],
        ['Perkiraan Habis (hari)', 'cover'],
        ['Saran Pesan', 'suggested'],
        ['Alasan', 'reason'],
        ['Stok Rusak', 'damaged'],
    ];

    public function download(RestockReportService $report, RestockFilters $filters, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kebutuhan Restock');

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

    protected function writeHeading($sheet, RestockFilters $filters): void
    {
        $sheet->setCellValue('A1', 'KEBUTUHAN RESTOCK');
        $sheet->setCellValue('A2', sprintf(
            '%s — laju keluar %s (%d hari) · disiapkan untuk %d hari ke depan · %s · diunduh %s',
            config('app.name'),
            $filters->label(),
            $filters->days(),
            $filters->coverDays,
            $filters->viewLabel(),
            now()->translatedFormat('d F Y H:i'),
        ));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }
    }

    protected function writeRows($sheet, RestockReportService $report, RestockFilters $filters): int
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

    protected function value(RestockRow $line, string $field): string|int|float
    {
        return match ($field) {
            'sku' => $line->sku,
            'name' => $line->name,
            'category' => $line->category ?? '-',
            'location' => $line->location ?? '-',
            'unit' => $line->unit,
            'stock' => $line->stock,
            'committed' => $line->committed,
            'available' => $line->available(),
            'min_stock' => $line->minStock,
            'per_day' => round($line->perDay(), 2),
            'cover' => $line->daysOfCover() === null ? '-' : round($line->daysOfCover(), 1),
            'suggested' => $line->suggested(),
            'reason' => $line->reason(),
            'damaged' => $line->damaged,
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

            // Kolom angka, dari stok sampai saran pesan.
            foreach (range(6, 12) as $column) {
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
