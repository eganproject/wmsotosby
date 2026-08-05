<?php

namespace App\Support;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\ReturnReceipt;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;

/**
 * Jumlah dokumen yang menunggu persetujuan, dipakai badge di menu samping.
 *
 * Seluruh jenis dokumen dihitung dalam satu query gabungan — badge ini muncul
 * di setiap halaman, jadi satu perjalanan terpisah per jenis dokumen tidak
 * sepadan dengan satu angka kecil.
 */
class ApprovalCounter
{
    public static function pending(): int
    {
        $queries = collect([Inbound::class, Outbound::class, ReturnReceipt::class, StockAdjustment::class, StockOpname::class, DamagedDisposal::class])
            ->map(fn (string $model) => $model::pending()->toBase()->selectRaw('1 as pending'));

        $union = $queries->shift();

        $queries->each(fn ($query) => $union->unionAll($query));

        return (int) DB::query()->fromSub($union, 'pending_documents')->count();
    }
}
