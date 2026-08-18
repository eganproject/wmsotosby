@php
    // $bundles: paket aktif berikut ketersediaannya, $rows: baris yang sudah ada
    $catalog = $bundles->map(fn ($bundle) => [
        'id' => $bundle->id,
        'sku' => $bundle->sku,
        'name' => $bundle->name,
        'unit' => $bundle->unit,
        // Sudah dikurangi pesanan lain yang belum diproses.
        'available' => $bundle->availableStock(),
        // Unit barang per satu paket, untuk menghitung isi seluruh dokumen.
        'units' => (int) $bundle->bundleComponents->sum('quantity'),
    ])->values();
@endphp

<div x-data="outboundBundles({{ Js::from($rows) }}, {{ Js::from($catalog) }})"
     class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
        <div>
            <h2 class="text-sm font-semibold tracking-tight text-ink-950">Paket Bundling</h2>
            <p class="mt-0.5 text-xs text-ink-500">
                <template x-if="filledRows">
                    <span>
                        <span x-text="filledRows"></span> paket &middot;
                        <span class="font-medium text-ink-950" x-text="totalPackages"></span> dipesan &middot;
                        menghasilkan <span class="font-medium text-ink-950" x-text="totalUnits"></span> unit barang
                    </span>
                </template>
                <template x-if="! filledRows">
                    <span>Opsional. Paket dipecah menjadi barang isinya saat dokumen disimpan.</span>
                </template>
            </p>
        </div>

        <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="add()">
            Tambah Paket
        </x-ui.button>
    </div>

    <x-input-error :messages="$errors->get('bundles')" class="px-5 pt-4 sm:px-6" />

    @if ($bundles->isEmpty())
        <div class="flex items-start gap-2.5 px-5 py-4 text-xs text-ink-500 sm:px-6">
            <x-icon name="info" class="mt-px h-4 w-4 shrink-0 text-ink-300" />
            <span>
                Belum ada paket bundling yang aktif. Susun dulu di
                <a href="{{ route('admin.products.index', ['type' => 'bundle']) }}" class="font-medium text-ink-950 underline-offset-4 hover:underline">master barang</a>.
            </span>
        </div>
    @else
        <div class="divide-y divide-ink-50" x-ref="rows">
            <template x-for="(row, index) in rows" :key="row.key">
                <div class="px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div class="flex-1 space-y-1.5">
                            <label class="block text-xs font-medium text-ink-500" x-text="'Paket #' + (index + 1)"></label>
                            <select x-model="row.bundle_id" :name="`bundles[${index}][bundle_id]`"
                                    class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                                <option value="">Pilih paket…</option>
                                <template x-for="item in catalog" :key="item.id">
                                    <option :value="item.id" x-text="`${item.sku} — ${item.name}`"></option>
                                </template>
                            </select>

                            <template x-if="isDuplicate(row)">
                                <p class="flex items-center gap-1.5 text-[11px] font-medium text-red-600">
                                    <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                                    Paket ini sudah ada di baris lain. Gabungkan jumlahnya menjadi satu baris.
                                </p>
                            </template>

                            <template x-if="bundle(row)">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                        <span class="opacity-50">SKU</span>
                                        <span x-text="bundle(row).sku"></span>
                                    </span>
                                    <span class="text-[11px] text-ink-400"
                                          x-text="`Masih bisa dijanjikan: ${bundle(row).available} · berisi ${bundle(row).units} unit barang`"></span>
                                </p>
                            </template>
                        </div>

                        <div class="w-full space-y-1.5 sm:w-36">
                            <label class="block text-xs font-medium text-ink-500">Jumlah</label>
                            <input type="number" min="1" x-model.number="row.quantity" :name="`bundles[${index}][quantity]`"
                                   class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950"
                                   :class="isOverAvailable(row) && 'border-red-300 focus:border-red-500 focus:ring-red-500'">
                        </div>

                        <div class="flex justify-end sm:pt-6">
                            <button type="button" x-on:click="remove(index)" title="Hapus baris"
                                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-ink-400 transition hover:bg-red-50 hover:text-red-600">
                                <x-icon name="trash" class="h-4 w-4" />
                                <span class="sr-only">Hapus baris</span>
                            </button>
                        </div>
                    </div>

                    <template x-if="isOverAvailable(row)">
                        <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-red-600">
                            <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                            <span x-text="`Melebihi yang masih bisa dijanjikan (${bundle(row).available}). Angka itu sudah dikurangi pesanan lain yang belum diproses.`"></span>
                        </p>
                    </template>
                </div>
            </template>
        </div>

        <template x-if="! rows.length">
            <div class="flex items-start gap-2.5 px-5 py-4 text-xs text-ink-500 sm:px-6">
                <x-icon name="info" class="mt-px h-4 w-4 shrink-0 text-ink-300" />
                <span>
                    Belum ada paket pada dokumen ini. Barang yang dipesan lepas cukup diisi di baris barang di bawah.
                </span>
            </div>
        </template>
    @endif
</div>
