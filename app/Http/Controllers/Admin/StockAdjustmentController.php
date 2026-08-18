<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Http\Requests\Admin\StoreStockAdjustmentRequest;
use App\Http\Requests\Admin\UpdateStockAdjustmentRequest;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockAdjustmentController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:adjustments.view', only: ['index', 'show']),
            new Middleware('can:adjustments.create', only: ['create', 'store']),
            new Middleware('can:adjustments.update', only: ['edit', 'update']),
            new Middleware('can:adjustments.delete', only: ['destroy']),
            new Middleware('can:adjustments.post', only: ['submit', 'withdraw']),
            new Middleware('can:adjustments.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $adjustments = StockAdjustment::query()
            ->with(['user', 'items'])
            ->withCount('items')
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->string('reason')))
            ->dateBetween(DateRange::fromRequest($request))
            ->latest('date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.adjustments.index', [
            'adjustments' => $adjustments,
            'reasons' => StockAdjustment::reasons(),
        ]);
    }

    public function create(): View
    {
        return view('admin.adjustments.create', [
            'adjustment' => null,
            'code' => StockAdjustment::nextCode(),
            'products' => $this->products(),
            'reasons' => StockAdjustment::reasons(),
        ]);
    }

    public function store(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $adjustment = DB::transaction(function () use ($data) {
            $adjustment = StockAdjustment::create([
                'code' => StockAdjustment::nextCode(),
                'date' => $data['date'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'status' => StockAdjustment::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $adjustment->items()->createMany($this->buildLines($data['items'] ?? []));

            return $adjustment;
        });

        if ($request->boolean('submit')) {
            return $this->submit($request, $adjustment)
                ->setTargetUrl(route('admin.adjustments.show', $adjustment));
        }

        return redirect()
            ->route('admin.adjustments.show', $adjustment)
            ->with('success', "Draft {$adjustment->code} berhasil disimpan.");
    }

    public function show(StockAdjustment $adjustment): View
    {
        $adjustment->load(['items.product', 'user', 'submitter', 'approver', 'rejecter']);

        return view('admin.adjustments.show', compact('adjustment'));
    }

    public function edit(StockAdjustment $adjustment): View|RedirectResponse
    {
        if (! $adjustment->isEditable()) {
            return redirect()
                ->route('admin.adjustments.show', $adjustment)
                ->with('error', $adjustment->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disesuaikan tidak dapat diubah.');
        }

        return view('admin.adjustments.edit', [
            'adjustment' => $adjustment->load('items.product'),
            'code' => $adjustment->code,
            'products' => $this->products(),
            'reasons' => StockAdjustment::reasons(),
        ]);
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $adjustment): RedirectResponse
    {
        if (! $adjustment->isEditable()) {
            return back()->with('error', 'Dokumen ini tidak lagi bisa diubah.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($adjustment, $data) {
            $adjustment->update([
                'date' => $data['date'],
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
            ]);

            $adjustment->items()->delete();
            $adjustment->items()->createMany($this->buildLines($data['items'] ?? []));
        });

        if ($request->boolean('submit')) {
            return $this->submit($request, $adjustment->refresh())
                ->setTargetUrl(route('admin.adjustments.show', $adjustment));
        }

        return redirect()
            ->route('admin.adjustments.show', $adjustment)
            ->with('success', "Dokumen {$adjustment->code} berhasil diperbarui.");
    }

    /**
     * Ajukan penyesuaian. Pengguna berwenang langsung menerapkannya.
     */
    public function submit(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $adjustment->load('items.product');

        $selfApprove = $request->user()->can('adjustments.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($adjustment)
                : $this->approvals->submit($adjustment);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Penyesuaian {$adjustment->code} diterapkan. Stok sudah diperbarui."
            : "Penyesuaian {$adjustment->code} diajukan dan menunggu persetujuan.");
    }

    public function approve(StockAdjustment $adjustment): RedirectResponse
    {
        $adjustment->load('items.product');

        try {
            $this->approvals->approve($adjustment);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Penyesuaian {$adjustment->code} disetujui. Stok sudah diperbarui.");
    }

    public function withdraw(StockAdjustment $adjustment): RedirectResponse
    {
        try {
            $this->approvals->withdraw($adjustment);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$adjustment->code} ditarik kembali ke draft.");
    }

    public function destroy(StockAdjustment $adjustment): RedirectResponse
    {
        if (! $adjustment->isEditable()) {
            return back()->with('error', $adjustment->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disesuaikan tidak dapat dihapus.');
        }

        $adjustment->delete();

        return redirect()
            ->route('admin.adjustments.index')
            ->with('success', 'Dokumen penyesuaian berhasil dihapus.');
    }

    /**
     * Saldo tercatat diambil dari server, bukan dari kiriman form, supaya
     * pembandingnya tidak bisa dikarang.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function buildLines(array $lines): array
    {
        $stock = Product::whereIn('id', collect($lines)->pluck('product_id'))->pluck('stock', 'id');

        return collect($lines)
            ->groupBy('product_id')
            ->map(fn ($group, $productId) => [
                'product_id' => (int) $productId,
                'system_quantity' => (int) ($stock[$productId] ?? 0),
                // Baris kembar memakai hasil hitung terakhir, bukan dijumlahkan.
                'actual_quantity' => (int) $group->last()['actual_quantity'],
                'note' => $group->pluck('note')->filter()->implode(', ') ?: null,
            ])
            ->values()
            ->all();
    }

    /**
     * Katalog barang untuk editor baris.
     *
     * Paket bundling tidak ikut: ia tidak punya saldo yang bisa disesuaikan.
     * Yang berselisih dengan kenyataan selalu barang yang ada di rak.
     */
    protected function products()
    {
        return Product::singles()->where('is_active', true)->orderBy('name')->get();
    }
}
