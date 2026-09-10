<?php

namespace App\Services;

use App\Support\DailyWaybillReportFilters;
use App\Support\DailyWaybillReportRow;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Export rekap resi per hari tanpa mengubah data sumber. */
class DailyWaybillReportExportService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $columns = [
        ['Tanggal Masuk', 'date'],
        ['Total Resi', 'total'],
        ['Total Unit', 'units'],
        ['Jumlah Ekspedisi', 'couriers'],
        ['Belum QC', 'awaiting'],
        ['Siap Dikirim', 'checked'],
        ['Dikirim', 'shipped'],
        ['Dibatalkan', 'cancelled'],
        ['Kesiapan (%)', 'readiness'],
    ];

    /**
     * @param  Collection<int, DailyWaybillReportRow>  $rows
     */
    public function download(
        Collection $rows,
        DailyWaybillReportFilters $filters,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Resi per Hari');

        $sheet->setCellValue('A1', 'LAPORAN RESI PER HARI');
        $sheet->setCellValue('A2', sprintf(
            '%s — tanggal masuk %s · diunduh %s',
            config('app.name'),
            $filters->label(),
            now()->translatedFormat('d F Y H:i'),
        ));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }

        $rowNumber = 5;

        foreach ($rows as $row) {
            foreach ($this->columns as $index => [, $field]) {
                $sheet->setCellValue([$index + 1, $rowNumber], $this->value($row, $field));
            }

            $rowNumber++;
        }

        $this->style($sheet, $rowNumber - 1);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    protected function value(DailyWaybillReportRow $row, string $field): string|int
    {
        return match ($field) {
            'date' => $row->date,
            'total' => $row->total,
            'units' => $row->units,
            'couriers' => $row->couriers,
            'awaiting' => $row->awaiting,
            'checked' => $row->checked,
            'shipped' => $row->shipped,
            'cancelled' => $row->cancelled,
            'readiness' => $row->readiness(),
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

            $sheet->getStyle([2, $headerRow + 1, $lastColumn, $lastRow])
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $sheet->setAutoFilter([1, $headerRow, $lastColumn, max($lastRow, $headerRow)]);
        $sheet->freezePane('A5');
    }
}
