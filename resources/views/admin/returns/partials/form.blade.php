@php
    $return = $return ?? null;
    $isEdit = (bool) $return;
    $type = old('type', $defaultType);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.returns.update', $return) : route('admin.returns.store') }}"
      class="space-y-6"
      x-data="{ type: '{{ $type }}', tracking: '{{ old('tracking_number', $return?->tracking_number) }}' }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Asal retur --}}
            <x-ui.card title="Asal Retur" subtitle="Menentukan data apa saja yang wajib diisi dan bagaimana resinya diverifikasi.">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['value' => 'marketplace', 'icon' => 'sparkles', 'label' => 'Marketplace', 'desc' => 'Retur dari Shopee, Tokopedia, dan sejenisnya. Resi wajib discan.'],
                        ['value' => 'regular', 'icon' => 'box', 'label' => 'Non-marketplace', 'desc' => 'Retur dari pelanggan atau bengkel langsung.'],
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

                    <x-ui.field label="Tanggal Terima" for="date" :error="$errors->get('date')" required>
                        <x-text-input id="date" name="date" type="date"
                                      :value="old('date', $return?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                    </x-ui.field>

                    <x-ui.field label="Pengirim" for="sender" :error="$errors->get('sender')" required>
                        <x-text-input id="sender" name="sender" type="text" :value="old('sender', $return?->sender)"
                                      required placeholder="Nama pembeli atau pelanggan" />
                    </x-ui.field>

                    <x-ui.field label="Nomor Pesanan" for="reference" :error="$errors->get('reference')"
                                hint="Nomor pesanan atau dokumen pengiriman asal.">
                        <x-text-input id="reference" name="reference" type="text" :value="old('reference', $return?->reference)"
                                      class="font-mono" placeholder="OUT-202608-0001" />
                    </x-ui.field>

                    {{-- Marketplace hanya untuk retur marketplace --}}
                    <template x-if="type === 'marketplace'">
                        <div class="space-y-1.5">
                            <label for="marketplace" class="block text-sm font-medium text-ink-800">
                                Marketplace <span class="text-red-500">*</span>
                            </label>
                            <x-ui.select id="marketplace" name="marketplace">
                                <option value="">Pilih marketplace…</option>
                                @foreach ($marketplaces as $marketplace)
                                    <option value="{{ $marketplace }}" @selected(old('marketplace', $return?->marketplace) === $marketplace)>
                                        {{ $marketplace }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                            <x-input-error :messages="$errors->get('marketplace')" />
                        </div>
                    </template>

                    <x-ui.field label="Alasan Retur" for="reason" :error="$errors->get('reason')">
                        <x-ui.select id="reason" name="reason">
                            <option value="">Pilih alasan…</option>
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason }}" @selected(old('reason', $return?->reason) === $reason)>{{ $reason }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                        <textarea id="note" name="note" rows="2" placeholder="Kondisi paket saat diterima, dsb. (opsional)"
                                  class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">{{ old('note', $return?->note) }}</textarea>
                    </x-ui.field>
                </div>
            </x-ui.card>

            {{-- Resi retur --}}
            <x-ui.card title="Resi Retur"
                       subtitle="Nomor pada paket yang dikembalikan. Nomor ini yang nanti harus discan.">
                <x-ui.field for="tracking_number" :error="$errors->get('tracking_number')">
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                            <x-icon name="document" class="h-4 w-4" />
                        </span>
                        <x-text-input id="tracking_number" name="tracking_number" type="text"
                                      x-model="tracking"
                                      class="pl-10 font-mono uppercase" placeholder="SPXID01234567890" />
                    </div>
                </x-ui.field>

                @include('admin.partials.resi-lookup')

                <template x-if="type === 'marketplace'">
                    <p class="mt-3 flex items-start gap-2 rounded-xl bg-ink-950 p-3 text-[11px] leading-relaxed text-white/70">
                        <x-icon name="shield" class="mt-px h-3.5 w-3.5 shrink-0 text-white" />
                        Retur marketplace wajib memiliki nomor resi dan harus diverifikasi lewat scan sebelum barang diterima ke stok.
                    </p>
                </template>

                <template x-if="type === 'regular' && tracking.trim()">
                    <p class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-[11px] leading-relaxed text-amber-800 ring-1 ring-inset ring-amber-200">
                        <x-icon name="key" class="mt-px h-3.5 w-3.5 shrink-0" />
                        Karena nomor resi diisi, dokumen ini juga wajib melewati scan resi sebelum diterima.
                    </p>
                </template>

                <template x-if="type === 'regular' && ! tracking.trim()">
                    <p class="mt-3 flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
                        <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                        Kosongkan bila barang diantar langsung ke gudang tanpa kurir — dokumen bisa langsung diterima tanpa scan.
                    </p>
                </template>
            </x-ui.card>
        </div>

        {{-- Aksi --}}
        <x-ui.card title="Aksi">
            <div class="space-y-3">
                <template x-if="type === 'marketplace' || tracking.trim()">
                    <x-ui.button type="submit" icon="search" class="w-full">
                        Simpan &amp; Scan Resi
                    </x-ui.button>
                </template>

                <template x-if="type === 'regular' && ! tracking.trim()">
                    <div class="space-y-3">
                        @can('returns.post')
                            <x-ui.button type="submit" name="submit" value="1" icon="check" class="w-full">
                                {{ auth()->user()->can('returns.approve') ? 'Simpan & Setujui' : 'Simpan & Ajukan' }}
                            </x-ui.button>
                        @endcan

                        <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                            Simpan sebagai Draft
                        </x-ui.button>
                    </div>
                </template>

                <x-ui.button :href="route('admin.returns.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>

            <p class="mt-4 flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                Hanya barang berkondisi <span class="font-medium text-ink-700">layak jual</span> yang kembali menambah stok. Barang rusak tetap tercatat tetapi tidak masuk stok siap jual.
            </p>
        </x-ui.card>
    </div>

    @include('admin.partials.line-items', [
        'products' => $products,
        'items' => $return?->items,
        'mode' => 'return',
    ])
</form>
