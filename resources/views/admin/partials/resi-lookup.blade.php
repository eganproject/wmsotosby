{{--
    Panel pencarian nomor resi ke data import Ginee.
    Ditempel di bawah input nomor resi pada form barang keluar dan retur.
--}}
{{-- `tracking` berasal dari x-data form induk. --}}
<div x-data="resiLookup({ url: '{{ route('admin.imports.lookup') }}' })"
     x-effect="schedule(tracking)"
     class="mt-3">

    {{-- Sedang mencari --}}
    <template x-if="state === 'searching'">
        <p class="flex items-center gap-2 rounded-xl bg-ink-50 p-3 text-[11px] text-ink-500">
            <span class="h-3 w-3 animate-spin rounded-full border-2 border-ink-300 border-t-transparent"></span>
            Mencari resi di data import…
        </p>
    </template>

    {{-- Ketemu --}}
    <template x-if="state === 'found'">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <div class="flex items-start gap-2.5">
                <x-icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold text-emerald-900">
                        Resi ditemukan di data import
                        <span class="font-normal" x-text="order.order_number ? `· ${order.order_number}` : ''"></span>
                    </p>
                    <p class="mt-0.5 text-[11px] text-emerald-800"
                       x-text="[order.marketplace, order.store_name, order.buyer_name].filter(Boolean).join(' · ')"></p>

                    {{-- Isi pesanan, SKU sebagai identitas utama --}}
                    <div class="mt-2.5 space-y-1">
                        <template x-for="item in order.items" :key="item.sku">
                            <div class="flex flex-wrap items-center gap-1.5 rounded-lg bg-white px-2 py-1.5 ring-1 ring-inset"
                                 :class="item.matched ? 'ring-emerald-200' : 'ring-red-200'">
                                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 ring-1 ring-inset"
                                      :class="item.matched ? 'bg-ink-100 text-ink-800 ring-ink-200' : 'bg-red-50 text-red-700 ring-red-200'">
                                    <span class="opacity-50">SKU</span>
                                    <span x-text="item.sku"></span>
                                </span>
                                <span class="min-w-0 flex-1 truncate text-[11px] text-ink-600" x-text="item.product_name"></span>
                                <span class="shrink-0 text-[11px] font-semibold text-ink-950" x-text="`${item.quantity}×`"></span>
                            </div>
                        </template>
                    </div>

                    <template x-if="! order.matched">
                        <p class="mt-2 text-[11px] font-medium text-red-700">
                            SKU berikut belum terdaftar di master barang:
                            <span class="font-mono" x-text="unmatchedSkus.join(', ')"></span>.
                            Daftarkan dulu agar barangnya bisa ditarik otomatis.
                        </p>
                    </template>

                    <template x-if="order.matched">
                        <button type="button" x-on:click="apply()"
                                class="mt-2.5 inline-flex h-8 items-center gap-1.5 rounded-lg bg-ink-950 px-3 text-xs font-medium text-white transition hover:bg-ink-800">
                            <x-icon name="refresh" class="h-3.5 w-3.5" />
                            Ambil Barang dari Pesanan Ini
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- Tidak ketemu --}}
    <template x-if="state === 'missing'">
        <p class="flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
            <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
            Resi ini belum ada di data import Ginee. Baris barang silakan diisi manual di bawah.
        </p>
    </template>

    <template x-if="state === 'error'">
        <p class="flex items-start gap-2 rounded-xl bg-red-50 p-3 text-[11px] text-red-700 ring-1 ring-inset ring-red-200">
            <x-icon name="x-circle" class="mt-px h-3.5 w-3.5 shrink-0" />
            <span x-text="message"></span>
        </p>
    </template>
</div>
