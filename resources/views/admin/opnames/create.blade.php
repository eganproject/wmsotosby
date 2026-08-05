{{--
    Membuka sesi opname. Cakupan dipilih di awal karena isinya langsung
    dipotret — menghitung seluruh gudang dan satu rak adalah pekerjaan yang
    sangat berbeda, dan itu keputusan yang harus diambil sebelum mulai.
--}}
<x-app-layout title="Buka Sesi Opname">
    <x-ui.page-header title="Buka Sesi Opname" icon="cube"
                      :subtitle="'Nomor sesi '.$code.' — isinya dipotret saat sesi dibuka.'"
                      :back="route('admin.opnames.index')" />

    <form method="POST" action="{{ route('admin.opnames.store') }}" class="mx-auto max-w-3xl space-y-5"
          x-data="{ scope: '{{ old('scope', 'all') }}' }">
        @csrf

        <x-ui.card title="Cakupan Hitung" subtitle="Barang yang akan masuk ke daftar hitung sesi ini.">
            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="date" value="Tanggal opname" />
                        <x-text-input id="date" name="date" type="date" class="mt-1.5 w-full"
                                      :value="old('date', now()->toDateString())" required />
                        <x-input-error :messages="$errors->get('date')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="scope" value="Cakupan" />
                        <x-ui.select id="scope" name="scope" class="mt-1.5 w-full" x-model="scope">
                            @foreach (\App\Models\StockOpname::scopes() as $value => $label)
                                <option value="{{ $value }}" @selected(old('scope') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('scope')" class="mt-1.5" />
                    </div>
                </div>

                <div x-show="scope === 'category'" x-cloak>
                    <x-input-label for="scope_value_category" value="Kategori" />
                    <x-ui.select id="scope_value_category" name="scope_value" class="mt-1.5 w-full"
                                 x-bind:disabled="scope !== 'category'">
                        <option value="">Pilih kategori…</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('scope_value') === $category)>{{ $category }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div x-show="scope === 'location'" x-cloak>
                    <x-input-label for="scope_value_location" value="Lokasi rak" />
                    <x-ui.select id="scope_value_location" name="scope_value" class="mt-1.5 w-full"
                                 x-bind:disabled="scope !== 'location'">
                        <option value="">Pilih lokasi…</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location }}" @selected(old('scope_value') === $location)>{{ $location }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-input-error :messages="$errors->get('scope_value')" class="mt-1.5" />

                <div>
                    <x-input-label for="note" value="Catatan (opsional)" />
                    <textarea id="note" name="note" rows="2"
                              class="mt-1.5 w-full rounded-xl border-ink-200 text-sm shadow-sm focus:border-ink-950 focus:ring-ink-950"
                              placeholder="Misal: opname rutin akhir bulan, tim gudang shift pagi.">{{ old('note') }}</textarea>
                    <x-input-error :messages="$errors->get('note')" class="mt-1.5" />
                </div>

                <p class="flex items-start gap-2 rounded-xl bg-ink-50 px-4 py-3 text-xs leading-relaxed text-ink-500">
                    <x-icon name="info" class="mt-px h-4 w-4 shrink-0 text-ink-400" />
                    <span>
                        Saldo tercatat tiap barang ikut dipotret saat sesi dibuka, jadi selisihnya tetap
                        terbaca meski stok berubah di tengah penghitungan. Stok baru bergerak setelah
                        hasilnya disetujui.
                    </span>
                </p>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button :href="route('admin.opnames.index')" variant="ghost">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check">Buka Sesi &amp; Mulai Hitung</x-ui.button>
        </div>
    </form>
</x-app-layout>
