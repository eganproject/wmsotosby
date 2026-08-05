@php
    $inbound = $inbound ?? null;
    $isEdit = (bool) $inbound;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.inbounds.update', $inbound) : route('admin.inbounds.store') }}"
      class="space-y-6">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Informasi Dokumen" subtitle="Data penerimaan barang dari pemasok.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.field label="Nomor Dokumen" hint="Dibuat otomatis oleh sistem.">
                    <x-text-input type="text" :value="$code" class="font-mono" disabled />
                </x-ui.field>

                <x-ui.field label="Tanggal" for="date" :error="$errors->get('date')" required>
                    <x-text-input id="date" name="date" type="date"
                                  :value="old('date', $inbound?->date?->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
                </x-ui.field>

                <x-ui.field label="Pemasok" for="supplier_id" :error="$errors->get('supplier_id')">
                    <x-ui.select id="supplier_id" name="supplier_id" data-placeholder="Pilih pemasok…">
                        <option value="">Pilih pemasok…</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $inbound?->supplier_id) == $supplier->id)>
                                {{ $supplier->code }} — {{ $supplier->name }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @can('suppliers.create')
                        <p class="text-xs text-ink-400">
                            Pemasok belum terdaftar?
                            <a href="{{ route('admin.suppliers.create') }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">Tambah pemasok</a>
                        </p>
                    @endcan
                </x-ui.field>

                <x-ui.field label="Nomor Referensi" for="reference" :error="$errors->get('reference')"
                            hint="Nomor surat jalan / faktur pemasok.">
                    <x-text-input id="reference" name="reference" type="text" :value="old('reference', $inbound?->reference)"
                                  class="font-mono" placeholder="SJ-00123" />
                </x-ui.field>

                <x-ui.field label="Catatan" for="note" :error="$errors->get('note')" class="sm:col-span-2">
                    <textarea id="note" name="note" rows="2" placeholder="Catatan tambahan (opsional)"
                              class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">{{ old('note', $inbound?->note) }}</textarea>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Aksi" subtitle="Simpan sebagai draft atau langsung tambahkan ke stok.">
            <div class="space-y-3">
                @can('inbounds.post')
                    <x-ui.button type="submit" name="submit" value="1"
                                 :icon="auth()->user()->can('inbounds.approve') ? 'check' : 'clock'" class="w-full">
                        {{ auth()->user()->can('inbounds.approve') ? 'Simpan & Setujui' : 'Simpan & Ajukan' }}
                    </x-ui.button>
                @endcan

                <x-ui.button type="submit" variant="secondary" icon="document" class="w-full">
                    Simpan sebagai Draft
                </x-ui.button>

                <x-ui.button :href="route('admin.inbounds.index')" variant="ghost" class="w-full">Batal</x-ui.button>
            </div>

            <p class="mt-4 flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-[11px] leading-relaxed text-ink-500">
                <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                Stok hanya bertambah setelah dokumen disetujui. Draft masih bisa diubah kapan saja.
            </p>
        </x-ui.card>
    </div>

    @include('admin.partials.line-items', [
        'products' => $products,
        'items' => $inbound?->items,
        'mode' => 'inbound',
    ])
</form>
