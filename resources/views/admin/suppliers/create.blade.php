<x-app-layout title="Tambah Pemasok">
    <x-ui.page-header title="Tambah Pemasok" subtitle="Daftarkan pemasok baru untuk dipakai pada dokumen barang masuk."
                      :back="route('admin.suppliers.index')" />

    @include('admin.suppliers.partials.form', ['supplier' => null, 'code' => $code])
</x-app-layout>
