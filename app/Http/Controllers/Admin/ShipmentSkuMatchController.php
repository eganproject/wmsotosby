<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentOrder;
use App\Services\ShipmentSkuMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * Cocokkan ulang SKU pesanan ke master barang, dari halaman importnya.
 *
 * Pencocokan hanya terjadi saat berkas diunggah, jadi resi yang masuk sebelum
 * barangnya didaftarkan tetap menggantung meskipun barangnya kemudian dibuat.
 * Sebelumnya jalan keluarnya hanya mengunggah ulang berkas Ginee-nya atau
 * menjalankan perintah dari terminal — dan terminal tidak selalu ada di tangan
 * orang yang menemukan masalahnya.
 *
 * Memakai izin yang sudah ada: siapa pun yang boleh mengunggah berkas import
 * wajar boleh mencocokkan ulang isinya.
 */
class ShipmentSkuMatchController extends Controller implements HasMiddleware
{
    public function __construct(protected ShipmentSkuMatcher $matcher)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.create'),
        ];
    }

    /**
     * Seluruh resi yang SKU-nya belum cocok.
     */
    public function all(Request $request): RedirectResponse
    {
        return $this->report($request, $this->matcher->match(), 'seluruh resi');
    }

    /**
     * Satu resi saja.
     */
    public function order(Request $request, ShipmentOrder $order): RedirectResponse
    {
        return $this->report($request, $this->matcher->match($order), "resi {$order->tracking_number}");
    }

    /**
     * Laporkan apa yang benar-benar terjadi.
     *
     * Hasil yang paling mungkin adalah nol — karena barangnya memang belum
     * didaftarkan. Tombol yang hanya menjawab "berhasil" akan ditekan
     * berulang kali oleh orang yang tidak bisa tahu apakah ada yang berubah,
     * jadi pesannya menyebut angkanya sekaligus SKU mana yang masih hilang.
     *
     * @param  array{rows: int, skus: int, remaining: Collection, ambiguous: Collection}  $result
     */
    protected function report(Request $request, array $result, string $scope): RedirectResponse
    {
        $back = $this->backToList($request);

        if ($result['ambiguous']->isNotEmpty()) {
            $back->with('error', 'SKU berikut ada lebih dari sekali di master barang bila huruf besar '
                .'dan spasi diabaikan, jadi sengaja tidak ditebak: '
                .$result['ambiguous']->keys()->implode(', ')
                .'. Samakan atau hapus salah satunya lebih dulu.');
        }

        if ($result['rows'] === 0) {
            // "status", bukan "warning": komponen flash hanya menampilkan
            // success, error, dan status — kunci lain hilang tanpa suara,
            // dan tombol yang tidak menjawab apa pun lebih buruk daripada
            // tombol yang menjawab "tidak ada yang berubah".
            return $back->with('status', $this->nothingMatched($result['remaining'], $scope));
        }

        $message = "{$result['rows']} baris pada {$scope} dicocokkan ke {$result['skus']} barang.";

        if ($result['remaining']->isNotEmpty()) {
            $message .= ' Masih tersisa '.$result['remaining']->count().' SKU yang belum terdaftar: '
                .$this->sample($result['remaining']).'.';
        }

        return $back->with('success', $message);
    }

    /**
     * @param  Collection<string, int>  $remaining
     */
    protected function nothingMatched(Collection $remaining, string $scope): string
    {
        if ($remaining->isEmpty()) {
            return "Tidak ada yang perlu dicocokkan pada {$scope} — seluruh SKU-nya sudah menunjuk barang.";
        }

        return 'Tidak ada yang bisa dicocokkan: SKU berikut belum ada di master barang — '
            .$this->sample($remaining)
            .'. Daftarkan barangnya dulu dengan SKU yang persis sama, lalu coba lagi.';
    }

    /**
     * Kembali ke daftar resi dengan saringan yang tadi dipakai.
     *
     * Sama seperti penandaan resi batal: `back()` membaca header Referer yang
     * tidak selalu ada, dan cadangannya di sesi tidak pernah diperbarui pada
     * navigasi AJAX. Saringannya dibawa sendiri oleh form, jadi tujuannya
     * pasti — dan orang yang tadi menyaring "Ada SKU belum cocok" kembali ke
     * daftar itu juga, tempat hasilnya paling terlihat.
     */
    protected function backToList(Request $request): RedirectResponse
    {
        return redirect()->route('admin.imports.index', array_filter(
            $request->only(['search', 'marketplace', 'courier', 'match', 'from', 'to']),
            fn ($value) => filled($value),
        ));
    }

    /**
     * Sepuluh SKU pertama saja. Pesan sepanjang layar tidak lagi terbaca.
     *
     * @param  Collection<string, int>  $skus
     */
    protected function sample(Collection $skus): string
    {
        $shown = $skus->keys()->take(10);

        return $shown->implode(', ').($skus->count() > $shown->count()
            ? ' (dan '.($skus->count() - $shown->count()).' lainnya)'
            : '');
    }
}
