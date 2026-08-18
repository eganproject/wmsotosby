@php
    $isBundle = $product->isBundle();
    $availability = $product->availableStock();
@endphp

<x-app-layout :title="$isBundle ? 'Detail Paket' : 'Kartu Stok'">
    <x-ui.page-header :title="$product->name"
                      :subtitle="$isBundle
                          ? 'Paket bundling — tidak punya stok sendiri, dirakit dari barang di bawah.'
                          : ($product->barcode ? 'Barcode '.$product->barcode : 'Tanpa barcode')"
                      :back="route('admin.products.index')">
        <x-slot name="actions">
            {{-- Paket tidak pernah menghasilkan mutasi, jadi tidak ada yang bisa dibuka di sana. --}}
            @if (! $isBundle)
                @can('movements.view')
                    {{-- Kartu stok ini hanya satu barang; mutasi seluruh gudang ada di halaman sendiri. --}}
                    <x-ui.button :href="route('admin.movements.index', ['product_id' => $product->id])"
                                 variant="secondary" icon="chart">
                        Mutasi Stok
                    </x-ui.button>
                @endcan
            @endif
            @can('products.update')
                <x-ui.button :href="route('admin.products.edit', $product)" icon="pencil">
                    {{ $isBundle ? 'Ubah Paket' : 'Ubah Barang' }}
                </x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Ringkasan --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="overflow-hidden rounded-2xl border border-ink-950 bg-ink-950 p-6 text-white shadow-card">
                <div class="mb-4 flex items-center gap-2">
                    <x-ui.sku :value="$product->sku" variant="outline" />
                    @if ($isBundle)
                        <x-ui.badge variant="outline" icon="sparkles">Paket</x-ui.badge>
                    @endif
                </div>

                <p class="text-xs font-medium uppercase tracking-wider text-white/50">
                    {{ $isBundle ? 'Masih bisa dijanjikan' : 'Stok saat ini' }}
                </p>
                <p class="mt-2 text-5xl font-semibold tracking-tight">
                    {{ number_format($availability, 0, ',', '.') }}
                    <span class="text-base font-normal text-white/50">{{ $product->unit }}</span>
                </p>

                @if (! $isBundle && $product->damaged_stock > 0)
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-red-500/15 px-2.5 py-1 text-xs font-medium text-red-200 ring-1 ring-inset ring-red-400/25">
                        {{ number_format($product->damaged_stock, 0, ',', '.') }} {{ $product->unit }} rusak
                    </p>
                @endif

                <div class="mt-4 flex items-center gap-2">
                    <x-ui.stock-badge :product="$product" />
                    <span class="text-xs text-white/50">
                        {{ $isBundle
                            ? 'dari stok komponen, dikurangi pesanan berjalan'
                            : 'batas minimum '.$product->min_stock.' '.$product->unit }}
                    </span>
                </div>
            </div>

            <x-ui.card :title="$isBundle ? 'Detail Paket' : 'Detail Barang'">
                <dl class="space-y-4 text-sm">
                    @php
                        $rows = $isBundle
                            ? [
                                ['SKU', $product->sku, 'document'],
                                ['Kategori', $product->category ?: '—', 'sliders'],
                                ['Satuan', $product->unit, 'box'],
                                ['Isi', $product->bundleComponents->count().' barang', 'sparkles'],
                            ]
                            : [
                                ['SKU', $product->sku, 'document'],
                                ['Kategori', $product->category ?: '—', 'sliders'],
                                ['Satuan', $product->unit, 'box'],
                                ['Lokasi rak', $product->location ?: '—', 'dashboard'],
                                ['Barcode', $product->barcode ?: '—', 'document'],
                            ];
                    @endphp

                    @foreach ($rows as [$label, $value, $icon])
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

            {{--
                Arah sebaliknya: barang biasa yang menjadi isi paket.

                Perlu terlihat dari sini karena stoknya bukan hanya menanggung
                pesanan atas namanya sendiri — mengeluarkannya lewat dokumen
                lain ikut menurunkan berapa paket yang masih bisa dirakit.
            --}}
            @if (! $isBundle && $product->partOfBundles->isNotEmpty())
                <x-ui.card title="Dipakai di Paket"
                           subtitle="Stok barang ini juga menentukan ketersediaan paket berikut.">
                    <ul class="space-y-3">
                        @foreach ($product->partOfBundles as $usage)
                            <li class="flex items-center justify-between gap-3">
                                <a href="{{ route('admin.products.show', $usage->bundle) }}"
                                   class="min-w-0 flex-1 truncate text-sm font-medium text-ink-950 hover:underline">
                                    {{ $usage->bundle->name }}
                                    <span class="block font-mono text-[11px] text-ink-400">{{ $usage->bundle->sku }}</span>
                                </a>
                                <span class="shrink-0 text-xs text-ink-500">
                                    {{ $usage->quantity }} {{ $product->unit }} / paket
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>

        @if ($isBundle)
            {{-- Isi paket --}}
            <x-ui.card class="lg:col-span-2" title="Isi Paket"
                       subtitle="Yang benar-benar turun dari rak saat paket ini dikirim." padding="p-0">
                @if ($product->bundleComponents->isEmpty())
                    <x-ui.empty-state icon="box" title="Paket belum ada isinya"
                                      description="Selama isinya kosong, paket ini tidak bisa dipesan maupun dikirim.">
                        @can('products.update')
                            <x-slot name="action">
                                <x-ui.button :href="route('admin.products.edit', $product)" icon="plus">Susun Isi Paket</x-ui.button>
                            </x-slot>
                        @endcan
                    </x-ui.empty-state>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-ink-100 text-left">
                            <thead class="bg-ink-50/60">
                                <tr>
                                    <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Per paket</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Stok</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Dijanjikan</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Cukup untuk</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-ink-50">
                                @foreach ($product->bundleComponents as $item)
                                    @php
                                        $reserved = (int) ($committed[$item->component_id] ?? 0);
                                        $sets = $item->availableSets($reserved);
                                    @endphp
                                    <tr class="transition hover:bg-ink-50/50">
                                        <td class="px-6 py-3.5">
                                            <a href="{{ route('admin.products.show', $item->component) }}"
                                               class="text-sm font-medium text-ink-950 hover:underline">
                                                {{ $item->component->name }}
                                            </a>
                                            <span class="mt-0.5 block font-mono text-[11px] text-ink-400">{{ $item->component->sku }}</span>
                                            @unless ($item->component->is_active)
                                                <span class="mt-1 inline-block text-[11px] font-medium text-red-600">
                                                    Nonaktif — paket tidak bisa dirakit selama begini
                                                </span>
                                            @endunless
                                        </td>
                                        <td class="px-6 py-3.5 text-right text-sm font-semibold text-ink-950">
                                            {{ $item->quantity }}
                                            <span class="text-xs font-normal text-ink-400">{{ $item->component->unit }}</span>
                                        </td>
                                        <td class="px-6 py-3.5 text-right text-sm text-ink-600">
                                            {{ number_format($item->component->stock, 0, ',', '.') }}
                                        </td>
                                        <td class="px-6 py-3.5 text-right text-sm {{ $reserved > 0 ? 'text-amber-700' : 'text-ink-300' }}">
                                            {{ $reserved > 0 ? '−'.number_format($reserved, 0, ',', '.') : '—' }}
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            {{-- Yang paling sedikit menyediakan paket adalah yang membatasi. --}}
                                            <span @class([
                                                'inline-flex rounded-lg px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                                                'bg-amber-50 text-amber-700 ring-amber-100' => $sets === $availability,
                                                'bg-ink-50 text-ink-600 ring-ink-100' => $sets !== $availability,
                                            ])>
                                                {{ $sets }} paket
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-start gap-2.5 border-t border-ink-100 bg-ink-50/60 px-6 py-3.5 text-xs text-ink-600">
                        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-ink-300" />
                        <span>
                            Paket tidak punya saldo sendiri dan tidak pernah muncul di kartu stok — yang tercatat bergerak
                            adalah barang di atas. Angka <span class="font-semibold text-ink-950">{{ $availability }}</span>
                            dibatasi oleh baris bertanda kuning; menambah stok barang lain tidak menaikkannya.
                            Kolom <span class="font-medium text-ink-950">Dijanjikan</span> adalah unit yang sudah tercantum
                            di dokumen barang keluar yang belum diproses — barangnya masih di rak, tetapi tidak bisa
                            dijanjikan dua kali.
                        </span>
                    </div>
                @endif
            </x-ui.card>
        @else
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
        @endif
    </div>
</x-app-layout>
