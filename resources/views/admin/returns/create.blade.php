<x-app-layout title="Buat Penerimaan Retur">
    <x-ui.page-header title="Buat Penerimaan Retur"
                      subtitle="Catat barang yang dikembalikan pembeli dari marketplace maupun pengiriman biasa."
                      :back="route('admin.returns.index')" />

    @include('admin.returns.partials.form', [
        'return' => null,
        'code' => $code,
        'products' => $products,
        'marketplaces' => $marketplaces,
        'reasons' => $reasons,
        'defaultType' => $defaultType,
    ])
</x-app-layout>
