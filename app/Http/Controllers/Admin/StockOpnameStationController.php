<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Services\OpnameCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Layar hitung cepat: satu kolom, satu barang, satu simpanan.
 *
 * Endpoint-nya mengembalikan JSON supaya kartu barang dan kemajuan sesi
 * berganti seketika — petugas berjalan dari rak ke rak tanpa halaman yang
 * dimuat ulang di antaranya.
 */
class StockOpnameStationController extends Controller implements HasMiddleware
{
    public function __construct(protected OpnameCountService $counter)
    {
    }

    public static function middleware(): array
    {
        return [
            // Menyegarkan kemajuan sesi hanya membaca; yang boleh melihat
            // opname boleh ikut memantau, termasuk dari halaman daftar.
            new Middleware('can:opnames.view', only: ['progress']),
            new Middleware('can:opnames.update', except: ['progress']),
        ];
    }

    /**
     * Kemajuan sesi terkini.
     *
     * Dipanggil berkala oleh layar yang sedang terbuka: satu sesi dikerjakan
     * beberapa orang sekaligus, dan angka yang membeku sejak halaman dimuat
     * membuat dua petugas menyisir rak yang sama tanpa pernah tahu.
     *
     * Bila layar sedang memegang satu barang, keadaan baris itu ikut dikirim —
     * supaya "baru saja dihitung rekan" ketahuan selagi petugas masih berdiri
     * di depan raknya, bukan nanti saat menyimpan.
     */
    public function progress(Request $request, StockOpname $opname): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        return response()->json([
            'progress' => $this->counter->progress($opname),
            'item' => isset($data['product_id'])
                ? $this->counter->state($opname, (int) $data['product_id'])
                : null,
        ]);
    }

    public function show(StockOpname $opname): View|RedirectResponse
    {
        if (! $opname->isEditable()) {
            return redirect()
                ->route('admin.opnames.show', $opname)
                ->with('error', 'Sesi ini tidak lagi bisa dihitung.');
        }

        return view('admin.opnames.station', [
            'opname' => $opname,
            'progress' => $this->counter->progress($opname),
        ]);
    }

    public function lookup(Request $request, StockOpname $opname): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ]);

        return response()->json(
            $this->counter->lookup($opname, $data['code'])
                + ['progress' => $this->counter->progress($opname)],
        );
    }

    /**
     * Simpan satu baris.
     *
     * Baris yang sudah berubah di database dijawab 409 berikut keadaan
     * terbarunya, bukan galat: layar menampilkan angka rekan yang menghitung
     * lebih dulu dan menawarkan menimpanya — dengan sengaja, sekali tekan.
     */
    public function count(Request $request, StockOpname $opname): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'counted' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'damaged' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'baseline' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'force' => ['nullable', 'boolean'],
            'adopt' => ['nullable', 'boolean'],
        ], [], ['counted' => 'hasil hitung', 'damaged' => 'jumlah rusak']);

        // Nilai awal yang dikirim sebagai null tetap harus dibedakan dari
        // yang memang tidak disertakan: null berarti "saya melihat baris ini
        // belum dihitung", dan itu pernyataan yang bisa bentrok.
        if (! $request->has('baseline')) {
            unset($data['baseline']);
        }

        $result = $this->counter->record($opname, $data);

        return response()->json(
            $result + ['progress' => $this->counter->progress($opname)],
            $result['conflict'] ? 409 : 200,
        );
    }
}
