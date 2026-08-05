<x-app-layout title="Buat Barang Keluar">
    <x-ui.page-header title="Buat Barang Keluar" subtitle="Catat pengiriman barang reguler atau pesanan marketplace."
                      :back="route('admin.outbounds.index')" />

    @include('admin.outbounds.partials.form', [
        'outbound' => null,
        'code' => $code,
        'products' => $products,
        'marketplaces' => $marketplaces,
        'defaultType' => $defaultType,
    ])
</x-app-layout>
