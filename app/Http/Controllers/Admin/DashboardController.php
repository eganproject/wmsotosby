<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentOrder;
use App\Models\StockMovement;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $stats = [
            'products' => Product::count(),
            'stock_units' => (int) Product::sum('stock'),
            'low_stock' => Product::lowStock()->count(),
            'damaged_units' => (int) Product::sum('damaged_stock'),
            'inbound_month' => Inbound::where('status', Inbound::STATUS_POSTED)
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'outbound_month' => Outbound::where('status', Outbound::STATUS_POSTED)
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'outbound_pending' => Outbound::where('status', Outbound::STATUS_DRAFT)->count(),
            'marketplace_pending' => Outbound::where('status', Outbound::STATUS_DRAFT)
                ->where('type', Outbound::TYPE_MARKETPLACE)
                ->count(),
        ];

        return view('admin.dashboard', [
            'stats' => $stats,
            'waybills' => $this->waybillStages(),
            'lowStockProducts' => Product::lowStock()->where('is_active', true)->orderBy('stock')->take(6)->get(),
            'movements' => StockMovement::with('product')->latest('id')->take(8)->get(),
            'couriers' => $this->ordersPerCourier(),
            'pendingOutbounds' => Outbound::with('items')
                ->where('status', Outbound::STATUS_DRAFT)
                ->latest('id')
                ->take(5)
                ->get(),
        ]);
    }

    /**
     * Jumlah resi pada tiap tahap alur gudang.
     *
     * @return array<string, int>
     */
    protected function waybillStages(): array
    {
        return [
            ShipmentOrder::STAGE_AWAITING_QC => ShipmentOrder::awaitingQc()->count(),
            ShipmentOrder::STAGE_CHECKED => ShipmentOrder::qualityChecked()->count(),
            ShipmentOrder::STAGE_SHIPPED => ShipmentOrder::shipped()->count(),
            ShipmentOrder::STAGE_CANCELLED => ShipmentOrder::cancelled()->count(),
        ];
    }

    /**
     * Jumlah pesanan hasil import per ekspedisi, beserta berapa yang sudah
     * benar-benar dikirim, supaya sisa pekerjaan per kurir terlihat.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function ordersPerCourier()
    {
        return ShipmentOrder::query()
            ->leftJoin('outbounds', function ($join) {
                $join->on('outbounds.shipment_order_id', '=', 'shipment_orders.id')
                    ->where('outbounds.status', Outbound::STATUS_POSTED);
            })
            ->selectRaw('shipment_orders.courier as courier')
            ->selectRaw('COUNT(DISTINCT shipment_orders.id) as total')
            ->selectRaw('COUNT(DISTINCT outbounds.id) as shipped')
            ->groupBy('shipment_orders.courier')
            ->orderByDesc('total')
            ->get();
    }
}
