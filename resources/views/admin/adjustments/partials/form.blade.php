@php
    $adjustment = $adjustment ?? null;
    $isEdit = (bool) $adjustment;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.adjustments.update', $adjustment) : route('admin.adjustments.store') }}"
      class="space-y-6">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Informasi Dokumen"
                   subtitle="Catat alasan penyesuaian agar selisihnya bisa ditelusuri kemudian.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.field label="Nomor Dokumen" hint="Dibuat otomatis oleh sistem.">
                    <x-text-input type="text" :value="$code" class="font-mono" disabled />
                </x-ui.field>

                <x-ui.field label="Tanggal Hitung" for="date" :error="$errors->get('date')" required>
                    <x-text-input id="date" name="date" type="date"
                                  :value="old('date', $adjustment?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                </x-ui.field>

                <x-ui.field label="Alasan Penyesuaian" for="reason" :error="$errors->get('reason')" required
                            class="sm:col-span-2">
                    <x-ui.select id="reason" name="reason">
                        <option value="">Pilih alasan…</option>
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason }}" @selected(old('reason', $adjustment?->reason) === $reason)>{{ $reason }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                    <textarea id="note" name="note" rows="2" placeholder="Siapa yang menghitung, lokasi rak, dsb. (opsional)"
                              class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">{{ old('note', $adjustment?->note) }}</textarea>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Aksi">
            <div class="space-y-3">
                @can('adjustments.post')
                    <x-ui.button type="submit" name="submit" value="1"
                                 :icon="auth()->user()->can('adjustments.approve') ? 'check' : 'clock'" class="w-full">
                        {{ auth()->user()->can('adjustments.approve') ? 'Simpan & Terapkan' : 'Simpan & Ajukan' }}
                    </x-ui.button>
                @endcan

                <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                    Simpan sebagai Draft
                </x-ui.button>

                <x-ui.button :href="route('admin.adjustments.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>

            <p class="mt-4 flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                Stok baru berubah setelah dokumen disetujui. Selisihnya dicatat di kartu stok tiap barang,
                jadi penyesuaian tetap bisa ditelusuri.
            </p>
        </x-ui.card>
    </div>

    @include('admin.partials.line-items', [
        'products' => $products,
        'items' => $adjustment?->items,
        'mode' => 'adjustment',
    ])
</form>
