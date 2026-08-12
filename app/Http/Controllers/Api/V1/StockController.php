<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockApiSyncRecord;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$filters, $error] = $this->filters($request);
        if ($error) {
            return $error;
        }

        $now = now('Asia/Jakarta');
        $query = $filters['as_of']
            ? $this->historical($filters['as_of'])
            : StockApiSyncRecord::query();

        if (! $filters['as_of']) {
            if ($filters['updated_since']) {
                $query->where('source_updated_at', '>=', $filters['updated_since']);
            }
            $query->where('source_updated_at', '<=', $filters['updated_until'] ?? $now);
        }

        $total = (clone $query)->count();
        $rows = $filters['as_of']
            ? $query->orderBy('sr.sku')->forPage($filters['page'], $filters['per_page'])->get()
            : $query->orderBy('source_updated_at')->orderBy('sku')
                ->forPage($filters['page'], $filters['per_page'])->get();

        return response()->json([
            'success' => true,
            'meta' => [
                'warehouse_code' => config('stock_api.warehouse_code'),
                'server_time' => $now->toIso8601String(),
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total' => $total,
                'total_pages' => (int) ceil($total / $filters['per_page']),
            ],
            'data' => $rows->map(fn (object $row) => [
                'sku' => $row->sku,
                'name' => $row->name,
                'category' => $row->category,
                'uom' => $row->uom,
                'qty' => (float) ($row->historical_qty ?? $row->qty),
                'min_qty' => $row->min_qty === null ? null : (float) $row->min_qty,
                'status' => $row->status,
                'updated_at' => $this->iso($row->historical_updated_at ?? $row->source_updated_at),
            ])->values(),
        ]);
    }

    private function historical(CarbonImmutable $date): Builder
    {
        $ledger = DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END) as qty_as_of")
            ->selectRaw('MAX(created_at) as last_movement_at')
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('created_at', '<=', $date)
            ->groupBy('product_id');

        return DB::table('stock_api_sync_records as sr')
            ->leftJoinSub($ledger, 'ledger', 'ledger.product_id', '=', 'sr.product_id')
            ->select([
                'sr.sku', 'sr.name', 'sr.category', 'sr.uom', 'sr.min_qty',
                'sr.status', 'sr.source_updated_at',
                DB::raw("CASE WHEN sr.status = 'deleted' THEN 0 WHEN COALESCE(ledger.qty_as_of, 0) < 0 THEN 0 ELSE COALESCE(ledger.qty_as_of, 0) END as historical_qty"),
                DB::raw('COALESCE(ledger.last_movement_at, sr.source_updated_at) as historical_updated_at'),
            ]);
    }

    private function filters(Request $request): array
    {
        $page = filter_var($request->input('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($request->input('per_page', 100), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($page === false || $perPage === false) {
            return [[], $this->bad('page dan per_page harus berupa integer positif; per_page maksimum 500.')];
        }

        $since = $this->time($request->input('updated_since'));
        $until = $this->time($request->input('updated_until'));
        if ($since === false || $until === false) {
            return [[], $this->bad('updated_since dan updated_until harus ISO-8601 dengan offset zona waktu.')];
        }
        if ($since && $until && $since->gt($until)) {
            return [[], $this->bad('updated_since tidak boleh melebihi updated_until.')];
        }

        $asOf = $this->date($request->input('as_of'));
        if ($asOf === false) {
            return [[], $this->bad('as_of harus berupa tanggal YYYY-MM-DD yang valid (WIB).')];
        }
        if ($asOf && ($since || $until)) {
            return [[], $this->bad('as_of tidak dapat dipakai bersama updated_since atau updated_until.')];
        }

        return [[
            'page' => $page, 'per_page' => $perPage, 'updated_since' => $since,
            'updated_until' => $until, 'as_of' => $asOf,
        ], null];
    }

    private function time(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/T.*(?:Z|[+-]\\d{2}:\\d{2})$/', $value)) {
            return false;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return false;
        }
    }

    private function date(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
            return false;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');

            return $date->format('Y-m-d') === $value ? $date->endOfDay() : false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function iso(mixed $value): string
    {
        return ($value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value))
            ->setTimezone('Asia/Jakarta')->toIso8601String();
    }

    private function bad(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => [
            'code' => 'INVALID_PARAMETER', 'message' => $message,
        ]], 400);
    }
}
