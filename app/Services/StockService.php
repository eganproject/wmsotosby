<?php

namespace App\Services;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\ReturnReceipt;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat stok barang berubah.
 *
 * Setiap perubahan selalu meninggalkan jejak di stock_movements sehingga
 * kartu stok bisa direkonstruksi dan saldo bisa ditelusuri.
 */
class StockService
{
    /**
     * Posting barang masuk: stok bertambah.
     */
    public function postInbound(Inbound $inbound): void
    {
        if ($inbound->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diposting sebelumnya.',
            ]);
        }

        $inbound->loadMissing('items');

        if ($inbound->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Dokumen belum memiliki baris barang.',
            ]);
        }

        DB::transaction(function () use ($inbound) {
            foreach ($inbound->items as $item) {
                $this->move($item->product_id, 'in', $item->quantity, $inbound, "Barang masuk {$inbound->code}");
            }

            $inbound->forceFill([
                'status' => Inbound::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Posting barang keluar: stok berkurang.
     */
    public function postOutbound(Outbound $outbound): void
    {
        if ($outbound->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diproses sebelumnya.',
            ]);
        }

        $outbound->loadMissing('items.product');

        if ($blockers = $outbound->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        $this->guardAgainstNegativeStock($outbound->items);

        DB::transaction(function () use ($outbound) {
            foreach ($outbound->items as $item) {
                $this->move($item->product_id, 'out', $item->quantity, $outbound, "Barang keluar {$outbound->code}");
            }

            $outbound->forceFill([
                'status' => Outbound::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Terima retur: hanya barang berkondisi layak jual yang kembali ke stok.
     * Barang rusak tetap tercatat pada dokumen tetapi tidak menambah stok.
     */
    public function postReturn(ReturnReceipt $return): void
    {
        if ($return->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diterima sebelumnya.',
            ]);
        }

        $return->loadMissing('items');

        if ($blockers = $return->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($return) {
            foreach ($return->items as $item) {
                if ($item->good_quantity > 0) {
                    $this->move($item->product_id, 'in', $item->good_quantity, $return, "Penerimaan retur {$return->code}");
                }

                // Barang rusak tidak menambah stok layak jual, tetapi tetap
                // ada fisiknya di gudang — jadi masuk ke saldo rusak.
                if ($item->damaged_quantity > 0) {
                    $this->move(
                        $item->product_id, 'in', $item->damaged_quantity, $return,
                        "Retur rusak {$return->code}", StockMovement::BUCKET_DAMAGED,
                    );
                }
            }

            $return->forceFill([
                'status' => ReturnReceipt::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Terapkan penyesuaian stok: saldo disamakan dengan hasil hitung fisik.
     *
     * Selisih dihitung ulang terhadap saldo saat dokumen disetujui — bukan
     * saat disusun — agar hasil akhirnya benar-benar sama dengan angka yang
     * dihitung petugas. Selisih yang dibukukan disimpan pada barisnya.
     */
    public function postAdjustment(StockAdjustment $adjustment): void
    {
        if ($adjustment->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah disesuaikan sebelumnya.',
            ]);
        }

        $adjustment->loadMissing('items.product');

        if ($blockers = $adjustment->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($adjustment) {
            foreach ($adjustment->items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                $difference = $item->actual_quantity - $product->stock;

                if ($difference === 0) {
                    $item->update(['applied_difference' => 0]);

                    continue;
                }

                $this->move(
                    $product->id,
                    $difference > 0 ? 'in' : 'out',
                    abs($difference),
                    $adjustment,
                    "Penyesuaian stok {$adjustment->code} — {$adjustment->reason}",
                );

                $item->update(['applied_difference' => $difference]);
            }

            $adjustment->forceFill([
                'status' => StockAdjustment::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Selesaikan dokumen pengeluaran barang.
     *
     * Unit keluar dari saldo asal yang disebut dokumennya. Bila tindakannya
     * hanya memindahkan barang antar saldo — diperbaiki atau ditandai rusak —
     * unit yang sama langsung masuk ke saldo tujuan dalam transaksi yang sama,
     * sehingga tidak pernah ada saat di mana barangnya seolah lenyap.
     */
    public function postDisposal(DamagedDisposal $disposal): void
    {
        if ($disposal->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diproses sebelumnya.',
            ]);
        }

        $disposal->loadMissing('items.product');

        if ($blockers = $disposal->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($disposal) {
            $source = $disposal->sourceBucket();
            $target = $disposal->targetBucket();

            $leaving = $disposal->isFromDamaged()
                ? "Barang rusak {$disposal->code} — {$disposal->actionLabel()}"
                : "Pengeluaran barang {$disposal->code} — {$disposal->actionLabel()}";

            $arriving = match ($target) {
                StockMovement::BUCKET_GOOD => "Perbaikan barang rusak {$disposal->code}",
                StockMovement::BUCKET_DAMAGED => "Ditandai rusak {$disposal->code}",
                default => null,
            };

            foreach ($disposal->items as $item) {
                $this->move($item->product_id, 'out', $item->quantity, $disposal, $leaving, $source);

                if ($target !== null) {
                    $this->move($item->product_id, 'in', $item->quantity, $disposal, $arriving, $target);
                }
            }

            $disposal->forceFill([
                'status' => DamagedDisposal::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Terapkan hasil stok opname.
     *
     * Hanya baris yang benar-benar dihitung yang dibukukan; baris yang belum
     * disentuh petugas dibiarkan apa adanya, karena tidak dihitung bukan
     * berarti nol. Sama seperti penyesuaian, selisihnya dihitung ulang
     * terhadap saldo saat disetujui supaya hasil akhirnya persis sama dengan
     * angka yang dihitung di rak.
     */
    public function postOpname(StockOpname $opname): void
    {
        if ($opname->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Sesi opname ini sudah diterapkan sebelumnya.',
            ]);
        }

        $opname->loadMissing('items.product');

        if ($blockers = $opname->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($opname) {
            foreach ($opname->items as $item) {
                if (! $item->isCounted()) {
                    continue;
                }

                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                $difference = $item->counted_quantity - $product->stock;

                if ($difference === 0) {
                    $item->update(['applied_difference' => 0]);

                    continue;
                }

                $this->move(
                    $product->id,
                    $difference > 0 ? 'in' : 'out',
                    abs($difference),
                    $opname,
                    "Stok opname {$opname->code} — {$opname->scopeLabel()}",
                );

                $item->update(['applied_difference' => $difference]);
            }

            $opname->forceFill([
                'status' => StockOpname::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Setel stok ke angka tertentu, mis. saat import stok awal dari Excel.
     *
     * Selisihnya tetap dicatat sebagai pergerakan stok supaya kartu stok
     * bisa direkonsiliasi seperti transaksi lain.
     *
     * @return bool Apakah stok benar-benar berubah.
     */
    public function setStock(Product $product, int $target, ProductImport $reference, string $description): bool
    {
        if ($target < 0) {
            throw ValidationException::withMessages([
                'stock' => "Stok {$product->sku} tidak boleh bernilai negatif.",
            ]);
        }

        return DB::transaction(function () use ($product, $target, $reference, $description) {
            $locked = Product::lockForUpdate()->findOrFail($product->id);

            $difference = $target - $locked->stock;

            if ($difference === 0) {
                return false;
            }

            $this->move(
                $locked->id,
                $difference > 0 ? 'in' : 'out',
                abs($difference),
                $reference,
                $description,
            );

            return true;
        });
    }

    /**
     * Catat satu pergerakan stok dan perbarui saldo barang.
     *
     * Tiap barang punya dua saldo: layak jual dan rusak. Saldo mana yang
     * disentuh ditentukan $bucket, dan pergerakannya menyimpan itu juga
     * supaya kartu stok keduanya bisa direkonstruksi terpisah.
     */
    protected function move(
        int $productId,
        string $type,
        int $quantity,
        Inbound|Outbound|ReturnReceipt|ProductImport|StockAdjustment|StockOpname|DamagedDisposal $reference,
        string $description,
        string $bucket = StockMovement::BUCKET_GOOD,
    ): void {
        // Kunci baris agar dua dokumen tidak menghitung saldo dari angka yang sama.
        $product = Product::lockForUpdate()->findOrFail($productId);

        $column = $bucket === StockMovement::BUCKET_DAMAGED ? 'damaged_stock' : 'stock';
        $current = (int) $product->{$column};

        $balance = $type === 'in' ? $current + $quantity : $current - $quantity;

        if ($balance < 0) {
            throw ValidationException::withMessages([
                'items' => $bucket === StockMovement::BUCKET_DAMAGED
                    ? "Stok rusak {$product->name} tidak mencukupi (tersisa {$current} {$product->unit})."
                    : "Stok {$product->name} tidak mencukupi (tersisa {$current} {$product->unit}).",
            ]);
        }

        $product->forceFill([$column => $balance])->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'bucket' => $bucket,
            'quantity' => $quantity,
            'balance_after' => $balance,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'description' => $description,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Cek ketersediaan stok sebelum transaksi dimulai supaya pesan error
     * bisa menyebut semua barang yang bermasalah sekaligus.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\InboundItem|\App\Models\OutboundItem|\App\Models\ReturnReceiptItem>  $items
     */
    protected function guardAgainstNegativeStock($items): void
    {
        $errors = [];

        foreach ($items->groupBy('product_id') as $productId => $group) {
            $product = $group->first()->product ?? Product::find($productId);
            $needed = $group->sum('quantity');

            if ($product && $needed > $product->stock) {
                $errors[] = "Stok {$product->name} tidak mencukupi: butuh {$needed}, tersedia {$product->stock} {$product->unit}.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['items' => $errors]);
        }
    }
}
