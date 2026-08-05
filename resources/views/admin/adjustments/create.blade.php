<x-app-layout title="Buat Penyesuaian Stok">
    <x-ui.page-header title="Buat Penyesuaian Stok"
                      subtitle="Masukkan hasil hitung fisik; selisih terhadap saldo tercatat dihitung otomatis."
                      :back="route('admin.adjustments.index')" />

    @include('admin.adjustments.partials.form', [
        'adjustment' => null,
        'code' => $code,
        'products' => $products,
        'reasons' => $reasons,
    ])
</x-app-layout>
