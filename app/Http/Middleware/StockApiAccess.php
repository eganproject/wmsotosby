<?php

namespace App\Http\Middleware;

use App\Models\StockApiAllowedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StockApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('stock_api.enabled')) {
            return response()->json(['success' => false, 'error' => [
                'code' => 'API_DISABLED', 'message' => 'API stok tidak aktif.',
            ]], 503);
        }

        $token = (string) config('stock_api.token');
        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['success' => false, 'error' => [
                'code' => 'UNAUTHORIZED', 'message' => 'Token API tidak valid.',
            ]], 401);
        }

        if (! StockApiAllowedIp::query()->where('is_active', true)
            ->where('ip_address', $request->ip())->exists()) {
            return response()->json(['success' => false, 'error' => [
                'code' => 'IP_NOT_ALLOWED', 'message' => 'IP sumber tidak diizinkan.',
            ]], 403);
        }

        return $next($request);
    }
}
