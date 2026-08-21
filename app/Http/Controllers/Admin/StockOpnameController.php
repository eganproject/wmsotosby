<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\ApprovalService;
use App\Services\OpnameExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stok opname: menghitung fisik barang di rak, lalu menyelaraskan saldonya.
 *
 * Sesi dibuka dengan memilih cakupan; isinya langsung dipotret menjadi baris
 * hitung berikut saldo tercatatnya. Petugas tinggal berjalan di gudang dan
 * mengisi hasil hitung — bisa dicari lewat scan barcode atau SKU. Stok baru
 * bergerak setelah sesinya disetujui.
 */
class StockOpnameController extends Controller implements HasMiddleware
{
    /** Baris per halaman saat menghitung. */
    protected const PER_PAGE = 50;

    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:opnames.view', only: ['index', 'show']),
            new Middleware('can:opnames.create', only: ['create', 'store']),
            new Middleware('can:opnames.update', only: ['count']),
            new Middleware('can:opnames.delete', only: ['destroy']),
            new Middleware('can:opnames.post', only: ['submit', 'withdraw']),
            new Middleware('can:opnames.approve', only: ['approve']),
            new Middleware('can:opnames.export', only: ['export']),
        ];
    }

    public function index(Request $request): View
    {
        $opnames = StockOpname::query()
            ->with('user')
            ->withCount([
                'items',
                'items as counted_items_count' => fn ($query) => $query->whereNotNull('counted_quantity'),
            ])
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->dateBetween(DateRange::fromRequest($request))
            ->latest('date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.opnames.index', compact('opnames'));
    }

    public function create(): View
    {
        return view('admin.opnames.create', [
            'code' => StockOpname::nextCode(),
            'categories' => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'locations' => Product::query()->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
        ]);
    }

    /**
     * Buka sesi dan potret isinya. Saldo tercatat disimpan per baris agar
     * selisihnya tetap bisa dibaca meski stok berubah setelahnya.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'scope' => ['required', 'in:all,category,location'],
            'scope_value' => ['nullable', 'string', 'max:100', 'required_unless:scope,all'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'scope_value' => 'cakupan',
        ]);

        $products = StockOpname::productsInScope($data['scope'], $data['scope_value'] ?? null)
            ->orderBy('name')
            ->get(['id', 'stock']);

        if ($products->isEmpty()) {
            return back()
                ->withInput()
                ->with('error', 'Tidak ada barang aktif pada cakupan itu. Pilih cakupan lain.');
        }

        $opname = DB::transaction(function () use ($data, $products) {
            $opname = StockOpname::create([
                'code' => StockOpname::nextCode(),
                'date' => $data['date'],
                'scope' => $data['scope'],
                'scope_value' => $data['scope'] === StockOpname::SCOPE_ALL ? null : $data['scope_value'],
                'note' => $data['note'] ?? null,
                'status' => StockOpname::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $opname->items()->createMany(
                $products->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'system_quantity' => $product->stock,
                ])->all(),
            );

            return $opname;
        });

        // Langkah berikutnya setelah sesi dibuka selalu sama: mulai menghitung.
        // Yang tidak berhak mengisi hitungan tetap diantar ke daftarnya.
        return redirect()
            ->route($request->user()->can('opnames.update') ? 'admin.opnames.station' : 'admin.opnames.show', $opname)
            ->with('success', "Sesi {$opname->code} dibuka dengan {$products->count()} barang untuk dihitung.");
    }

    public function show(Request $request, StockOpname $opname): View
    {
        // Angka ringkasnya diambil lewat agregat, jadi hanya baris yang tampil
        // di halaman ini yang benar-benar dimuat.
        $opname->load('user');

        // Selisih per baris hanya boleh dibaca yang berwenang menyetujui.
        // Saringan "berselisih" adalah cara termudah mengetahui angka sistem
        // tanpa pernah melihatnya, jadi ia ikut ditutup selama sesi berjalan.
        $maySeeVariance = ! $opname->isEditable() || $request->user()->can('opnames.approve');

        $items = $opname->items()
            ->with(['product', 'counter'])
            ->when($request->string('filter')->value() === 'uncounted', fn ($query) => $query->whereNull('counted_quantity'))
            ->when($maySeeVariance && $request->string('filter')->value() === 'variance', fn ($query) => $query
                ->whereNotNull('counted_quantity')
                ->whereColumn('counted_quantity', '!=', 'system_quantity'))
            ->when($request->filled('search'), fn ($query) => $query
                ->whereHas('product', fn ($product) => $product
                    ->where('name', 'like', '%'.$request->string('search')->trim().'%')
                    ->orWhere('sku', 'like', '%'.$request->string('search')->trim().'%')
                    ->orWhere('barcode', 'like', '%'.$request->string('search')->trim().'%')))
            ->join('products', 'products.id', '=', 'stock_opname_items.product_id')
            // Urut lokasi rak, bukan abjad: petugas menyisir gudang mengikuti
            // rak, dan daftar berurutan nama membuat urutan layar tidak pernah
            // sama dengan urutan jalan kakinya. Barang tanpa lokasi jatuh ke
            // akhir daftar supaya tidak menghadang yang punya rak.
            ->orderByRaw('products.location IS NULL, products.location, products.name')
            ->select('stock_opname_items.*')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.opnames.show', compact('opname', 'items', 'maySeeVariance'));
    }

    /**
     * Simpan hasil hitung.
     *
     * Satu sesi lazim dikerjakan beberapa petugas sekaligus, jadi penyimpanan
     * tidak boleh menimpa pekerjaan orang lain. Dua penjagaan dipasang:
     * halaman hanya mengirim baris yang benar-benar disentuh, dan tiap baris
     * membawa nilai awalnya sehingga baris yang sudah berubah di database
     * dilewati — lalu dilaporkan, bukan ditimpa diam-diam.
     */
    public function count(Request $request, StockOpname $opname): RedirectResponse
    {
        if (! $opname->isEditable()) {
            return back()->with('error', 'Sesi ini tidak lagi bisa diubah.');
        }

        $data = $request->validate([
            'counts' => ['array'],
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'damaged' => ['array'],
            'damaged.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'baseline' => ['array'],
            'baseline.*' => ['nullable', 'integer'],
        ], [], ['counts.*' => 'hasil hitung', 'damaged.*' => 'jumlah rusak']);

        $counts = $data['counts'] ?? [];
        $damaged = $data['damaged'] ?? [];
        $baselines = $data['baseline'] ?? [];

        $items = $opname->items()->whereIn('id', array_keys($counts))->get()->keyBy('id');

        $changed = 0;
        $conflicts = [];

        DB::transaction(function () use ($counts, $damaged, $baselines, $items, &$changed, &$conflicts) {
            foreach ($counts as $id => $value) {
                $item = $items->get((int) $id);

                if (! $item) {
                    continue;
                }

                // Kolom dikosongkan berarti hitungannya dibatalkan, bukan nol.
                $counted = $this->normalizeCount($value);

                // Hitungan yang dibatalkan ikut membatalkan temuan rusaknya:
                // keduanya berasal dari pemeriksaan rak yang sama.
                $broken = $counted === null ? 0 : max(0, (int) ($damaged[$id] ?? 0));

                if ($item->counted_quantity === $counted && $item->damaged_quantity === $broken) {
                    continue;
                }

                // Baris yang sudah berubah sejak halaman dibuka milik orang lain.
                if (array_key_exists($id, $baselines)) {
                    $baseline = $this->normalizeCount($baselines[$id]);

                    if ($item->counted_quantity !== $baseline) {
                        $conflicts[] = $item->product->sku ?? '#'.$item->id;

                        continue;
                    }
                }

                $item->update([
                    'counted_quantity' => $counted,
                    'damaged_quantity' => $broken,
                    'counted_at' => $counted === null ? null : now(),
                    'counted_by' => $counted === null ? null : auth()->id(),
                ]);

                $changed++;
            }
        });

        return back()
            ->with('success', $changed > 0 ? "{$changed} baris hitungan tersimpan." : null)
            ->with('error', $conflicts
                ? count($conflicts).' baris dilewati karena baru saja dihitung petugas lain ('
                    .implode(', ', array_slice($conflicts, 0, 5))
                    .'). Muat ulang halaman untuk melihat angka terbarunya.'
                : null);
    }

    /**
     * Kolom kosong berarti belum dihitung; nol adalah hasil hitung yang sah.
     */
    protected function normalizeCount(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Unduh hasil sesi sebagai berkas Excel.
     *
     * Hanya sesi yang hitungannya sudah ditutup — diajukan atau diterapkan.
     * Selama sesi masih berjalan, berkas ini akan membawa keluar saldo
     * tercatat yang justru sengaja disembunyikan dari petugas penghitung,
     * dan satu unduhan sudah cukup untuk membatalkan seluruh gunanya.
     */
    public function export(StockOpname $opname, OpnameExportService $exporter): StreamedResponse|RedirectResponse
    {
        if ($opname->isEditable()) {
            return back()->with('error', 'Sesi ini masih dihitung. Ajukan hasilnya dulu sebelum diunduh.');
        }

        $opname->load(['user', 'submitter', 'approver']);

        return $exporter->download($opname, 'stok-opname-'.$opname->code.'.xlsx');
    }

    /**
     * Ajukan hasil opname. Yang berwenang menyetujui langsung diterapkan.
     */
    public function submit(Request $request, StockOpname $opname): RedirectResponse
    {
        $opname->load('items.product');

        $selfApprove = $request->user()->can('opnames.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($opname)
                : $this->approvals->submit($opname);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? $this->appliedMessage($opname)
            : "Stok opname {$opname->code} diajukan dan menunggu persetujuan.");
    }

    /**
     * Sesi yang hasilnya cocok sepenuhnya tetap boleh ditutup — tetapi tidak
     * boleh dilaporkan seakan-akan ada saldo yang disesuaikan. Yang dibaca
     * adalah angka yang benar-benar dibukukan, bukan niatnya.
     */
    protected function appliedMessage(StockOpname $opname): string
    {
        return $opname->movedStock()
            ? "Stok opname {$opname->code} diterapkan. Saldo stok sudah disesuaikan."
            : "Stok opname {$opname->code} selesai. Hasil hitung cocok dengan catatan — tidak ada saldo yang berubah.";
    }

    public function approve(StockOpname $opname): RedirectResponse
    {
        $opname->load('items.product');

        try {
            $this->approvals->approve($opname);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $this->appliedMessage($opname));
    }

    public function withdraw(StockOpname $opname): RedirectResponse
    {
        try {
            $this->approvals->withdraw($opname);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$opname->code} ditarik kembali ke draft.");
    }

    public function destroy(StockOpname $opname): RedirectResponse
    {
        if (! $opname->isEditable()) {
            return back()->with('error', $opname->isPending()
                ? 'Sesi sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Sesi yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $opname->delete();

        return redirect()
            ->route('admin.opnames.index')
            ->with('success', 'Sesi stok opname berhasil dihapus.');
    }
}
