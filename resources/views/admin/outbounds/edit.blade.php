<x-app-layout title="Ubah Barang Keluar">
    <x-ui.page-header :title="$outbound->code" subtitle="Perbarui dokumen pengiriman yang masih berstatus draft."
                      :back="route('admin.outbounds.show', $outbound)">
        <x-slot name="actions">
            {{-- Dulu tertulis "mengubah dokumen mereset hasil scan", dan itu
                 memang yang terjadi — termasuk saat tidak ada yang diubah.
                 Sekarang hanya bagian yang benar-benar berganti yang gugur. --}}
            @if ($outbound->isMarketplace())
                <x-ui.badge variant="warning" icon="warning">Baris yang diubah perlu discan ulang</x-ui.badge>
            @endif
        </x-slot>
    </x-ui.page-header>


    @include('admin.outbounds.partials.form', [
        'outbound' => $outbound,
        'code' => $code,
        'products' => $products,
        'marketplaces' => $marketplaces,
        'defaultType' => $defaultType,
    ])
</x-app-layout>
