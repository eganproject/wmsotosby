<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'warehouse_code' => config('stock_api.warehouse_code'),
            'server_time' => now('Asia/Jakarta')->toIso8601String(),
        ]);
    }
}
