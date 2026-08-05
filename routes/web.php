<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\DamagedStockController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InboundController;
use App\Http\Controllers\Admin\OutboundController;
use App\Http\Controllers\Admin\OutboundDispatchController;
use App\Http\Controllers\Admin\OutboundMarketplaceController;
use App\Http\Controllers\Admin\OutboundScanController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\ReturnMarketplaceController;
use App\Http\Controllers\Admin\ReturnReceiptController;
use App\Http\Controllers\Admin\ReturnScanController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShipmentImportController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockMovementController;
use App\Http\Controllers\Admin\StockOpnameController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\WaybillStatusController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('auth')
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Operasional gudang
        Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
        Route::get('products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import.store');
        Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::resource('products', ProductController::class);

        Route::get('movements/export', [StockMovementController::class, 'export'])->name('movements.export');
        Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');

        Route::post('inbounds/{inbound}/submit', [InboundController::class, 'submit'])->name('inbounds.submit');
        Route::post('inbounds/{inbound}/approve', [InboundController::class, 'approve'])->name('inbounds.approve');
        Route::post('inbounds/{inbound}/withdraw', [InboundController::class, 'withdraw'])->name('inbounds.withdraw');
        Route::resource('inbounds', InboundController::class);

        Route::get('outbounds/marketplace', [OutboundMarketplaceController::class, 'create'])->name('outbounds.marketplace');
        Route::post('outbounds/marketplace', [OutboundMarketplaceController::class, 'store'])->name('outbounds.marketplace.store');
        Route::get('outbounds/ready', [OutboundDispatchController::class, 'index'])->name('outbounds.ready');
        Route::post('outbounds/ready', [OutboundDispatchController::class, 'process'])->name('outbounds.ready.process');
        Route::get('outbounds/{outbound}/scan', [OutboundScanController::class, 'show'])->name('outbounds.scan');
        Route::post('outbounds/{outbound}/scan/resi', [OutboundScanController::class, 'resi'])->name('outbounds.scan.resi');
        Route::post('outbounds/{outbound}/scan/item', [OutboundScanController::class, 'item'])->name('outbounds.scan.item');
        Route::post('outbounds/{outbound}/scan/reset', [OutboundScanController::class, 'reset'])->name('outbounds.scan.reset');
        Route::post('outbounds/{outbound}/submit', [OutboundController::class, 'submit'])->name('outbounds.submit');
        Route::post('outbounds/{outbound}/approve', [OutboundController::class, 'approve'])->name('outbounds.approve');
        Route::post('outbounds/{outbound}/withdraw', [OutboundController::class, 'withdraw'])->name('outbounds.withdraw');
        Route::resource('outbounds', OutboundController::class);

        Route::get('returns/marketplace', [ReturnMarketplaceController::class, 'create'])->name('returns.marketplace');
        Route::post('returns/marketplace', [ReturnMarketplaceController::class, 'store'])->name('returns.marketplace.store');
        Route::post('returns/marketplace/manual', [ReturnMarketplaceController::class, 'manual'])->name('returns.marketplace.manual');
        Route::post('returns/marketplace/lookup', [ReturnMarketplaceController::class, 'lookupItem'])->name('returns.marketplace.lookup');
        Route::post('returns/marketplace/{return}/item', [ReturnMarketplaceController::class, 'addItem'])->name('returns.marketplace.item');
        Route::delete('returns/marketplace/{return}/item/{item}', [ReturnMarketplaceController::class, 'removeItem'])->name('returns.marketplace.item.remove');
        Route::post('returns/marketplace/{return}/finish', [ReturnMarketplaceController::class, 'finish'])->name('returns.marketplace.finish');
        Route::get('returns/{return}/scan', [ReturnScanController::class, 'show'])->name('returns.scan');
        Route::post('returns/{return}/scan/resi', [ReturnScanController::class, 'resi'])->name('returns.scan.resi');
        Route::post('returns/{return}/scan/reset', [ReturnScanController::class, 'reset'])->name('returns.scan.reset');
        Route::post('returns/{return}/submit', [ReturnReceiptController::class, 'submit'])->name('returns.submit');
        Route::post('returns/{return}/approve', [ReturnReceiptController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return}/withdraw', [ReturnReceiptController::class, 'withdraw'])->name('returns.withdraw');
        Route::resource('returns', ReturnReceiptController::class)->parameters(['returns' => 'return']);

        Route::post('adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submit'])->name('adjustments.submit');
        Route::post('adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/withdraw', [StockAdjustmentController::class, 'withdraw'])->name('adjustments.withdraw');
        Route::resource('adjustments', StockAdjustmentController::class);

        Route::post('disposals/{disposal}/submit', [DamagedStockController::class, 'submit'])->name('disposals.submit');
        Route::post('disposals/{disposal}/approve', [DamagedStockController::class, 'approve'])->name('disposals.approve');
        Route::post('disposals/{disposal}/withdraw', [DamagedStockController::class, 'withdraw'])->name('disposals.withdraw');
        Route::resource('disposals', DamagedStockController::class)->except(['edit', 'update']);

        Route::post('opnames/{opname}/count', [StockOpnameController::class, 'count'])->name('opnames.count');
        Route::post('opnames/{opname}/submit', [StockOpnameController::class, 'submit'])->name('opnames.submit');
        Route::post('opnames/{opname}/approve', [StockOpnameController::class, 'approve'])->name('opnames.approve');
        Route::post('opnames/{opname}/withdraw', [StockOpnameController::class, 'withdraw'])->name('opnames.withdraw');
        Route::resource('opnames', StockOpnameController::class)->except(['edit', 'update']);

        Route::resource('suppliers', SupplierController::class);

        // Persetujuan dokumen
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        // Import resi dari Ginee
        Route::get('imports/status', WaybillStatusController::class)->name('imports.status');
        Route::get('imports/lookup', [ShipmentImportController::class, 'lookup'])->name('imports.lookup');
        Route::get('imports/batches', [ShipmentImportController::class, 'batches'])->name('imports.batches');
        Route::resource('imports', ShipmentImportController::class)->except('edit', 'update')
            ->parameters(['imports' => 'import']);

        // Manajemen akses
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::put('permissions', [PermissionController::class, 'update'])->name('permissions.update');

        Route::resource('users', UserController::class);
        Route::resource('roles', RoleController::class)->except('show');
    });

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
