@php
    $outbound = $outbound ?? null;
    $isEdit = (bool) $outbound;
    $type = old('type', $defaultType);

    /*
        Paket punya barisnya sendiri, terpisah dari baris barang.

        Baris barang yang ditampilkan karenanya hanya yang dipesan lepas —
        sisanya setelah dikurangi apa yang dijanjikan paket. Tanpa pemisahan
        ini, menyunting dokumen berisi paket berarti menyunting isinya satu
        per satu, dan menyimpan akan menghitung barangnya dua kali.
    */
    $bundleCatalog = $products->filter(fn ($product) => $product->isBundle())->values();
    $itemCatalog = $products->reject(fn ($product) => $product->isBundle())->values();

    $bundleRows = collect(old('bundles', $outbound?->bundles
        ->map(fn ($bundle) => ['bundle_id' => $bundle->bundle_id, 'quantity' => $bundle->quantity])
        ->all() ?? []))->values();

    $lineItems = $outbound?->looseItems();
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.outbounds.update', $outbound) : route('admin.outbounds.store') }}"
      class="space-y-6"
      x-data="{ type: '{{ $type }}', tracking: '{{ old('tracking_number', $outbound?->tracking_number) }}' }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Jenis pengiriman --}}
            <x-ui.card title="Jenis Pengiriman" subtitle="Pesanan marketplace wajib melewati verifikasi scan sebelum dikirim.">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['value' => 'regular', 'icon' => 'box', 'label' => 'Reguler', 'desc' => 'Pengiriman langsung ke pelanggan atau cabang.'],
                        ['value' => 'marketplace', 'icon' => 'sparkles', 'label' => 'Marketplace', 'desc' => 'Wajib scan resi lalu scan barang.'],
                    ] as $option)
                        <label class="relative flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                               :class="type === '{{ $option['value'] }}' ? 'border-ink-950 bg-ink-950 text-white shadow-soft' : 'border-ink-100 hover:border-ink-200 hover:bg-ink-50/60'">
                            <input type="radio" name="type" value="{{ $option['value'] }}" x-model="type" class="sr-only">
                            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition"
                                  :class="type === '{{ $option['value'] }}' ? 'bg-white/10 text-white' : 'bg-ink-50 text-ink-950 ring-1 ring-ink-100'">
                                <x-icon :name="$option['icon']" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ $option['label'] }}</span>
                                <span class="block text-xs" :class="type === '{{ $option['value'] }}' ? 'text-white/60' : 'text-ink-500'">
                                    {{ $option['desc'] }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <x-input-error :messages="$errors->get('type')" class="mt-3" />
            </x-ui.card>

            {{-- Informasi dokumen --}}
            <x-ui.card title="Informasi Dokumen">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Nomor Dokumen" hint="Dibuat otomatis oleh sistem.">
                        <x-text-input type="text" :value="$code" class="font-mono" disabled />
                    </x-ui.field>

                    <x-ui.field label="Tanggal" for="date" :error="$errors->get('date')" required>
                        <x-text-input id="date" name="date" type="date"
                                      :value="old('date', $outbound?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                    </x-ui.field>

                    <x-ui.field label="Penerima" for="recipient" :error="$errors->get('recipient')" required
                                class="sm:col-span-2">
                        <x-text-input id="recipient" name="recipient" type="text" :value="old('recipient', $outbound?->recipient)"
                                      required placeholder="Nama pembeli, toko, atau cabang tujuan" />
                    </x-ui.field>

                    {{-- Field khusus marketplace --}}
                    <template x-if="type === 'marketplace'">
                        <div class="grid grid-cols-1 gap-5 sm:col-span-2 sm:grid-cols-2">
                            <x-ui.field label="Marketplace" for="marketplace" :error="$errors->get('marketplace')" required>
                                <x-ui.select id="marketplace" name="marketplace">
                                    <option value="">Pilih marketplace…</option>
                                    @foreach ($marketplaces as $marketplace)
                                        <option value="{{ $marketplace }}" @selected(old('marketplace', $outbound?->marketplace) === $marketplace)>
                                            {{ $marketplace }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field label="Nomor Resi" for="tracking_number" :error="$errors->get('tracking_number')"
                                        hint="Dicari otomatis ke data import Ginee." required class="sm:col-span-2">
                                <x-text-input id="tracking_number" name="tracking_number" type="text"
                                              x-model="tracking"
                                              class="font-mono uppercase" placeholder="SPXID01234567890" />

                                @include('admin.partials.resi-lookup')
                            </x-ui.field>
                        </div>
                    </template>

                    <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                        <textarea id="note" name="note" rows="2" placeholder="Catatan tambahan (opsional)"
                                  class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">{{ old('note', $outbound?->note) }}</textarea>
                    </x-ui.field>
                </div>
            </x-ui.card>
        </div>

        {{-- Aksi --}}
        <x-ui.card title="Aksi">
            <div class="space-y-3">
                <template x-if="type === 'marketplace'">
                    <div>
                        <x-ui.button type="submit" icon="search" class="w-full">
                            {{ $isEdit ? 'Simpan & Lanjut Scan' : 'Simpan & Mulai Scan' }}
                        </x-ui.button>
                        <p class="mt-3 flex items-start gap-2 rounded-xl bg-ink-950 p-3 text-[11px] leading-relaxed text-white/70">
                            <x-icon name="shield" class="mt-px h-3.5 w-3.5 shrink-0 text-white" />
                            @if ($isEdit)
                                Setelah disimpan, Anda kembali ke halaman verifikasi. Hasil scan yang masih cocok tetap tersimpan; hanya baris yang berubah — dan resi bila nomornya diganti — yang perlu diulang.
                            @else
                                Pesanan marketplace tidak bisa langsung diproses. Setelah disimpan, Anda diarahkan ke halaman verifikasi untuk scan resi lalu scan barang.
                            @endif
                        </p>
                    </div>
                </template>

                <template x-if="type === 'regular'">
                    <div class="space-y-3">
                        @can('outbounds.post')
                            <x-ui.button type="submit" name="submit" value="1" icon="check" class="w-full">
                                {{ auth()->user()->can('outbounds.approve') ? 'Simpan & Setujui' : 'Simpan & Ajukan' }}
                            </x-ui.button>
                        @endcan

                        <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                            Simpan sebagai Draft
                        </x-ui.button>
                    </div>
                </template>

                <x-ui.button :href="route('admin.outbounds.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        @include('admin.outbounds.partials.bundles', [
            'bundles' => $bundleCatalog,
            'rows' => $bundleRows,
        ])

        @include('admin.partials.line-items', [
            'products' => $itemCatalog,
            'items' => $lineItems,
            'mode' => 'outbound',
        ])
    </div>
</form>
