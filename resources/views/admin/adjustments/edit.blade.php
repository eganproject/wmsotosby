<x-app-layout title="Ubah Penyesuaian Stok">
    <x-ui.page-header :title="$adjustment->code" subtitle="Perbarui hasil hitung selama dokumen belum disetujui."
                      :back="route('admin.adjustments.show', $adjustment)">
        <x-slot name="actions">
            <x-ui.badge :variant="$adjustment->statusVariant()" :icon="$adjustment->statusIcon()">
                {{ $adjustment->statusLabel() }}
            </x-ui.badge>
        </x-slot>
    </x-ui.page-header>

    @include('admin.adjustments.partials.form', [
        'adjustment' => $adjustment,
        'code' => $code,
        'products' => $products,
        'reasons' => $reasons,
    ])
</x-app-layout>
