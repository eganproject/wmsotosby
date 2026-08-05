<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\ApprovalService;
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
            new Middleware('can:outbounds.post', only: ['process']),
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
            return back()->with('error', 'Tidak ada paket yang bisa diproses. Mungkin sudah dikerjakan orang lain.');
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

        return back()
            ->with('success', $done ? $this->summary($done, $selfApprove) : null)
            ->with('error', $failed
                ? count($failed).' paket gagal diproses. '.implode(' | ', $failed)
                : null);
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
