<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Antrean paket siap kirim.
 *
 * Stasiun packing hanya memastikan isi paket sesuai pesanan, lalu langsung
 * lanjut ke resi berikutnya. Keputusan mengirim — yang memindahkan stok —
 * dikerjakan terpisah dari sini, sekaligus untuk banyak paket.
 */
class OutboundDispatchController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.view', only: ['index']),
            new Middleware('can:outbounds.post', only: ['process', 'scan']),
        ];
    }

    public function index(Request $request): View
    {
        $outbounds = Outbound::readyToShip()
            ->with('user')
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('marketplace'), fn ($query) => $query->where('marketplace', $request->string('marketplace')))
            // Paket yang paling lama menunggu dikerjakan lebih dulu.
            ->oldest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.outbounds.ready', [
            'outbounds' => $outbounds,
            'marketplaces' => Outbound::marketplaces(),
            // Sengaja tanpa filter: memindai resi bekerja atas seluruh antrean,
            // bukan hanya atas yang kebetulan tampil di layar.
            'readyCount' => Outbound::readyToShip()->count(),
        ]);
    }

    /**
     * Proses paket terpilih. Satu paket yang gagal tidak menggagalkan sisanya —
     * hasilnya dilaporkan per dokumen supaya jelas mana yang perlu ditangani.
     */
    public function process(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $documents = Outbound::readyToShip()
            ->whereIn('id', $ids)
            ->with('items.product')
            ->get();

        if ($documents->isEmpty()) {
            return $this->backToQueue($request)
                ->with('error', 'Tidak ada paket yang bisa diproses. Mungkin sudah dikerjakan orang lain.');
        }

        $selfApprove = $request->user()->can('outbounds.approve');

        $done = [];
        $failed = [];

        foreach ($documents as $outbound) {
            try {
                $selfApprove
                    ? $this->approvals->submitAndApprove($outbound)
                    : $this->approvals->submit($outbound);

                $done[] = $outbound->code;
            } catch (ValidationException $exception) {
                $failed[] = $outbound->code.' — '.collect($exception->errors())->flatten()->implode(' ');
            }
        }

        return $this->backToQueue($request)
            ->with('success', $done ? $this->summary($done, $selfApprove) : null)
            ->with('error', $failed
                ? count($failed).' paket gagal diproses. '.implode(' | ', $failed)
                : null);
    }

    /**
     * Kirim satu paket dengan memindai resinya.
     *
     * Dipakai di titik serah ke kurir: paket diangkat, resinya discan, dan
     * layar langsung siap untuk paket berikutnya. Mencentang daftar tetap ada
     * untuk memproses borongan, tetapi memindai punya keunggulan yang tidak
     * bisa ditiru centang — yang tercatat terkirim adalah paket yang benar-
     * benar ada di tangan, bukan baris yang kebetulan tercentang di layar.
     */
    public function scan(Request $request): JsonResponse
    {
        $code = trim($request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code']);

        $outbound = Outbound::findByScannedCode($code);

        if (! $outbound) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak dikenali · belum discan di packing',
            ]);
        }

        $outbound->load('items.product');

        $this->guardDispatchable($outbound);

        $selfApprove = $request->user()->can('outbounds.approve');
        $units = (int) $outbound->items->sum('quantity');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($outbound)
                : $this->approvals->submit($outbound);
        } catch (ValidationException $exception) {
            // Stok bisa saja sudah diambil paket lain sejak antrean disusun.
            throw ValidationException::withMessages([
                'code' => collect($exception->errors())->flatten()->implode(' '),
            ]);
        }

        return response()->json([
            'outbound' => [
                'id' => $outbound->id,
                'code' => $outbound->code,
                'tracking_number' => $outbound->tracking_number,
                'marketplace' => $outbound->marketplace,
                'recipient' => $outbound->recipient,
                'units' => $units,
            ],
            'shipped' => $selfApprove,
            'message' => $selfApprove
                ? "Dikirim · {$units} unit"
                : 'Diajukan · menunggu persetujuan',
            'remaining' => Outbound::readyToShip()->count(),
        ]);
    }

    /**
     * Tolak paket yang belum boleh dikirim, dengan alasan yang menyebutkan
     * tindakan berikutnya — bukan sekadar "tidak bisa".
     *
     * Nomor dokumen hanya disebut saat memang membantu mengenali paketnya.
     * Pada paket yang QC-nya belum selesai, operator memegang barangnya dan
     * yang perlu ia tahu adalah berapa unit yang kurang.
     */
    protected function guardDispatchable(Outbound $outbound): void
    {
        $reason = match (true) {
            $outbound->isPosted() => "Sudah dikirim · {$outbound->code}",
            $outbound->isPending() => "Menunggu persetujuan · {$outbound->code}",
            $outbound->isRejected() => "Ditolak penyetuju · {$outbound->code}",
            ! $outbound->isMarketplace() => "Bukan paket marketplace · proses dari dokumen {$outbound->code}",
            ! $outbound->isResiVerified() => 'Belum discan di packing',
            ! $outbound->isFullyScanned() => 'Belum selesai QC · sisa '
                .($outbound->totalQuantity() - $outbound->totalScanned()).' unit',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['code' => $reason]);
        }
    }

    /**
     * Kembali ke antrean dengan filter yang tadi dipakai.
     *
     * Sengaja tidak memakai `back()`: fungsi itu membaca header Referer, yang
     * tidak selalu dikirim, dan cadangannya di sesi tidak pernah diperbarui
     * pada navigasi AJAX. Filternya dibawa sendiri oleh form, jadi tujuannya
     * pasti — operator yang menyaring satu marketplace tidak terlempar ke
     * seluruh antrean setelah memproses.
     */
    protected function backToQueue(Request $request): RedirectResponse
    {
        return redirect()->route('admin.outbounds.ready', array_filter(
            $request->only(['search', 'marketplace']),
            fn ($value) => filled($value),
        ));
    }

    /**
     * @param  array<int, string>  $codes
     */
    protected function summary(array $codes, bool $selfApprove): string
    {
        $count = count($codes);

        $subject = $count === 1
            ? "Paket {$codes[0]}"
            : "{$count} paket";

        return $selfApprove
            ? "{$subject} dikirim. Stok sudah berkurang."
            : "{$subject} diajukan dan menunggu persetujuan.";
    }
}
