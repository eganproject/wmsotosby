<x-app-layout title="Ubah Barang">
    <x-ui.page-header :title="$product->name"
                      :subtitle="$product->isBundle()
                          ? 'Perbarui identitas paket dan susunan barang di dalamnya.'
                          : 'Perbarui identitas, klasifikasi, dan batas stok barang.'"
                      :back="route('admin.products.index')">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.products.show', $product)" variant="secondary" icon="document">
                {{ $product->isBundle() ? 'Detail Paket' : 'Kartu Stok' }}
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('admin.products.partials.form', ['product' => $product, 'catalog' => $catalog])
</x-app-layout>
