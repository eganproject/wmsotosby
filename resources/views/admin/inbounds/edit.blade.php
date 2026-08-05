<x-app-layout title="Ubah Barang Masuk">
    <x-ui.page-header :title="$inbound->code" subtitle="Perbarui dokumen barang masuk yang masih berstatus draft."
                      :back="route('admin.inbounds.show', $inbound)">
        <x-slot name="actions">
            <x-ui.badge variant="neutral" icon="document">Draft</x-ui.badge>
        </x-slot>
    </x-ui.page-header>

    @include('admin.inbounds.partials.form', ['inbound' => $inbound, 'code' => $code, 'products' => $products])
</x-app-layout>
