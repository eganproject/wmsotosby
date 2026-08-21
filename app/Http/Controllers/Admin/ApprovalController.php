<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\ReturnReceipt;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Kotak masuk persetujuan: semua dokumen yang menunggu keputusan,
 * dari barang masuk, barang keluar, maupun penerimaan retur.
 */
class ApprovalController extends Controller implements HasMiddleware
{
    /**
     * Jenis dokumen yang mengikuti alur persetujuan.
     *
     * @var array<string, array<string, mixed>>
     */
    public const TYPES = [
        'inbound' => [
            'model' => Inbound::class,
            'label' => 'Barang Masuk',
            'icon' => 'login',
            'permission' => 'inbounds.approve',
            'route' => 'admin.inbounds.show',
        ],
        'outbound' => [
            'model' => Outbound::class,
            'label' => 'Barang Keluar',
            'icon' => 'logout',
            'permission' => 'outbounds.approve',
            'route' => 'admin.outbounds.show',
        ],
        'return' => [
            'model' => ReturnReceipt::class,
            'label' => 'Penerimaan Retur',
            'icon' => 'refresh',
            'permission' => 'returns.approve',
            'route' => 'admin.returns.show',
        ],
        'adjustment' => [
            'model' => StockAdjustment::class,
            'label' => 'Penyesuaian Stok',
            'icon' => 'sliders',
            'permission' => 'adjustments.approve',
            'route' => 'admin.adjustments.show',
        ],
        'disposal' => [
            'model' => DamagedDisposal::class,
            'label' => 'Barang Rusak',
            'icon' => 'trash',
            'permission' => 'disposals.approve',
            'route' => 'admin.disposals.show',
        ],
        'opname' => [
            'model' => StockOpname::class,
            'label' => 'Stok Opname',
            'icon' => 'cube',
            'permission' => 'opnames.approve',
            'route' => 'admin.opnames.show',
        ],
    ];

    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:approvals.view', only: ['index']),
        ];
    }

    public function index(Request $request): View
    {
        $filter = $request->string('type')->value();

        $groups = collect(self::TYPES)
            ->when($filter, fn ($types) => $types->only($filter))
            ->map(function (array $config, string $key) {
                /** @var class-string<Model> $model */
                $model = $config['model'];

                return $config + [
                    'key' => $key,
                    'documents' => $model::query()
                        ->pending()
                        ->with(['items.product', 'submitter'])
                        ->oldest('submitted_at')
                        ->get(),
                ];
            });

        return view('admin.approvals.index', [
            'groups' => $groups,
            'total' => $groups->sum(fn (array $group) => $group['documents']->count()),
        ]);
    }

    public function approve(Request $request, string $type, int $id): RedirectResponse
    {
        [$document, $config] = $this->resolve($type, $id);

        $this->authorizeAction($config['permission']);

        try {
            $this->approvals->approve($document);
        } catch (ValidationException $exception) {
            return $this->backToInbox($request)
                ->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        // Stok opname yang hasilnya cocok sepenuhnya tetap disetujui, tetapi
        // tidak ada saldo yang bergerak — dan itu tidak boleh dilaporkan
        // sebagai pembaruan stok.
        $moved = ! method_exists($document, 'movedStock') || $document->movedStock();

        return $this->backToInbox($request)
            ->with('success', $moved
                ? "Dokumen {$document->code} disetujui. Stok telah diperbarui."
                : "Dokumen {$document->code} disetujui. Hasilnya cocok dengan catatan — tidak ada saldo yang berubah.");
    }

    public function reject(Request $request, string $type, int $id): RedirectResponse
    {
        [$document, $config] = $this->resolve($type, $id);

        $this->authorizeAction($config['permission']);

        $reason = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:255'],
        ], [], ['rejection_reason' => 'alasan penolakan'])['rejection_reason'];

        try {
            $this->approvals->reject($document, $reason);
        } catch (ValidationException $exception) {
            return $this->backToInbox($request)
                ->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return $this->backToInbox($request)
            ->with('success', "Dokumen {$document->code} ditolak dan dikembalikan ke penyusunnya.");
    }

    /**
     * Kembali ke kotak masuk dengan saringan yang tadi dipakai.
     *
     * Sama seperti antrean siap kirim: `back()` membaca header Referer yang
     * tidak selalu ada, dan cadangannya di sesi tidak pernah diperbarui pada
     * navigasi AJAX — sehingga saringannya hilang setelah satu keputusan.
     */
    protected function backToInbox(Request $request): RedirectResponse
    {
        // Dikirim sebagai "filter", bukan "type": nama kedua sudah dipakai
        // parameter route jenis dokumen yang sedang diputuskan, dan dua hal
        // berbeda dengan nama sama cepat atau lambat tertukar.
        $filter = $request->input('filter');

        return redirect()->route(
            'admin.approvals.index',
            filled($filter) ? ['type' => $filter] : [],
        );
    }

    /**
     * @return array{0: Model, 1: array<string, mixed>}
     */
    protected function resolve(string $type, int $id): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $config = self::TYPES[$type];

        /** @var class-string<Model> $model */
        $model = $config['model'];

        return [$model::with('items.product')->findOrFail($id), $config];
    }

    protected function authorizeAction(string $permission): void
    {
        abort_unless(auth()->user()->can($permission), 403);
    }
}
