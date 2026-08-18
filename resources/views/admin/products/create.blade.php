<x-app-layout title="Tambah Barang">
    <x-ui.page-header title="Tambah Barang" subtitle="Daftarkan barang baru ke master data gudang, atau susun paket bundling dari barang yang sudah ada."
                      :back="route('admin.products.index')" />

    @include('admin.products.partials.form', ['product' => null, 'catalog' => $catalog])
</x-app-layout>
