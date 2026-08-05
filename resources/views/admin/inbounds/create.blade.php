<x-app-layout title="Buat Barang Masuk">
    <x-ui.page-header title="Buat Barang Masuk" subtitle="Catat penerimaan barang baru dari pemasok."
                      :back="route('admin.inbounds.index')" />

    @include('admin.inbounds.partials.form', ['inbound' => null, 'code' => $code, 'products' => $products])
</x-app-layout>
