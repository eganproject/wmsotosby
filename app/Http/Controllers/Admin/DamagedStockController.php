<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Models\DamagedDisposal;
use App\Models\Product;
use App\Models\StockMovement;
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
 * Barang rusak dan pengeluaran barang di luar penjualan.
 *
 * Dua saldo dilayani dokumen yang sama. Dari saldo rusak: dibuang, dikembalikan
 * ke pemasok, atau diperbaiki sehingga layak jual kembali. Dari saldo layak
 * jual: dibuang karena kedaluwarsa, dikembalikan ke pemasok, atau dipindahkan
 * ke saldo rusak karena ternyata cacat.
 *
 * Dengan begitu tidak ada barang yang menguap tanpa jejak, dan tidak ada
 * pengeluaran yang terpaksa dicatat sebagai penyesuaian angka.
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
            ->dateBetween(DateRange::fromRequest($request))
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

    public function create(Request $request): View
    {
        $bucket = $this->bucketFrom($request);

        return view('admin.disposals.create', [
            'code' => DamagedDisposal::nextCode(),
            'bucket' => $bucket,
            'products' => Product::where($this->columnFor($bucket), '>', 0)->orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $bucket = $this->bucketFrom($request);
        $column = $this->columnFor($bucket);

        // Saldo asal tidak divalidasi: bucketFrom() hanya mengenal dua nilai dan
        // jatuh ke saldo rusak untuk apa pun selainnya — persis satu-satunya
        // perilaku yang dulu ada, sehingga form tanpa kolom itu tetap berlaku.
        $data = $request->validate([
            'date' => ['required', 'date'],
            // Tindakannya harus masuk akal bagi saldo asal: memperbaiki barang
            // yang memang layak jual tidak punya arti.
            'action' => ['required', Rule::in(array_keys(DamagedDisposal::actionsFor($bucket)))],
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
        $label = $bucket === StockMovement::BUCKET_GOOD ? 'stok layak jual' : 'stok rusak';

        // Tidak boleh mengeluarkan lebih banyak daripada yang tercatat.
        foreach ($lines as $productId => $quantity) {
            $product = $products->get((int) $productId);

            if (! $product || (int) $quantity > $product->{$column}) {
                throw ValidationException::withMessages([
                    'quantities' => "Jumlah {$product?->name} melebihi {$label} yang tercatat ({$product?->{$column}}).",
                ]);
            }
        }

        $disposal = DB::transaction(function () use ($data, $lines, $bucket) {
            $disposal = DamagedDisposal::create([
                'code' => DamagedDisposal::nextCode(),
                'date' => $data['date'],
                'bucket' => $bucket,
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
            ? "Dokumen {$disposal->code} diproses. {$disposal->postedSummary()}"
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

        return back()->with('success', "Dokumen {$disposal->code} diproses. {$disposal->postedSummary()}");
    }

    /**
     * Saldo asal yang sedang dikerjakan; bawaannya saldo rusak, sama seperti
     * satu-satunya perilaku yang dulu ada.
     */
    protected function bucketFrom(Request $request): string
    {
        return $request->input('bucket') === StockMovement::BUCKET_GOOD
            ? StockMovement::BUCKET_GOOD
            : StockMovement::BUCKET_DAMAGED;
    }

    protected function columnFor(string $bucket): string
    {
        return $bucket === StockMovement::BUCKET_GOOD ? 'stock' : 'damaged_stock';
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
