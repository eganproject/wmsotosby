<?php

namespace App\Console\Commands;

use App\Models\Outbound;
use App\Models\ShipmentOrder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tandai resi yang status pesanannya sudah menyebut batal.
 *
 * Status pesanan sudah ikut tersimpan sejak import pertama, tetapi dulu tidak
 * pernah dibaca. Import berikutnya akan membacanya sendiri — perintah ini untuk
 * data yang sudah telanjur masuk, supaya tidak perlu menunggu berkas terbaru.
 *
 * Tanpa --apply perintah ini tidak menulis apa pun. Ini menyangkut data
 * produksi, dan menandai resi batal berarti paketnya tidak bisa lagi discan
 * maupun dikirim — keputusan seperti itu diambil setelah melihat, bukan
 * sebelum.
 */
class FlagCancelledWaybills extends Command
{
    protected $signature = 'waybills:flag-cancelled
                            {--apply : Tulis perubahannya. Tanpa ini hanya ditampilkan.}';

    protected $description = 'Tandai resi batal dari status pesanan yang sudah tersimpan di data import';

    public function handle(): int
    {
        $this->showStatuses();

        $pending = $this->pending();
        $total = $pending->count();

        if ($total === 0) {
            $this->components->info('Tidak ada resi yang perlu ditandai. Data Anda sudah sesuai.');

            return self::SUCCESS;
        }

        $this->showAffected($pending, $total);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('Belum ada yang diubah. Jalankan ulang dengan --apply bila daftar di atas sudah benar.');

            return self::SUCCESS;
        }

        $changed = $pending->update([
            'cancelled_at' => now(),
            // Kosong berarti terbaca dari data import, bukan ditandai orang —
            // sama seperti pembatalan yang datang lewat berkas.
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ]);

        $this->components->info("{$changed} resi ditandai batal. Paketnya tidak bisa lagi discan maupun dikirim.");

        return self::SUCCESS;
    }

    /**
     * Seluruh status yang ada beserta jumlahnya, supaya keputusan diambil
     * dengan melihat data sendiri — bukan mempercayai daftar kata di kode.
     */
    protected function showStatuses(): void
    {
        $rows = DB::table('shipment_orders')
            ->selectRaw('order_status, COUNT(*) as jumlah')
            ->groupBy('order_status')
            ->orderByDesc('jumlah')
            ->get()
            ->map(fn ($row) => [
                $row->order_status ?: '(kosong)',
                $row->jumlah,
                ShipmentOrder::looksCancelled($row->order_status) ? 'BATAL' : '—',
            ]);

        if ($rows->isEmpty()) {
            $this->components->info('Belum ada data resi hasil import.');

            return;
        }

        $this->newLine();
        $this->line('Status pesanan yang tersimpan:');
        $this->table(['Status', 'Jumlah', 'Terbaca'], $rows);
    }

    /**
     * Resi yang statusnya menyebut batal tetapi belum ditandai.
     */
    protected function pending(): Builder
    {
        $query = ShipmentOrder::query()->whereNull('cancelled_at');

        $query->where(function (Builder $query) {
            foreach (ShipmentOrder::CANCELLED_MARKERS as $marker) {
                $query->orWhere('order_status', 'like', "%{$marker}%");
            }
        });

        return $query;
    }

    protected function showAffected(Builder $pending, int $total): void
    {
        // Yang sudah berangkat tetap ditandai — pembatalannya memang terjadi —
        // tetapi tahapnya tetap terbaca "Dikirim", dan itu perlu disebutkan
        // supaya angkanya tidak terlihat mengkhawatirkan.
        $shipped = (clone $pending)
            ->whereHas('outbounds', fn (Builder $outbound) => $outbound
                ->where('status', Outbound::STATUS_POSTED))
            ->count();

        $this->newLine();
        $this->line("Akan ditandai batal: <fg=yellow>{$total}</> resi.");

        if ($shipped > 0) {
            $this->line("  Di antaranya <fg=green>{$shipped}</> sudah telanjur dikirim — tahapnya tetap \"Dikirim\",");
            $this->line('  dan pengembaliannya dicatat lewat penerimaan retur seperti biasa.');
        }

        $sample = (clone $pending)
            ->latest('id')
            ->take(10)
            ->get(['tracking_number', 'order_number', 'order_status']);

        $this->table(
            ['Resi', 'Pesanan', 'Status'],
            $sample->map(fn (ShipmentOrder $order) => [
                $order->tracking_number,
                $order->order_number ?: '—',
                $order->order_status,
            ]),
        );

        if ($total > $sample->count()) {
            $this->line('  … dan '.($total - $sample->count()).' lainnya.');
        }
    }
}
