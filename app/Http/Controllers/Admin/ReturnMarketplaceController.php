<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\ShipmentOrder;
use App\Services\ApprovalService;
use App\Services\ShipmentOrderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stasiun retur marketplace.
 *
 * Satu layar dipakai berulang: scan resi paket retur, periksa kondisi
 * barangnya, terima, lalu layar kembali menunggu resi berikutnya.
 *
 * Berbeda dengan barang keluar, retur tetap menyisakan satu langkah sadar —
 * menandai barang rusak — karena justru di situlah retur perlu diperiksa.
 */
class ReturnMarketplaceController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ShipmentOrderResolver $resolver,
        protected ApprovalService $approvals,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.create', only: ['create', 'store', 'manual', 'addItem', 'removeItem', 'lookupItem']),
            new Middleware('can:returns.scan', only: ['create', 'store', 'manual', 'addItem', 'removeItem', 'lookupItem']),
            new Middleware('can:returns.post', only: ['finish']),
        ];
    }

    public function create(): View
    {
        return view('admin.returns.marketplace', [
            'reasons' => ReturnReceipt::reasons(),
            'pending' => ReturnReceipt::query()
                ->where('type', ReturnReceipt::TYPE_MARKETPLACE)
                ->whereIn('status', [ReturnReceipt::STATUS_DRAFT, ReturnReceipt::STATUS_REJECTED])
                ->latest('id')
                ->take(5)
                ->get(),
        ]);
    }

    /**
     * Scan resi retur: cari ke data import, bentuk dokumennya, lalu kirim
     * balik isi paket untuk diperiksa kondisinya.
     */
    public function store(Request $request): JsonResponse
    {
        $code = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];

        $order = $this->resolver->resolve($code);

        // Resi yang belum pernah diimport bukan kegagalan: isinya diinput
        // sendiri oleh operator lewat mode manual.
        if (! $order) {
            return response()->json([
                'found' => false,
                'tracking_number' => trim($code),
                'message' => 'Resi tidak ada di data import. Scan atau ketik barangnya satu per satu.',
            ]);
        }

        $return = $this->documentFor($order);

        return response()->json(['found' => true] + $this->payload($return, $order->order_number));
    }

    /**
     * Cari barang dari kode yang discan, dipakai saat mengisi retur manual.
     */
    public function lookupItem(Request $request): JsonResponse
    {
        $code = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];

        $product = Product::findByCode($code);

        if (! $product) {
            throw ValidationException::withMessages([
                'code' => "Kode {$code} tidak dikenali sebagai barcode maupun SKU barang mana pun.",
            ]);
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'unit' => $product->unit,
            ],
        ]);
    }

    /**
     * Scan barang pertama pada retur manual: dokumennya langsung dibentuk.
     *
     * Tidak ada tahap perantara — begitu satu barang discan, dokumen ada dan
     * operator bisa terus menambah barang sekaligus memeriksa kondisinya.
     */
    public function manual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'sender' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:191'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        // Retur borongan discan sekali dengan menyebut jumlahnya.
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $product = $this->productFor($data['code']);

        $tracking = filled($data['tracking_number'] ?? null) ? trim($data['tracking_number']) : null;

        if ($tracking && ReturnReceipt::where('tracking_number', $tracking)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Nomor resi ini sudah dipakai dokumen retur lain.',
            ]);
        }

        $return = DB::transaction(function () use ($data, $tracking, $product, $quantity) {
            $return = ReturnReceipt::create([
                'code' => ReturnReceipt::nextCode(),
                'date' => now()->toDateString(),
                'type' => ReturnReceipt::TYPE_MARKETPLACE,
                'sender' => $data['sender'] ?? 'Pembeli marketplace',
                'tracking_number' => $tracking,
                'status' => ReturnReceipt::STATUS_DRAFT,
                // Label fisiknya baru saja discan operator.
                'resi_verified_at' => now(),
                'user_id' => auth()->id(),
            ]);

            $return->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'good_quantity' => $quantity,
                'damaged_quantity' => 0,
            ]);

            return $return->load('items.product');
        });

        return response()->json([
            'found' => true,
            'scanned' => $this->scannedInfo($product, $quantity),
        ] + $this->payload($return, null));
    }

    /**
     * Tambah satu unit barang ke retur manual yang sedang dikerjakan.
     */
    public function addItem(Request $request, ReturnReceipt $return): JsonResponse
    {
        $this->guardEditable($return);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:191'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $product = $this->productFor($data['code']);

        $item = DB::transaction(function () use ($return, $product, $quantity) {
            $item = $return->items()->where('product_id', $product->id)->first();

            if ($item) {
                // Unit baru dianggap layak jual sampai operator menyatakan lain.
                $item->update([
                    'quantity' => $item->quantity + $quantity,
                    'good_quantity' => $item->good_quantity + $quantity,
                ]);
            } else {
                $item = $return->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'good_quantity' => $quantity,
                    'damaged_quantity' => 0,
                ]);
            }

            return $item;
        });

        $return->load('items.product');

        return response()->json([
            'found' => true,
            'scanned' => $this->scannedInfo($product, $item->quantity),
        ] + $this->payload($return, $return->reference));
    }

    /**
     * Buang baris yang salah discan.
     */
    public function removeItem(ReturnReceipt $return, ReturnReceiptItem $item): JsonResponse
    {
        $this->guardEditable($return);

        abort_unless($item->return_receipt_id === $return->id, 404);

        $item->delete();

        $return->load('items.product');

        return response()->json(['found' => true] + $this->payload($return, $return->reference));
    }

    protected function guardEditable(ReturnReceipt $return): void
    {
        if (! $return->isEditable()) {
            throw ValidationException::withMessages([
                'code' => "Dokumen {$return->code} tidak lagi bisa diubah.",
            ]);
        }
    }

    protected function productFor(string $code): Product
    {
        $product = Product::findByCode($code);

        if (! $product) {
            throw ValidationException::withMessages([
                'code' => "Kode {$code} tidak dikenali sebagai barcode maupun SKU barang mana pun.",
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scannedInfo(Product $product, int $quantity): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => $quantity,
            'unit' => $product->unit,
        ];
    }

    /**
     * Bentuk balasan yang sama untuk resi hasil import maupun input manual.
     *
     * @return array<string, mixed>
     */
    protected function payload(ReturnReceipt $return, ?string $orderNumber): array
    {
        return [
            'return' => [
                'id' => $return->id,
                'code' => $return->code,
                'tracking_number' => $return->tracking_number,
                'marketplace' => $return->marketplace,
                'sender' => $return->sender,
                'order_number' => $orderNumber,
            ],
            'items' => $return->items->map(fn (ReturnReceiptItem $item) => [
                'id' => $item->id,
                'sku' => $item->product->sku,
                'name' => $item->product->name,
                'barcode' => $item->product->barcode,
                'unit' => $item->product->unit,
                'quantity' => $item->quantity,
                'good' => $item->good_quantity,
                'damaged' => $item->damaged_quantity,
                'remove_url' => route('admin.returns.marketplace.item.remove', [$return, $item]),
            ])->values(),
            'urls' => [
                'item' => route('admin.returns.marketplace.item', $return),
                'finish' => route('admin.returns.marketplace.finish', $return),
                'detail' => route('admin.returns.show', $return),
            ],
        ];
    }

    /**
     * Terima paket retur beserta hasil pemeriksaan tiap barangnya.
     *
     * Operator mengisi berapa yang layak jual dan berapa yang rusak; sisanya
     * terhadap jumlah pada resi otomatis dianggap hilang.
     */
    public function finish(Request $request, ReturnReceipt $return): JsonResponse
    {
        $return->load('items.product');

        $data = $request->validate([
            'reason' => ['nullable', Rule::in(ReturnReceipt::reasons())],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['array'],
            'items.*.good' => ['required_with:items.*', 'integer', 'min:0'],
            'items.*.damaged' => ['required_with:items.*', 'integer', 'min:0'],
            'items.*.expected' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $checks = $data['items'] ?? [];

        // Retur tanpa data import tidak punya pembanding: jumlah yang
        // seharusnya kembali dinyatakan operator, dan selisihnya terhadap
        // barang yang benar-benar ada menjadi barang hilang.
        $manual = $return->shipment_order_id === null;

        foreach ($return->items as $item) {
            $check = $checks[$item->id] ?? null;

            if (! $check) {
                continue;
            }

            $expected = $manual && isset($check['expected'])
                ? (int) $check['expected']
                : $item->quantity;

            if ($check['good'] + $check['damaged'] > $expected) {
                throw ValidationException::withMessages([
                    'code' => "{$item->product->name} (SKU {$item->product->sku}): layak jual + rusak melebihi {$expected} {$item->product->unit} "
                        .($manual ? 'yang seharusnya kembali.' : 'pada resi.'),
                ]);
            }
        }

        DB::transaction(function () use ($return, $checks, $data, $manual) {
            foreach ($return->items as $item) {
                $check = $checks[$item->id] ?? null;

                if ($check) {
                    $item->update(array_filter([
                        'quantity' => $manual && isset($check['expected']) ? (int) $check['expected'] : null,
                        'good_quantity' => $check['good'],
                        'damaged_quantity' => $check['damaged'],
                    ], fn ($value) => $value !== null));
                }
            }

            $return->update(array_filter([
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]));
        });

        $return->load('items.product');

        $selfApprove = $request->user()->can('returns.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($return)
                : $this->approvals->submit($return);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'code' => collect($exception->errors())->flatten()->implode(' '),
            ]);
        }

        $return->refresh()->load('items');

        $missing = $return->missingQuantity();

        return response()->json([
            'code' => $return->code,
            'tracking_number' => $return->tracking_number,
            'posted' => $return->isPosted(),
            'good' => $return->goodQuantity(),
            'damaged' => $return->damagedQuantity(),
            'missing' => $missing,
            'message' => $selfApprove
                ? "{$return->code} diterima. {$return->goodQuantity()} unit layak jual masuk stok"
                    .($missing > 0 ? ", {$missing} unit hilang." : '.')
                : "{$return->code} diajukan dan menunggu persetujuan.",
        ]);
    }

    /**
     * Ambil dokumen retur yang masih bisa diubah untuk resi ini, atau buat
     * baru dari data import.
     */
    protected function documentFor(ShipmentOrder $order): ReturnReceipt
    {
        $existing = ReturnReceipt::with('items')
            ->where('shipment_order_id', $order->id)
            ->orWhere('tracking_number', $order->tracking_number)
            ->latest('id')
            ->first();

        if ($existing && ! $existing->isEditable()) {
            throw ValidationException::withMessages([
                'code' => "Resi ini sudah diproses pada dokumen retur {$existing->code}.",
            ]);
        }

        $lines = $this->resolver->toReturnLines($order);

        return DB::transaction(function () use ($order, $existing, $lines) {
            $return = $existing ?? new ReturnReceipt([
                'code' => ReturnReceipt::nextCode(),
                'date' => now()->toDateString(),
                'type' => ReturnReceipt::TYPE_MARKETPLACE,
                'user_id' => auth()->id(),
            ]);

            $return->fill([
                'sender' => $order->buyer_name ?: ($order->store_name ?: 'Pembeli marketplace'),
                'marketplace' => $order->marketplace,
                'tracking_number' => $order->tracking_number,
                'reference' => $order->order_number,
                'shipment_order_id' => $order->id,
                'status' => ReturnReceipt::STATUS_DRAFT,
                // Resi baru saja discan di stasiun ini.
                'resi_verified_at' => now(),
            ])->save();

            $return->items()->delete();
            $return->items()->createMany($lines);

            return $return->load('items.product');
        });
    }
}
