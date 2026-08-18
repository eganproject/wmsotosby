<?php

namespace App\Support;

/**
 * Hasil pemecahan paket: baris barang nyata, berikut paket asalnya.
 *
 * Keduanya dibawa bersama karena keduanya harus ditulis bersama. Menulis
 * salah satunya saja menghasilkan dokumen yang stoknya benar tetapi tidak
 * bisa menjelaskan dirinya, atau sebaliknya.
 */
final class BundledLines
{
    /**
     * @param  array<int, array<string, mixed>>  $items  satu baris per barang, sudah digabung
     * @param  array<int, array<string, mixed>>  $bundles  paket yang menghasilkan baris di atas
     */
    public function __construct(
        public readonly array $items,
        public readonly array $bundles,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function hasBundles(): bool
    {
        return $this->bundles !== [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Unit barang yang harus benar-benar turun dari rak.
     */
    public function totalUnits(): int
    {
        return (int) array_sum(array_column($this->items, 'quantity'));
    }

    /**
     * Sidik jari isi dokumen, untuk membandingkan dua susunan baris.
     *
     * Dipakai saat resi discan ulang: bila isinya sama persis, baris dokumen
     * tidak perlu ditulis ulang — dan hasil scan yang sudah ada tidak perlu
     * hilang. Paket ikut dihitung supaya dokumen lama yang barisnya kebetulan
     * sama tetapi belum punya catatan paket tetap terbaca sebagai berbeda.
     */
    public function signature(): string
    {
        $items = collect($this->items)
            ->map(fn (array $line) => $line['product_id'].'x'.$line['quantity'])
            ->sort()
            ->values()
            ->implode('|');

        $bundles = collect($this->bundles)
            ->map(fn (array $line) => $line['bundle_id'].'x'.$line['quantity'])
            ->sort()
            ->values()
            ->implode('|');

        return $items.'#'.$bundles;
    }
}
