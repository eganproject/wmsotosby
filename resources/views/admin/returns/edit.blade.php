<x-app-layout title="Ubah Penerimaan Retur">
    <x-ui.page-header :title="$return->code" subtitle="Perbarui dokumen retur yang masih berstatus draft."
                      :back="route('admin.returns.show', $return)">
        <x-slot name="actions">
            @if ($return->requiresResiScan())
                <x-ui.badge variant="warning" icon="warning">Mengubah dokumen mereset verifikasi resi</x-ui.badge>
            @endif
        </x-slot>
    </x-ui.page-header>

    @include('admin.returns.partials.form', [
        'return' => $return,
        'code' => $code,
        'products' => $products,
        'marketplaces' => $marketplaces,
        'reasons' => $reasons,
        'defaultType' => $defaultType,
    ])
</x-app-layout>
