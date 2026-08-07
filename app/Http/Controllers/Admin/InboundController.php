<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInboundRequest;
use App\Http\Requests\Admin\UpdateInboundRequest;
use App\Models\Inbound;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InboundController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:inbounds.view', only: ['index', 'show']),
            new Middleware('can:inbounds.create', only: ['create', 'store']),
            new Middleware('can:inbounds.update', only: ['edit', 'update']),
            new Middleware('can:inbounds.delete', only: ['destroy']),
            new Middleware('can:inbounds.post', only: ['submit', 'withdraw']),
            new Middleware('can:inbounds.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $inbounds = Inbound::query()
            // Nama pemasok tampil di setiap baris tabel.
            ->with(['user', 'supplier'])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->dateBetween($request->input('from'), $request->input('to'))
            ->latest('date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.inbounds.index', compact('inbounds'));
    }

    public function create(): View
    {
        return view('admin.inbounds.create', [
            'inbound' => null,
            'code' => Inbound::nextCode(),
            'products' => $this->products(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function store(StoreInboundRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $inbound = DB::transaction(function () use ($data) {
            $inbound = Inbound::create([
                'code' => Inbound::nextCode(),
                'date' => $data['date'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => Inbound::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $inbound->items()->createMany($this->mergeLines($data['items'] ?? []));

            return $inbound;
        });

        // Tombol "Simpan & Ajukan" langsung memasukkan dokumen ke alur persetujuan.
        if ($request->boolean('submit')) {
            return $this->submit($request, $inbound)
                ->setTargetUrl(route('admin.inbounds.show', $inbound));
        }

        return redirect()
            ->route('admin.inbounds.show', $inbound)
            ->with('success', "Draft {$inbound->code} berhasil disimpan.");
    }

    public function show(Inbound $inbound): View
    {
        $inbound->load(['items.product', 'user']);

        return view('admin.inbounds.show', compact('inbound'));
    }

    public function edit(Inbound $inbound): View|RedirectResponse
    {
        if (! $inbound->isEditable()) {
            return redirect()
                ->route('admin.inbounds.show', $inbound)
                ->with('error', $inbound->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        return view('admin.inbounds.edit', [
            'inbound' => $inbound->load('items.product'),
            'code' => $inbound->code,
            'products' => $this->products(),
            'suppliers' => $this->suppliers(),
        ]);
    }

    public function update(UpdateInboundRequest $request, Inbound $inbound): RedirectResponse
    {
        if (! $inbound->isEditable()) {
            return back()->with('error', $inbound->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($inbound, $data) {
            $inbound->update([
                'date' => $data['date'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $inbound->items()->delete();
            $inbound->items()->createMany($this->mergeLines($data['items'] ?? []));
        });

        if ($request->boolean('submit')) {
            return $this->submit($request, $inbound->refresh())
                ->setTargetUrl(route('admin.inbounds.show', $inbound));
        }

        return redirect()
            ->route('admin.inbounds.show', $inbound)
            ->with('success', "Dokumen {$inbound->code} berhasil diperbarui.");
    }

    /**
     * Ajukan dokumen untuk disetujui. Pengguna yang berwenang menyetujui
     * langsung diproses tanpa perlu antre.
     */
    public function submit(Request $request, Inbound $inbound): RedirectResponse
    {
        $inbound->load('items.product');

        $selfApprove = $request->user()->can('inbounds.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($inbound)
                : $this->approvals->submit($inbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Barang masuk {$inbound->code} disetujui. Stok telah bertambah."
            : "Barang masuk {$inbound->code} diajukan dan menunggu persetujuan.");
    }

    /**
     * Setujui dokumen yang sedang diajukan.
     */
    public function approve(Inbound $inbound): RedirectResponse
    {
        $inbound->load('items.product');

        try {
            $this->approvals->approve($inbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Barang masuk {$inbound->code} disetujui. Stok telah bertambah.");
    }

    /**
     * Tarik kembali pengajuan menjadi draft.
     */
    public function withdraw(Inbound $inbound): RedirectResponse
    {
        try {
            $this->approvals->withdraw($inbound);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$inbound->code} ditarik kembali ke draft.");
    }

    public function destroy(Inbound $inbound): RedirectResponse
    {
        if (! $inbound->isEditable()) {
            return back()->with('error', $inbound->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $inbound->delete();

        return redirect()
            ->route('admin.inbounds.index')
            ->with('success', 'Dokumen barang masuk berhasil dihapus.');
    }

    /**
     * Gabungkan baris dengan barang yang sama agar tidak dobel.
     *
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
                'note' => $group->pluck('note')->filter()->implode(', ') ?: null,
            ])
            ->values()
            ->all();
    }

    protected function products()
    {
        return Product::where('is_active', true)->orderBy('name')->get();
    }

    protected function suppliers()
    {
        return Supplier::where('is_active', true)->orderBy('name')->get();
    }
}
