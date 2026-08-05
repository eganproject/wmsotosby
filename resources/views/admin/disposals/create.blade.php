{{--
    Menangani barang rusak.

    Daftarnya hanya berisi barang yang memang punya stok rusak — tidak mungkin
    membuang sesuatu yang tidak pernah tercatat rusak, jadi tidak ada gunanya
    menawarkan seluruh master barang di sini.
--}}
<x-app-layout title="Tangani Barang Rusak">
    <x-ui.page-header title="Tangani Barang Rusak" icon="trash"
                      :subtitle="'Nomor dokumen '.$code"
                      :back="route('admin.disposals.index')" />

    @if ($products->isEmpty())
        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
            <x-ui.empty-state icon="check-circle" title="Tidak ada barang rusak"
                              description="Belum ada unit rusak yang tercatat. Barang rusak masuk otomatis dari penerimaan retur.">
                <x-slot name="action">
                    <x-ui.button :href="route('admin.disposals.index')" variant="secondary">Kembali</x-ui.button>
                </x-slot>
            </x-ui.empty-state>
        </div>
    @else
        <form method="POST" action="{{ route('admin.disposals.store') }}" class="space-y-5"
              x-data="{ action: '{{ old('action', \App\Models\DamagedDisposal::ACTION_DISCARD) }}' }">
            @csrf

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="space-y-5 lg:col-span-2">
                    <x-ui.card title="Barang yang Ditangani"
                               subtitle="Isi jumlahnya; kosongkan yang belum ditangani." padding="p-0">
                        <ul class="divide-y divide-ink-50">
                            @foreach ($products as $product)
                                <li class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-4">
                                    <div class="min-w-0 flex-1">
                                        <x-ui.sku :value="$product->sku" />
                                        <p class="mt-1 truncate text-sm font-medium text-ink-950">{{ $product->name }}</p>
                                        <p class="text-[11px] text-ink-400">
                                            Tersedia {{ $product->damaged_stock }} {{ $product->unit }} rusak
                                        </p>
                                    </div>

                                    <div class="w-full sm:w-32">
                                        <input type="number" min="0" max="{{ $product->damaged_stock }}" inputmode="numeric"
                                               name="quantities[{{ $product->id }}]"
                                               value="{{ old('quantities.'.$product->id) }}"
                                               placeholder="0"
                                               class="h-12 w-full rounded-xl border-ink-200 text-center text-base font-semibold tabular-nums shadow-sm focus:border-ink-950 focus:ring-ink-950 sm:h-11 sm:text-sm">
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>

                    <x-input-error :messages="$errors->get('quantities')" />
                </div>

                <div class="space-y-5">
                    <x-ui.card title="Tindakan" subtitle="Menentukan ke mana barangnya pergi.">
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="date" value="Tanggal" />
                                <x-text-input id="date" name="date" type="date" class="mt-1.5 w-full"
                                              :value="old('date', now()->toDateString())" required />
                                <x-input-error :messages="$errors->get('date')" class="mt-1.5" />
                            </div>

                            <div class="space-y-2">
                                @foreach (\App\Models\DamagedDisposal::actions() as $value => $label)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition
                                                  has-[:checked]:border-ink-950 has-[:checked]:bg-ink-50/70 border-ink-100">
                                        <input type="radio" name="action" value="{{ $value }}" x-model="action" required
                                               class="mt-0.5 h-4 w-4 shrink-0 border-ink-300 text-ink-950 focus:ring-ink-950">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-ink-950">{{ $label }}</span>
                                            @if ($value === \App\Models\DamagedDisposal::ACTION_REPAIR)
                                                <span class="block text-[11px] leading-relaxed text-ink-500">
                                                    Unitnya kembali ke saldo layak jual.
                                                </span>
                                            @else
                                                <span class="block text-[11px] leading-relaxed text-ink-500">
                                                    Unitnya keluar dari gudang untuk selamanya.
                                                </span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                                <x-input-error :messages="$errors->get('action')" class="mt-1" />
                            </div>

                            <div x-show="action === '{{ \App\Models\DamagedDisposal::ACTION_RETURN }}'" x-cloak>
                                <x-input-label for="supplier_id" value="Pemasok tujuan" />
                                <x-ui.select id="supplier_id" name="supplier_id" class="mt-1.5 w-full">
                                    <option value="">Pilih pemasok…</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                                            {{ $supplier->name }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div>
                                <x-input-label for="note" value="Catatan (opsional)" />
                                <textarea id="note" name="note" rows="2"
                                          class="mt-1.5 w-full rounded-xl border-ink-200 text-sm shadow-sm focus:border-ink-950 focus:ring-ink-950"
                                          placeholder="Misal: nomor berita acara pemusnahan.">{{ old('note') }}</textarea>
                            </div>
                        </div>
                    </x-ui.card>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <x-ui.button type="submit" icon="check" class="flex-1">Simpan Dokumen</x-ui.button>
                        <x-ui.button :href="route('admin.disposals.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    @endif
</x-app-layout>
