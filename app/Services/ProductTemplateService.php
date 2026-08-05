<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas template untuk import barang.
 *
 * Isinya berupa judul kolom, dua baris contoh, dan satu lembar keterangan
 * agar pengguna tidak perlu menebak format yang diminta.
 */
class ProductTemplateService
{
    /**
     * @var array<int, array<int, string|int>>
     */
    protected array $examples = [
        ['FLT-OLI-STD', 'Filter Oli Standar', '8991234500035', 'Filter', 'pcs', 'A-02-01', 15, 40],
        ['BSI-IRIDIUM', 'Busi Iridium', '8991234500073', 'Kelistrikan', 'pcs', 'B-02-01', 24, 120],
    ];

    /**
     * @var array<int, array<int, string>>
     */
    protected array $notes = [
        ['Kolom', 'Wajib', 'Keterangan'],
        ['SKU', 'Ya', 'Kode unik barang. SKU yang sudah ada akan diperbarui, bukan diduplikat.'],
        ['Nama Barang', 'Ya', 'Nama barang seperti yang dipakai sehari-hari.'],
        ['Barcode', 'Tidak', 'Kode yang dibaca scanner. Boleh dikosongkan — scan tetap bisa memakai SKU.'],
        ['Kategori', 'Tidak', 'Contoh: Filter, Pelumas, Kelistrikan.'],
        ['Satuan', 'Tidak', 'Contoh: pcs, box, set, botol. Kosong dianggap pcs.'],
        ['Lokasi Rak', 'Tidak', 'Contoh: A-02-01.'],
        ['Stok Minimum', 'Tidak', 'Batas peringatan stok menipis. Kosong dianggap 0.'],
        ['Stok', 'Tidak', 'Stok saat ini. Selisihnya dicatat otomatis di kartu stok.'],
        ['', '', ''],
        ['Catatan', '', 'Judul kolom berlatar hitam wajib diisi, yang berlatar abu-abu boleh dikosongkan.'],
        ['', '', 'Hapus dua baris contoh sebelum berkas diimport.'],
    ];

    public function download(string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $this->buildDataSheet($spreadsheet);
        $this->buildNotesSheet($spreadsheet);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    protected function buildDataSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Barang');

        $fields = array_keys(ProductImportService::TEMPLATE_COLUMNS);
        $columns = array_values(ProductImportService::TEMPLATE_COLUMNS);
        $lastColumn = count($columns);

        foreach ($columns as $index => $label) {
            $sheet->setCellValue([$index + 1, 1], $label);
        }

        foreach ($this->examples as $rowIndex => $example) {
            foreach ($example as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 2], $value);
            }
        }

        // Kolom wajib diberi judul hitam, kolom opsional abu-abu, supaya
        // terlihat mana yang harus diisi tanpa membuka lembar petunjuk.
        foreach ($fields as $index => $field) {
            $required = in_array($field, ProductImportService::REQUIRED_COLUMNS, true);

            $style = $sheet->getStyle([$index + 1, 1, $index + 1, 1]);
            $style->getFont()->setBold(true)->getColor()->setRGB($required ? 'FFFFFF' : '454545');
            $style->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($required ? '0A0A0A' : 'E7E7E7');
            $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        $sheet->getRowDimension(1)->setRowHeight(22);

        // Baris contoh dibuat abu-abu agar jelas harus dihapus.
        $examples = $sheet->getStyle([1, 2, $lastColumn, 1 + count($this->examples)]);
        $examples->getFont()->getColor()->setRGB('8A8A8A');
        $examples->getFont()->setItalic(true);

        $sheet->getStyle([1, 1, $lastColumn, 1 + count($this->examples)])
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('D1D1D1');

        // SKU dan barcode ditulis apa adanya supaya nol di depan tidak hilang.
        foreach ([1, 3] as $column) {
            $sheet->getStyle([$column, 1, $column, 200])
                ->getNumberFormat()->setFormatCode('@');
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $sheet->freezePane('A2');
    }

    protected function buildNotesSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        foreach ($this->notes as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $header = $sheet->getStyle([1, 1, 3, 1]);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');

        $sheet->getStyle([1, 1, 3, count($this->notes)])
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(70);
    }
}
