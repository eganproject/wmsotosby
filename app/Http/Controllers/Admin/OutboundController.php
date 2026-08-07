<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOutboundRequest;
use App\Http\Requests\Admin\UpdateOutboundRequest;
use App\Models\Outbound;
use App\Models\Product;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OutboundController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.view', only: ['index', 'show']),
            new Middleware('can:outbounds.create', only: ['create', 'store']),
            new Middleware('can:outbounds.update', only: ['edit', 'update']),
            new Middleware('can:outbounds.delete', only: ['destroy']),
            new Middleware('can:outbounds.post', only: ['submit', 'withdraw']),
            new Middleware('can:outbounds.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $outbounds = Outbound::query()
            ->with('user')
            ->withCount('items')
            ->withSum('items', 'quantity')
            // Progres scan dinilai dari jumlahnya, jadi baris barang tidak
            // perlu dimuat satu per satu hanya untuk menampilkan badge.
            ->withSum('items', 'scanned_quantity')
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('marketplace'), fn ($query) => $query->where('marketplace', $request->string('marketplace')))
            ->dateBetween($request->input('from'), $request->input('to'))
            ->latest('date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.outbounds.index', [
            'outbounds' => $outbounds,
            'marketplaces' => Outbound::marketplaces(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.outbounds.create', [
            'outbound' => null,
            'code' => Outbound::nextCode(),
            'products' => $this->products(),
            'marketplaces' => Outbound::marketplaces(),
            'defaultType' => $request->input('type') === Outbound::TYPE_MARKETPLACE
                ? Outbound::TYPE_MARKETPLACE
                : Outbound::TYPE_REGULAR,
        ]);
    }

    public function store(StoreOutboundRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $outbound = DB::transaction(function () use ($data) {
            $outbound = Outbound::create([
                'code' => Outbound::nextCode(),
                'date' => $data['date'],
                'type' => $data['type'],
                'recipient' => $data['recipient'],
                'marketplace' => $data['marketplace'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => Outbound::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $outbound->items()->createMany($this->mergeLines($data['items'] ?? []));

            return $outbound;
        });

        // Pengiriman marketplace wajib lewat verifikasi scan lebih dulu.
        if ($outbound->isMarketplace()) {
            return redirect()
                ->route('admin.outbounds.scan', $outbound)
                ->with('success', "Dokumen {$outbound->code} dibuat. Lanjutkan dengan scan resi dan barang.");
        }

        if ($request->boolean('submit')) {
            return $this->submit($request, $outbound)
                ->setTargetUrl(route('admin.outbounds.show', $outbound));
        }

        return redirect()
            ->route('admin.outbounds.show', $outbound)
            ->with('success', "Draft {$outbound->code} berhasil disimpan.");
    }

    public function show(Outbound $outbound): View
    {
        $outbound->load(['items.product', 'user']);

        return view('admin.outbounds.show', compact('outbound'));
    }

    public function edit(Outbound $outbound): View|RedirectResponse
    {
        if (! $outbound->isEditable()) {
            return redirect()
                ->route('admin.outbounds.show', $outbound)
                ->with('error', $outbound->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        return view('admin.outbounds.edit', [
            'outbound' => $outbound->load('items.product'),
            'code' => $outbound->code,
            'products' => $this->products(),
            'marketplaces' => Outbound::marketplaces(),
            'defaultType' => $outbound->type,
        ]);
    }

    public function update(UpdateOutboundRequest $request, Outbound $outbound): RedirectResponse
    {
        if (! $outbound->isEditable()) {
            return back()->with('error', $outbound->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        $data = $request->validated();
        $trackingChanged = ($data['tracking_number'] ?? null) !== $outbound->tracking_number;

        DB::transaction(function () use ($outbound, $data, $trackingChanged) {
            $outbound->update([
                'date' => $data['date'],
                'type' => $data['type'],
                'recipient' => $data['recipient'],
                'marketplace' => $data['marketplace'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $outbound->items()->delete();
            $outbound->items()->createMany($this->mergeLines($data['items'] ?? []));

            // Isi dokumen berubah, jadi hasil scan sebelumnya tidak lagi sahih.
            if ($trackingChanged || $outbound->isMarketplace()) {
                $outbound->forceFill(['resi_verified_at' => null])->save();
            }
        });

        if ($outbound->refresh()->isMarketplace()) {
            return redirect()
                ->route('admin.outbounds.scan', $outbound)
                ->with('success', "Dokumen {$outbound->code} diperbarui. Verifikasi scan diulang dari awal.");
        }

        return redirect()
            ->route('admin.outbounds.show', $outbound)
            ->with('success', "Dokumen {$outbound->code} berhasil diperbarui.");
    }

    /**
     * Ajukan dokumen untuk disetujui. Pengguna yang berwenang menyetujui
     * langsung diproses tanpa perlu antre.
     */
    public function submit(Request $request, Outbound $outbound): RedirectResponse
    {
        $outbound->load('items.product');

        $selfApprove = $request->user()->can('outbounds.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($outbound)
                : $this->approvals->submit($outbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Barang keluar {$outbound->code} disetujui. Stok telah berkurang."
            : "Barang keluar {$outbound->code} diajukan dan menunggu persetujuan.");
    }

    /**
     * Setujui dokumen yang sedang diajukan.
     */
    public function approve(Outbound $outbound): RedirectResponse
    {
        $outbound->load('items.product');

        try {
            $this->approvals->approve($outbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Barang keluar {$outbound->code} disetujui. Stok telah berkurang.");
    }

    /**
     * Tarik kembali pengajuan menjadi draft.
     */
    public function withdraw(Outbound $outbound): RedirectResponse
    {
        try {
            $this->approvals->withdraw($outbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$outbound->code} ditarik kembali ke draft.");
    }

    public function destroy(Outbound $outbound): RedirectResponse
    {
        if (! $outbound->isEditable()) {
            return back()->with('error', $outbound->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $outbound->delete();

        return redirect()
            ->route('admin.outbounds.index')
            ->with('success', 'Dokumen barang keluar berhasil dihapus.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function mergeLines(array $lines): array
    {
        return collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group, $productId) => [
                'product_id' => (int) $productId,
                'quantity' => (int) $group->sum('quantity'),
                'scanned_quantity' => 0,
                'note' => $group->pluck('note')->filter()->implode(', ') ?: null,
            ])
            ->values()
            ->all();
    }

    protected function products()
    {
        return Product::where('is_active', true)->orderBy('name')->get();
    }
}
