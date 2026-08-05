<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export mutasi stok ke Excel.
 *
 * Masuk dan keluar sengaja dipisah menjadi dua kolom angka, bukan satu kolom
 * bertanda, supaya bisa langsung dijumlahkan di spreadsheet tanpa diolah lagi.
 */
class StockMovementExportService
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $columns = [
        ['Tanggal', 'date'],
        ['Waktu', 'time'],
        ['SKU', 'sku'],
        ['Nama Barang', 'product'],
        ['Satuan', 'unit'],
        ['Masuk', 'incoming'],
        ['Keluar', 'outgoing'],
        ['Saldo Setelah', 'balance_after'],
        ['Sumber', 'source'],
        ['Keterangan', 'description'],
        ['Oleh', 'user'],
    ];

    public function download(Builder $query, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mutasi Stok');

        $this->writeHeading($sheet);
        $lastRow = $this->writeRows($sheet, $query);
        $this->style($sheet, $lastRow);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    protected function writeHeading($sheet): void
    {
        $sheet->setCellValue('A1', 'LAPORAN MUTASI STOK');
        $sheet->setCellValue('A2', config('app.name').' — diunduh '.now()->translatedFormat('d F Y H:i'));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }
    }

    protected function writeRows($sheet, Builder $query): int
    {
        $row = 5;

        // Urutan tertua ke terbaru: saldo pada tiap baris jadi bisa dibaca
        // sebagai kartu stok yang berjalan.
        $query->oldest('created_at')->oldest('id')->chunk(500, function ($movements) use ($sheet, &$row) {
            foreach ($movements as $movement) {
                foreach ($this->columns as $index => [, $field]) {
                    $sheet->setCellValue([$index + 1, $row], $this->value($movement, $field));
                }

                $row++;
            }
        });

        return $row - 1;
    }

    protected function value(StockMovement $movement, string $field): string|int
    {
        return match ($field) {
            'date' => $movement->created_at->format('d/m/Y'),
            'time' => $movement->created_at->format('H:i'),
            'sku' => (string) ($movement->product?->sku ?? '-'),
            'product' => (string) ($movement->product?->name ?? '-'),
            'unit' => (string) ($movement->product?->unit ?? '-'),
            'incoming' => $movement->isIncoming() ? (int) $movement->quantity : 0,
            'outgoing' => $movement->isIncoming() ? 0 : (int) $movement->quantity,
            'balance_after' => (int) $movement->balance_after,
            'source' => $movement->sourceLabel(),
            'description' => (string) ($movement->description ?? '-'),
            'user' => (string) ($movement->user?->name ?? '-'),
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
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        if ($lastRow > $headerRow) {
            $sheet->getStyle([1, $headerRow, $lastColumn, $lastRow])
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setRGB('D1D1D1');

            // Kolom angka: masuk, keluar, saldo.
            foreach ([6, 7, 8] as $column) {
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
