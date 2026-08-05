<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export data stok ke berkas Excel.
 *
 * Isinya mengikuti filter yang sedang aktif di halaman stok, sehingga yang
 * diunduh sama persis dengan yang dilihat pengguna.
 */
class ProductExportService
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    protected array $columns = [
        ['SKU', 'sku', 'text'],
        ['Barcode', 'barcode', 'text'],
        ['Nama Barang', 'name', 'text'],
        ['Kategori', 'category', 'text'],
        ['Satuan', 'unit', 'text'],
        ['Lokasi Rak', 'location', 'text'],
        ['Stok', 'stock', 'number'],
        ['Stok Minimum', 'min_stock', 'number'],
        ['Status Stok', 'stock_status', 'text'],
        ['Status Barang', 'active_status', 'text'],
    ];

    public function download(Builder $query, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stok Barang');

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
        $sheet->setCellValue('A1', 'LAPORAN STOK BARANG');
        $sheet->setCellValue('A2', config('app.name').' — diunduh '.now()->translatedFormat('d F Y H:i'));

        foreach ($this->columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, 4], $label);
        }
    }

    protected function writeRows($sheet, Builder $query): int
    {
        $row = 5;

        // Dipotong per 500 baris agar aman untuk data besar.
        $query->orderBy('name')->chunk(500, function ($products) use ($sheet, &$row) {
            foreach ($products as $product) {
                foreach ($this->columns as $index => [, $field]) {
                    $sheet->setCellValue([$index + 1, $row], $this->value($product, $field));
                }

                $row++;
            }
        });

        return $row - 1;
    }

    protected function value(Product $product, string $field): string|int
    {
        return match ($field) {
            'stock_status' => ucfirst($product->stockStatus()),
            'active_status' => $product->is_active ? 'Aktif' : 'Nonaktif',
            'stock', 'min_stock' => (int) $product->{$field},
            default => (string) ($product->{$field} ?? '-'),
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

        // Judul kolom dibuat kontras agar mudah dibaca saat dicetak.
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

            // Kolom angka dirata kanan.
            foreach ([7, 8] as $column) {
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
