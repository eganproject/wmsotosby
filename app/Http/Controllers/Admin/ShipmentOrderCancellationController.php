<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\ShipmentOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Menandai resi sebagai dibatalkan pembeli, atau mencabutnya kembali.
 *
 * Pembatalan sebenarnya sudah terbaca sendiri dari berkas import, tetapi itu
 * baru berlaku setelah berkas terbaru diimport. Penjual biasanya tahu dari
 * aplikasi marketplace beberapa jam lebih awal — dan beberapa jam itu cukup
 * untuk memacking lalu mengirim paket yang seharusnya batal.
 */
class ShipmentOrderCancellationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.cancel'),
        ];
    }

    public function store(Request $request, ShipmentOrder $order): RedirectResponse
    {
        $reason = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:255'],
        ], [], ['cancellation_reason' => 'alasan pembatalan'])['cancellation_reason'];

        // Barang yang sudah berangkat tidak bisa ditarik dengan menandai
        // resinya; yang seperti itu kembali lewat penerimaan retur, dan di
        // sanalah stoknya bertambah lagi.
        if ($order->outbounds()->where('status', Outbound::STATUS_POSTED)->exists()) {
            return $this->backToStatus($request)->with(
                'error',
                "Resi {$order->tracking_number} sudah dikirim. Pembatalan setelah barang berangkat dicatat lewat penerimaan retur.",
            );
        }

        $order->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $reason,
        ])->save();

        return $this->backToStatus($request)->with(
            'success',
            "Resi {$order->tracking_number} ditandai batal. Paketnya tidak bisa lagi discan maupun dikirim.",
        );
    }

    public function destroy(Request $request, ShipmentOrder $order): RedirectResponse
    {
        $order->forceFill([
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ])->save();

        return $this->backToStatus($request)->with(
            'success',
            "Pembatalan resi {$order->tracking_number} dicabut. Paketnya bisa dipacking seperti biasa.",
        );
    }

    /**
     * Kembali ke halaman status dengan saringan yang tadi dipakai.
     *
     * Sama seperti antrean siap kirim dan kotak masuk persetujuan: `back()`
     * membaca header Referer yang tidak selalu ada, dan cadangannya di sesi
     * tidak pernah diperbarui pada navigasi AJAX.
     */
    protected function backToStatus(Request $request): RedirectResponse
    {
        return redirect()->route('admin.imports.status', array_filter(
            $request->only(['stage', 'search', 'courier']),
            fn ($value) => filled($value),
        ));
    }
}
