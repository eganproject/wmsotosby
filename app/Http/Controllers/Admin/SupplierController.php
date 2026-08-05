<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSupplierRequest;
use App\Http\Requests\Admin\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

class SupplierController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:suppliers.view', only: ['index', 'show']),
            new Middleware('can:suppliers.create', only: ['create', 'store']),
            new Middleware('can:suppliers.update', only: ['edit', 'update']),
            new Middleware('can:suppliers.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $suppliers = Supplier::query()
            ->withCount('inbounds')
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        return view('admin.suppliers.create', ['code' => Supplier::nextCode()]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated() + ['code' => Supplier::nextCode()]);

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', "Pemasok {$supplier->name} berhasil ditambahkan.");
    }

    public function show(Supplier $supplier): View
    {
        return view('admin.suppliers.show', [
            'supplier' => $supplier,
            'inbounds' => $supplier->inbounds()->withSum('items', 'quantity')->latest('date')->paginate(10),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('admin.suppliers.edit', [
            'supplier' => $supplier,
            'code' => $supplier->code,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', "Pemasok {$supplier->name} berhasil diperbarui.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->inbounds()->exists()) {
            return back()->with('error', 'Pemasok sudah dipakai pada dokumen barang masuk. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $supplier->delete();

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', 'Pemasok berhasil dihapus.');
    }
}
