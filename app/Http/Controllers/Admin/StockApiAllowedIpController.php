<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockApiAllowedIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockApiAllowedIpController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stock-api-access.view', only: ['index']),
            new Middleware('can:stock-api-access.update', only: ['store', 'update']),
            new Middleware('can:stock-api-access.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $ips = StockApiAllowedIp::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = $request->string('search')->trim()->value();
                $query->where(fn ($query) => $query
                    ->where('ip_address', 'like', "%{$term}%")
                    ->orWhere('label', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query
                ->where('is_active', $request->input('status') === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('ip_address')
            ->paginate(20)
            ->withQueryString();

        return view('admin.stock-api-access.index', compact('ips'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'max:45', 'unique:stock_api_allowed_ips,ip_address'],
            'label' => ['nullable', 'string', 'max:150'],
        ]);

        StockApiAllowedIp::create($data + ['is_active' => true]);

        return back()->with('success', "IP {$data['ip_address']} berhasil diizinkan.");
    }

    public function update(Request $request, StockApiAllowedIp $stockApiAllowedIp): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => [
                'required', 'ip', 'max:45',
                Rule::unique('stock_api_allowed_ips', 'ip_address')->ignore($stockApiAllowedIp),
            ],
            'label' => ['nullable', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ]);

        $stockApiAllowedIp->update($data);

        return back()->with('success', "Akses IP {$data['ip_address']} berhasil diperbarui.");
    }

    public function destroy(StockApiAllowedIp $stockApiAllowedIp): RedirectResponse
    {
        $ip = $stockApiAllowedIp->ip_address;
        $stockApiAllowedIp->delete();

        return back()->with('success', "IP {$ip} dihapus dari daftar akses API.");
    }
}
