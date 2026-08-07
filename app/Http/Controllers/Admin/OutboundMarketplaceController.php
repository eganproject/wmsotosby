<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\OutboundItem;
use App\Models\ShipmentOrder;
use App\Services\OutboundScanService;
use App\Services\ShipmentOrderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stasiun packing pesanan marketplace.
 *
 * Satu halaman dipakai berulang: scan resi, scan barangnya, lalu layar
 * langsung kembali menunggu resi berikutnya. Stasiun ini hanya memverifikasi
 * isi paket — pengirimannya dikerjakan di antrean "Siap Dikirim", supaya
 * operator tidak pernah berhenti untuk mengurus dokumen.
 */
class OutboundMarketplaceController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ShipmentOrderResolver $resolver,
        protected OutboundScanService $scanner,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.create', only: ['create', 'store']),
            new Middleware('can:outbounds.scan', only: ['create', 'store']),
        ];
    }

    public function create(): View
    {
        return view('admin.outbounds.marketplace', [
            // Hanya yang scannya belum tuntas; yang sudah lengkap ada di
            // antrean siap kirim, bukan pekerjaan stasiun ini lagi.
            'pending' => Outbound::stillScanning()->latest('id')->take(5)->get(),
            'readyCount' => Outbound::readyToShip()->count(),
        ]);
    }

    /**
     * Scan resi: cari ke data import, bentuk dokumennya, lalu kirim balik
     * daftar barang yang harus discan berikut alamat endpoint lanjutannya.
     */
    public function store(Request $request): JsonResponse
    {
        $code = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];

        $order = $this->resolver->resolve($code);

        if (! $order) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak ditemukan di data import. Import dulu berkas pesanan dari Ginee, atau buat dokumen manual.',
            ]);
        }

        // Diperiksa sebelum dokumen dibentuk: paket batal tidak boleh dibuka
        // sama sekali, karena setiap unit yang discan akan berakhir sebagai
        // stok yang berkurang untuk pesanan yang tidak akan pernah berangkat.
        if ($order->isCancelled()) {
            throw ValidationException::withMessages([
                'code' => 'Pesanan dibatalkan pembeli · kembalikan barang ke rak',
            ]);
        }

        $outbound = $this->documentFor($order);

        return response()->json([
            'outbound' => [
                'id' => $outbound->id,
                'code' => $outbound->code,
                'tracking_number' => $outbound->tracking_number,
                'marketplace' => $outbound->marketplace,
                'recipient' => $outbound->recipient,
                'order_number' => $order->order_number,
            ],
            // Stok ikut dikirim supaya operator tahu sejak resi discan bila ada
            // barang yang tidak cukup — bukan setelah semuanya terlanjur discan.
            'items' => $outbound->items->map(fn (OutboundItem $item) => [
                'id' => $item->id,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
                'barcode' => $item->product->barcode,
                'unit' => $item->product->unit,
                'stock' => $item->product->stock,
                'quantity' => $item->quantity,
            ])->values(),
            'progress' => $this->scanner->progress($outbound),
            'urls' => [
                'item' => route('admin.outbounds.scan.item', $outbound),
                'detail' => route('admin.outbounds.show', $outbound),
            ],
        ]);
    }

    /**
     * Ambil dokumen draft yang sudah ada untuk resi ini, atau buat baru dari
     * data import. Resi langsung ditandai terverifikasi karena baru saja discan.
     */
    protected function documentFor(ShipmentOrder $order): Outbound
    {
        $existing = Outbound::with('items')
            ->where('shipment_order_id', $order->id)
            ->orWhere('tracking_number', $order->tracking_number)
            ->latest('id')
            ->first();

        if ($existing && ! $existing->isEditable()) {
            throw ValidationException::withMessages([
                'code' => "Resi ini sudah diproses pada dokumen {$existing->code}.",
            ]);
        }

        // Paket yang scannya sudah tuntas tidak boleh dibuka ulang.
        //
        // Dokumennya masih berstatus draft karena menunggu diproses di
        // antrean, dan tanpa penjagaan ini scan resi kedua akan menghapus
        // seluruh baris barangnya lalu membuatnya dari nol — hasil QC yang
        // sudah selesai hilang tanpa pemberitahuan. Kejadian ini gampang
        // terjadi tanpa disengaja: setelah paket ditutup layar kembali
        // menunggu resi, sementara label yang sama masih di depan kamera.
        if ($existing && $existing->isResiVerified() && $existing->items->isNotEmpty()
            && $existing->items->every(fn (OutboundItem $item) => $item->isFullyScanned())) {
            throw ValidationException::withMessages([
                'code' => "Resi sudah selesai discan · menunggu diproses ({$existing->code})",
            ]);
        }

        // Baris barang selalu diambil ulang dari data import agar sesuai pesanan.
        $lines = $this->resolver->toOutboundLines($order);

        // Paket yang stoknya tidak cukup tidak dibuka sama sekali: lebih baik
        // ditolak sebelum ada yang discan daripada tersangkut di tengah.
        if ($shortages = $this->scanner->stockShortages($lines)) {
            throw ValidationException::withMessages([
                'code' => $shortages,
            ]);
        }

        return DB::transaction(function () use ($order, $existing, $lines) {
            $outbound = $existing ?? new Outbound([
                'code' => Outbound::nextCode(),
                'date' => now()->toDateString(),
                'type' => Outbound::TYPE_MARKETPLACE,
                'user_id' => auth()->id(),
            ]);

            $outbound->fill([
                'recipient' => $order->buyer_name ?: ($order->store_name ?: 'Pembeli marketplace'),
                'marketplace' => $order->marketplace,
                'tracking_number' => $order->tracking_number,
                'shipment_order_id' => $order->id,
                'status' => Outbound::STATUS_DRAFT,
                'resi_verified_at' => now(),
            ])->save();

            $outbound->items()->delete();
            $outbound->items()->createMany($lines);

            return $outbound->load('items.product');
        });
    }
}
