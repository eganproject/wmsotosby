<x-app-layout title="Tambah Barang">
    <x-ui.page-header title="Tambah Barang" subtitle="Daftarkan barang baru ke master data gudang."
                      :back="route('admin.products.index')" />

    @include('admin.products.partials.form', ['product' => null])
</x-app-layout>
