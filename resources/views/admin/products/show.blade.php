<x-app-layout title="Kartu Stok">
    <x-ui.page-header :title="$product->name"
                      :subtitle="$product->barcode ? 'Barcode '.$product->barcode : 'Tanpa barcode'"
                      :back="route('admin.products.index')">
        <x-slot name="actions">
            @can('movements.view')
                {{-- Kartu stok ini hanya satu barang; mutasi seluruh gudang ada di halaman sendiri. --}}
                <x-ui.button :href="route('admin.movements.index', ['product_id' => $product->id])"
                             variant="secondary" icon="chart">
                    Mutasi Stok
                </x-ui.button>
            @endcan
            @can('products.update')
                <x-ui.button :href="route('admin.products.edit', $product)" icon="pencil">Ubah Barang</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Ringkasan --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="overflow-hidden rounded-2xl border border-ink-950 bg-ink-950 p-6 text-white shadow-card">
                <x-ui.sku :value="$product->sku" variant="outline" class="mb-4" />
                <p class="text-xs font-medium uppercase tracking-wider text-white/50">Stok saat ini</p>
                <p class="mt-2 text-5xl font-semibold tracking-tight">
                    {{ number_format($product->stock, 0, ',', '.') }}
                    <span class="text-base font-normal text-white/50">{{ $product->unit }}</span>
                </p>
                @if ($product->damaged_stock > 0)
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-red-500/15 px-2.5 py-1 text-xs font-medium text-red-200 ring-1 ring-inset ring-red-400/25">
                        {{ number_format($product->damaged_stock, 0, ',', '.') }} {{ $product->unit }} rusak
                    </p>
                @endif

                <div class="mt-4 flex items-center gap-2">
                    <x-ui.stock-badge :product="$product" />
                    <span class="text-xs text-white/50">batas minimum {{ $product->min_stock }} {{ $product->unit }}</span>
                </div>
            </div>

            <x-ui.card title="Detail Barang">
                <dl class="space-y-4 text-sm">
                    @foreach ([
                        ['SKU', $product->sku, 'document'],
                        ['Kategori', $product->category ?: '—', 'sliders'],
                        ['Satuan', $product->unit, 'box'],
                        ['Lokasi rak', $product->location ?: '—', 'dashboard'],
                        ['Barcode', $product->barcode ?: '—', 'document'],
                    ] as [$label, $value, $icon])
                        <div class="flex items-center justify-between gap-3">
                            <dt class="flex items-center gap-2 text-ink-500">
                                <x-icon :name="$icon" class="h-4 w-4 text-ink-300" /> {{ $label }}
                            </dt>
                            <dd class="text-right font-medium text-ink-950">{{ $value }}</dd>
                        </div>
                    @endforeach

                    <div class="flex items-center justify-between gap-3 border-t border-ink-100 pt-4">
                        <dt class="text-ink-500">Status</dt>
                        <dd>
                            <x-ui.badge :variant="$product->is_active ? 'success' : 'danger'">
                                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        {{-- Kartu stok --}}
        <x-ui.card class="lg:col-span-2" title="Kartu Stok"
                   subtitle="Setiap pergerakan stok beserta saldo setelahnya." padding="p-0">
            @if ($movements->isEmpty())
                <x-ui.empty-state icon="refresh" title="Belum ada pergerakan"
                                  description="Stok akan tercatat di sini setelah dokumen barang masuk atau keluar diproses." />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 text-left">
                        <thead class="bg-ink-50/60">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Waktu</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Keterangan</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Masuk</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Keluar</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            @foreach ($movements as $movement)
                                <tr class="transition hover:bg-ink-50/50">
                                    <td class="whitespace-nowrap px-6 py-3.5 text-xs text-ink-500">
                                        {{ $movement->created_at->translatedFormat('d M Y') }}
                                        <span class="block text-[11px] text-ink-400">{{ $movement->created_at->format('H:i') }}</span>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <p class="text-sm text-ink-800">{{ $movement->description }}</p>
                                        @if ($movement->user)
                                            <p class="text-[11px] text-ink-400">oleh {{ $movement->user->name }}</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5 text-right text-sm font-medium text-emerald-600">
                                        {{ $movement->isIncoming() ? '+'.$movement->quantity : '—' }}
                                    </td>
                                    <td class="px-6 py-3.5 text-right text-sm font-medium text-red-600">
                                        {{ $movement->isIncoming() ? '—' : '−'.$movement->quantity }}
                                    </td>
                                    <td class="px-6 py-3.5 text-right text-sm font-semibold text-ink-950">
                                        {{ number_format($movement->balance_after, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$movements" />
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
