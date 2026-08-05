<x-app-layout title="Ubah Pemasok">
    <x-ui.page-header :title="$supplier->name" subtitle="Perbarui data kontak dan status pemasok."
                      :back="route('admin.suppliers.index')" />

    @include('admin.suppliers.partials.form', ['supplier' => $supplier, 'code' => $code])
</x-app-layout>
