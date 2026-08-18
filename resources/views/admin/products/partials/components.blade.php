{{--
    Editor isi paket. Berada di dalam x-data="bundleRecipe(...)" milik
    formulir induknya, jadi tidak punya state sendiri.
--}}
<div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
        <div>
            <h2 class="text-sm font-semibold tracking-tight text-ink-950">Isi Paket</h2>
            <p class="mt-0.5 text-xs text-ink-500">
                <span x-text="filledRows"></span> barang &middot;
                <span class="font-medium text-ink-950" x-text="totalUnits"></span> unit per paket &middot;
                bisa dirakit <span class="font-medium text-ink-950" x-text="availability"></span>
            </p>
        </div>

        <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="add()">
            Tambah Barang
        </x-ui.button>
    </div>

    <x-input-error :messages="$errors->get('components')" class="px-5 pt-4 sm:px-6" />

    @if ($catalog->isEmpty())
        <x-ui.empty-state icon="box" title="Belum ada barang yang bisa dijadikan isi"
                          description="Paket hanya boleh berisi barang biasa. Tambahkan barangnya lebih dulu." />
    @else
        <div class="divide-y divide-ink-50" x-ref="rows">
            <template x-for="(row, index) in rows" :key="row.key">
                <div class="px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        {{-- Barang --}}
                        <div class="flex-1 space-y-1.5">
                            <label class="block text-xs font-medium text-ink-500" x-text="'Barang #' + (index + 1)"></label>
                            <select x-model="row.component_id" :name="`components[${index}][component_id]`"
                                    class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                                <option value="">Pilih barang…</option>
                                <template x-for="item in catalog" :key="item.id">
                                    <option :value="item.id" x-text="`${item.sku} — ${item.name}`"></option>
                                </template>
                            </select>

                            <template x-if="isDuplicate(row)">
                                <p class="flex items-center gap-1.5 text-[11px] font-medium text-red-600">
                                    <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                                    Barang ini sudah ada di baris lain. Gabungkan jumlahnya menjadi satu baris.
                                </p>
                            </template>

                            <template x-if="component(row)">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                        <span class="opacity-50">SKU</span>
                                        <span x-text="component(row).sku"></span>
                                    </span>
                                    <span class="text-[11px] text-ink-400"
                                          x-text="`Stok tersedia: ${component(row).stock} ${component(row).unit}`"></span>
                                </p>
                            </template>
                        </div>

                        {{-- Jumlah per paket --}}
                        <div class="w-full space-y-1.5 sm:w-36">
                            <label class="block text-xs font-medium text-ink-500">Per paket</label>
                            <input type="number" min="1" x-model.number="row.quantity" :name="`components[${index}][quantity]`"
                                   class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                        </div>

                        {{-- Berapa paket yang bisa dibentuk dari barang ini saja --}}
                        <div class="w-full space-y-1.5 sm:w-32">
                            <label class="block text-xs font-medium text-ink-500">Cukup untuk</label>
                            <p class="flex h-[2.625rem] items-center justify-center rounded-xl text-sm font-semibold ring-1 ring-inset"
                               :class="setsFrom(row) === null ? 'bg-ink-50 text-ink-400 ring-ink-100'
                                    : (isBottleneck(row) ? 'bg-amber-50 text-amber-700 ring-amber-100' : 'bg-ink-50 text-ink-600 ring-ink-100')"
                               x-text="setsFrom(row) === null ? '—' : `${setsFrom(row)} paket`"></p>
                        </div>

                        {{-- Hapus baris --}}
                        <div class="flex justify-end sm:pt-6">
                            <button type="button" x-on:click="remove(index)" title="Hapus baris"
                                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-ink-400 transition hover:bg-red-50 hover:text-red-600">
                                <x-icon name="trash" class="h-4 w-4" />
                                <span class="sr-only">Hapus baris</span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{--
            Yang membatasi disebut namanya. Tanpa ini, angka "bisa dirakit"
            hanya memberi tahu bahwa paketnya sedikit, bukan barang mana yang
            harus dipesan supaya bertambah.
        --}}
        <template x-if="filledRows > 1 && availability >= 0">
            <div class="flex items-start gap-2.5 border-t border-ink-100 bg-ink-50/60 px-5 py-3.5 text-xs text-ink-600 sm:px-6">
                <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-ink-300" />
                <span>
                    Paket ini bisa dirakit sebanyak <span class="font-semibold text-ink-950" x-text="availability"></span> kali —
                    dibatasi oleh barang yang bertanda kuning. Menambah stok barang lain tidak menaikkan angka itu.
                </span>
            </div>
        </template>

        <template x-if="hasDuplicate">
            <div class="flex items-start gap-2.5 border-t border-red-100 bg-red-50 px-5 py-3.5 text-sm text-red-700 sm:px-6">
                <x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Ada barang yang disebut lebih dari sekali. Jumlah per paket harus satu angka, jadi gabungkan barisnya.</span>
            </div>
        </template>
    @endif
</div>
