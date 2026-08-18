<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Http\Requests\Admin\StoreOutboundRequest;
use App\Http\Requests\Admin\UpdateOutboundRequest;
use App\Models\Outbound;
use App\Models\Product;
use App\Services\ApprovalService;
use App\Services\BundleExploder;
use App\Support\BundledLines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OutboundController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ApprovalService $approvals,
        protected BundleExploder $exploder,
    ) {
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
            ->when($request->filled('status'), fn ($query) => $query->atStage($request->string('status')->value()))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('marketplace'), fn ($query) => $query->where('marketplace', $request->string('marketplace')))
            ->dateBetween(DateRange::fromRequest($request))
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

            $lines = $this->buildLines($data['items'] ?? []);

            $outbound->items()->createMany($lines->items);
            $outbound->bundles()->createMany($lines->bundles);

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
        $outbound->load(['items.product', 'bundles.bundle', 'user']);

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

        $outbound->load('items.product', 'bundles.bundle');

        return view('admin.outbounds.edit', [
            'outbound' => $outbound,
            'code' => $outbound->code,
            'products' => $this->products($outbound),
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

        // Dibaca sebelum update(): setelahnya nilai lamanya sudah tersapu.
        $trackingChanged = ($data['tracking_number'] ?? null) !== $outbound->tracking_number;
        $typeChanged = $data['type'] !== $outbound->type;

        DB::transaction(function () use ($outbound, $data, $trackingChanged, $typeChanged) {
            $outbound->update([
                'date' => $data['date'],
                'type' => $data['type'],
                'recipient' => $data['recipient'],
                'marketplace' => $data['marketplace'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            // Baris barang selalu ditulis ulang seluruhnya, jadi hasil scan yang
            // masih sahih harus diselamatkan lebih dulu. Tanpa ini setiap
            // penyimpanan — termasuk yang tidak mengubah apa pun — memulangkan
            // paket yang sudah selesai QC ke titik nol, dan paket itu diam-diam
            // hilang dari antrean Siap Dikirim.
            $scanned = $outbound->items()->pluck('scanned_quantity', 'product_id');

            $lines = $this->buildLines($data['items'] ?? [], $scanned);

            $outbound->items()->delete();
            $outbound->bundles()->delete();
            $outbound->items()->createMany($lines->items);
            $outbound->bundles()->createMany($lines->bundles);

            // Verifikasi resi berlaku atas satu nomor tertentu, jadi hanya nomor
            // yang berganti — atau dokumen yang berpindah jenis — yang
            // membatalkannya. Mengetik ulang catatan tidak.
            if ($trackingChanged || $typeChanged) {
                $outbound->forceFill(['resi_verified_at' => null])->save();
            }
        });

        if ($outbound->refresh()->isMarketplace()) {
            return redirect()
                ->route('admin.outbounds.scan', $outbound)
                ->with('success', "Dokumen {$outbound->code} diperbarui. ".($trackingChanged
                    ? 'Nomor resi berganti, jadi verifikasinya diulang dari awal.'
                    : 'Hasil scan yang masih cocok tetap tersimpan.'));
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
     * Baris dokumen dari isian form.
     *
     * Paket bundling dipecah menjadi barang isinya lebih dulu, lalu barang
     * yang sama digabung menjadi satu baris — termasuk bila ia datang dari
     * paket sekaligus dipesan satuan pada dokumen yang sama.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, int>|null  $scanned  hasil scan per barang yang masih boleh dipertahankan
     */
    protected function buildLines(array $lines, ?Collection $scanned = null): BundledLines
    {
        $exploded = $this->exploder->explode($lines);

        return new BundledLines(
            array_map(fn (array $line) => $line + [
                // Tidak pernah melebihi jumlah yang diminta: baris yang
                // jumlahnya dikurangi tidak boleh tampak lebih dari selesai.
                'scanned_quantity' => min((int) ($scanned[$line['product_id']] ?? 0), $line['quantity']),
            ], $exploded->items),
            $exploded->bundles,
        );
    }

    /**
     * Katalog barang untuk editor baris.
     *
     * Barang yang sudah dinonaktifkan tetapi masih tercantum di dokumen tetap
     * ikut. Tanpa pilihannya, dropdown baris itu jatuh ke kosong dan barisnya
     * lenyap tanpa suara begitu dokumen disimpan ulang.
     */
    protected function products(?Outbound $outbound = null)
    {
        // Paket bundling ikut, tidak seperti dokumen lain: barang keluar
        // justru satu-satunya tempat paket boleh dipesan. Ketersediaannya
        // dihitung sekali di basis data — memanggilnya per baris akan
        // membuat satu katalog berisi puluhan paket menjadi puluhan kueri.
        return Product::withBundleAvailability()
            ->where('is_active', true)
            ->when($outbound, fn ($query) => $query->orWhereIn('id', $outbound->items->pluck('product_id')))
            ->orderBy('name')
            ->get();
    }
}
