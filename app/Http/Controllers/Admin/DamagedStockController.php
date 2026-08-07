<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamagedDisposal;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Barang rusak: saldonya sendiri, beserta dokumen penanganannya.
 *
 * Unit rusak masuk dari penerimaan retur dan hanya bisa keluar lewat dokumen
 * di sini — dibuang, dikembalikan ke pemasok, atau diperbaiki sehingga layak
 * jual kembali. Dengan begitu tidak ada barang rusak yang menguap tanpa jejak.
 */
class DamagedStockController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:disposals.view', only: ['index', 'show']),
            new Middleware('can:disposals.create', only: ['create', 'store']),
            new Middleware('can:disposals.delete', only: ['destroy']),
            new Middleware('can:disposals.post', only: ['submit', 'withdraw']),
            new Middleware('can:disposals.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): View
    {
        $damaged = Product::query()
            ->where('damaged_stock', '>', 0)
            ->search($request->string('search')->trim()->value())
            ->orderByDesc('damaged_stock')
            ->paginate(10, ['*'], 'stok')
            ->withQueryString();

        $disposals = DamagedDisposal::query()
            ->with('user')
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->dateBetween($request->input('from'), $request->input('to'))
            ->latest('date')
            ->latest('id')
            ->paginate(10, ['*'], 'dokumen')
            ->withQueryString();

        return view('admin.disposals.index', [
            'damaged' => $damaged,
            'disposals' => $disposals,
            'summary' => [
                'units' => (int) Product::sum('damaged_stock'),
                'skus' => Product::where('damaged_stock', '>', 0)->count(),
                'pending' => DamagedDisposal::pending()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.disposals.create', [
            'code' => DamagedDisposal::nextCode(),
            'products' => Product::where('damaged_stock', '>', 0)->orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'action' => ['required', Rule::in(array_keys(DamagedDisposal::actions()))],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'quantities' => ['array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ], [], ['quantities.*' => 'jumlah']);

        $lines = collect($data['quantities'] ?? [])
            ->filter(fn ($quantity) => (int) $quantity > 0);

        if ($lines->isEmpty()) {
            return back()->withInput()->with('error', 'Isi jumlah pada minimal satu barang.');
        }

        $products = Product::whereIn('id', $lines->keys())->get()->keyBy('id');

        // Tidak boleh menangani lebih banyak daripada yang tercatat rusak.
        foreach ($lines as $productId => $quantity) {
            $product = $products->get((int) $productId);

            if (! $product || (int) $quantity > $product->damaged_stock) {
                throw ValidationException::withMessages([
                    'quantities' => "Jumlah {$product?->name} melebihi stok rusak yang tercatat ({$product?->damaged_stock}).",
                ]);
            }
        }

        $disposal = DB::transaction(function () use ($data, $lines) {
            $disposal = DamagedDisposal::create([
                'code' => DamagedDisposal::nextCode(),
                'date' => $data['date'],
                'action' => $data['action'],
                'supplier_id' => $data['action'] === DamagedDisposal::ACTION_RETURN ? ($data['supplier_id'] ?? null) : null,
                'note' => $data['note'] ?? null,
                'status' => DamagedDisposal::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $disposal->items()->createMany(
                $lines->map(fn ($quantity, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => (int) $quantity,
                ])->values()->all(),
            );

            return $disposal;
        });

        return redirect()
            ->route('admin.disposals.show', $disposal)
            ->with('success', "Dokumen {$disposal->code} dibuat. Ajukan untuk memproses barangnya.");
    }

    public function show(DamagedDisposal $disposal): View
    {
        $disposal->load('items.product', 'user', 'supplier');

        return view('admin.disposals.show', compact('disposal'));
    }

    public function submit(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        $disposal->load('items.product');

        $selfApprove = $request->user()->can('disposals.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($disposal)
                : $this->approvals->submit($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Dokumen {$disposal->code} diproses. Stok rusak sudah berkurang."
            : "Dokumen {$disposal->code} diajukan dan menunggu persetujuan.");
    }

    public function approve(DamagedDisposal $disposal): RedirectResponse
    {
        $disposal->load('items.product');

        try {
            $this->approvals->approve($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Dokumen {$disposal->code} diproses. Stok rusak sudah berkurang.");
    }

    public function withdraw(DamagedDisposal $disposal): RedirectResponse
    {
        try {
            $this->approvals->withdraw($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$disposal->code} ditarik kembali ke draft.");
    }

    public function destroy(DamagedDisposal $disposal): RedirectResponse
    {
        if (! $disposal->isEditable()) {
            return back()->with('error', $disposal->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $disposal->delete();

        return redirect()
            ->route('admin.disposals.index')
            ->with('success', 'Dokumen penanganan barang rusak berhasil dihapus.');
    }
}
