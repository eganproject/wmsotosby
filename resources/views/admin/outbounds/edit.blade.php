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

    {{--
        Editor baris bekerja atas barang, dan paket sudah dipecah menjadi
        barang sejak dokumennya dibentuk — jadi menyimpan dari sini menuliskan
        ulang barisnya tanpa keterangan paketnya.

        Disebut lebih dulu, bukan disembunyikan: barangnya tetap benar dan
        stoknya tidak berubah, tetapi keterangan asal-usul yang muncul di
        halaman detail akan hilang. Pilih paketnya lagi dari daftar barang
        bila keterangan itu memang ingin dipertahankan.
    --}}
    @if ($outbound->bundles->isNotEmpty())
        <div class="mb-6 flex items-start gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>
                Dokumen ini berasal dari
                <span class="font-semibold">{{ $outbound->bundles->count() }} paket bundling</span>
                yang sudah dipecah menjadi baris barang di bawah. Menyimpan dari halaman ini menghapus keterangan
                paketnya — barang dan jumlahnya tetap sama. Pilih SKU paketnya lagi dari daftar barang bila
                keterangan itu ingin dipertahankan.
            </span>
        </div>
    @endif

    @include('admin.outbounds.partials.form', [
        'outbound' => $outbound,
        'code' => $code,
        'products' => $products,
        'marketplaces' => $marketplaces,
        'defaultType' => $defaultType,
    ])
</x-app-layout>
