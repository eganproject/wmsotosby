@php
    // $products: koleksi barang aktif, $items: baris yang sudah ada, $mode: inbound|outbound
    $mode = $mode ?? 'inbound';
    $checkStock = $mode === 'outbound';
    $withCondition = $mode === 'return';
    $withActual = $mode === 'adjustment';

    $catalog = $products->map(fn ($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'sku' => $product->sku,
        'unit' => $product->unit,
        'stock' => $product->stock,
    ])->values();

    // Rincian rusak/hilang hanya ada pada baris retur.
    $initial = collect(old('items', ($items ?? collect())->map(fn ($item) => [
        'product_id' => $item->product_id,
        'quantity' => $item->quantity,
        'damaged' => $withCondition ? $item->damaged_quantity : 0,
        'missing' => $withCondition ? $item->missingQuantity() : 0,
        'actual' => $withActual ? $item->actual_quantity : 0,
        'note' => $item->note,
    ])->all()))->values();
@endphp

<div x-data="lineItems({{ Js::from($initial) }}, {{ Js::from($catalog) }})"
     class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
        <div>
            <h2 class="text-sm font-semibold tracking-tight text-ink-950">Baris Barang</h2>
            <p class="mt-0.5 text-xs text-ink-500">
                <span x-text="filledRows"></span> baris &middot; total <span class="font-medium text-ink-950" x-text="totalQuantity"></span> unit
                @if ($withActual)
                    &middot; <span class="font-medium text-ink-950" x-text="changedRows"></span> baris berselisih
                    &middot; <span class="font-medium text-emerald-600" x-text="'+' + increaseQuantity"></span>
                    &middot; <span class="font-medium text-red-600" x-text="'-' + decreaseQuantity"></span>
                @endif
                @if ($withCondition)
                    &middot; <span class="font-medium text-emerald-600" x-text="goodQuantity"></span> layak jual
                    &middot; <span class="font-medium text-red-600" x-text="damagedQuantity"></span> rusak
                    &middot; <span class="font-medium text-amber-600" x-text="missingQuantity"></span> hilang
                @endif
            </p>
        </div>

        <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="add()">
            Tambah Baris
        </x-ui.button>
    </div>

    <x-input-error :messages="$errors->get('items')" class="px-5 pt-4 sm:px-6" />

    @if ($products->isEmpty())
        <x-ui.empty-state icon="box" title="Belum ada barang aktif"
                          description="Tambahkan barang di menu Barang &amp; Stok sebelum membuat dokumen.">
            @can('products.create')
                <x-slot name="action">
                    <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
                </x-slot>
            @endcan
        </x-ui.empty-state>
    @else
        <div class="divide-y divide-ink-50" x-ref="rows">
            <template x-for="(row, index) in rows" :key="row.key">
                <div class="px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        {{-- Barang --}}
                        <div class="flex-1 space-y-1.5">
                            <label class="block text-xs font-medium text-ink-500" x-text="'Barang #' + (index + 1)"></label>
                            <select x-model="row.product_id" :name="`items[${index}][product_id]`"
                                    class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                                <option value="">Pilih barang…</option>
                                <template x-for="product in products" :key="product.id">
                                    <option :value="product.id" x-text="`${product.sku} — ${product.name}`"></option>
                                </template>
                            </select>

                            <template x-if="isDuplicate(row)">
                                <p class="flex items-center gap-1.5 text-[11px] font-medium text-amber-600">
                                    <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
                                    Barang ini juga ada di baris lain — jumlahnya akan digabung.
                                </p>
                            </template>

                            <template x-if="product(row)">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold leading-4 text-ink-800 ring-1 ring-inset ring-ink-200">
                                        <span class="opacity-50">SKU</span>
                                        <span x-text="product(row).sku"></span>
                                    </span>
                                    <span class="text-[11px] text-ink-400"
                                          x-text="`Stok tersedia: ${product(row).stock} ${product(row).unit}`"></span>
                                </p>
                            </template>
                        </div>

                        {{-- Jumlah --}}
                        @unless ($withActual)
                        <div class="w-full space-y-1.5 sm:w-36">
                            <label class="block text-xs font-medium text-ink-500">Jumlah</label>
                            <input type="number" min="1" x-model.number="row.quantity" :name="`items[${index}][quantity]`"
                                   class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950"
                                   @if ($checkStock) :class="isOverStock(row) && 'border-red-300 focus:border-red-500 focus:ring-red-500'" @endif>
                        </div>
                        @endunless

                        {{-- Hasil hitung fisik vs saldo tercatat --}}
                        @if ($withActual)
                            <div class="w-full space-y-1.5 sm:w-28">
                                <label class="block text-xs font-medium text-ink-500">Tercatat</label>
                                <p class="flex h-[2.625rem] items-center justify-center rounded-xl bg-ink-50 text-sm font-semibold text-ink-500 ring-1 ring-inset ring-ink-100"
                                   x-text="product(row) ? systemOf(row) : '—'"></p>
                            </div>

                            <div class="w-full space-y-1.5 sm:w-28">
                                <label class="block text-xs font-medium text-ink-500">Hitung Fisik</label>
                                <input type="number" min="0" x-model.number="row.actual" :name="`items[${index}][actual_quantity]`"
                                       class="block w-full rounded-xl border-ink-200 bg-white text-sm font-semibold text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                            </div>

                            <div class="w-full space-y-1.5 sm:w-28">
                                <label class="block text-xs font-medium text-ink-500">Selisih</label>
                                <p class="flex h-[2.625rem] items-center justify-center rounded-xl text-sm font-semibold ring-1 ring-inset"
                                   :class="differenceOf(row) > 0 ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
                                        : (differenceOf(row) < 0 ? 'bg-red-50 text-red-700 ring-red-100' : 'bg-ink-50 text-ink-400 ring-ink-100')"
                                   x-text="differenceOf(row) > 0 ? '+' + differenceOf(row) : differenceOf(row)"></p>
                            </div>
                        @endif

                        {{-- Hasil pemeriksaan barang retur --}}
                        @if ($withCondition)
                            <div class="w-full space-y-1.5 sm:w-28">
                                <label class="block text-xs font-medium text-ink-500">Rusak</label>
                                <input type="number" min="0" x-model.number="row.damaged" :name="`items[${index}][damaged_quantity]`"
                                       class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                            </div>

                            <div class="w-full space-y-1.5 sm:w-28">
                                <label class="block text-xs font-medium text-ink-500">Hilang</label>
                                <input type="number" min="0" x-model.number="row.missing" :name="`items[${index}][missing_quantity]`"
                                       class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
                                <p class="text-[11px] text-emerald-600" x-text="`${goodOf(row)} layak jual`"></p>
                            </div>
                        @endif

                        {{-- Catatan --}}
                        <div class="w-full space-y-1.5 sm:w-52">
                            <label class="block text-xs font-medium text-ink-500">Catatan</label>
                            <input type="text" x-model="row.note" :name="`items[${index}][note]`" placeholder="Opsional"
                                   class="block w-full rounded-xl border-ink-200 bg-white text-sm text-ink-950 placeholder:text-ink-300 shadow-soft transition focus:border-ink-950 focus:ring-1 focus:ring-ink-950">
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

                    @if ($withCondition)
                        <template x-if="isOverChecked(row)">
                            <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-red-600">
                                <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                                Rusak + hilang melebihi jumlah pada baris ini.
                            </p>
                        </template>
                    @endif

                    @if ($checkStock)
                        <template x-if="isOverStock(row)">
                            <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-red-600">
                                <x-icon name="warning" class="h-3.5 w-3.5 shrink-0" />
                                <span x-text="`Melebihi stok tersedia (${product(row).stock} ${product(row).unit}).`"></span>
                            </p>
                        </template>
                    @endif
                </div>
            </template>
        </div>

        @if ($checkStock)
            <template x-if="hasStockProblem">
                <div class="flex items-start gap-2.5 border-t border-red-100 bg-red-50 px-5 py-3.5 text-sm text-red-700 sm:px-6">
                    <x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Ada baris yang melebihi stok tersedia. Dokumen tetap bisa disimpan sebagai draft, tetapi tidak dapat diproses.</span>
                </div>
            </template>
        @endif
    @endif
</div>
