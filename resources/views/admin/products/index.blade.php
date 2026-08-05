<x-app-layout title="Barang & Stok">
    <x-ui.page-header title="Barang & Stok" icon="box"
                      subtitle="Master data barang sekaligus saldo stoknya. Tambah dan ubah barang dilakukan di sini.">
        <x-slot name="actions">
            @can('products.import')
                <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                    Import Excel
                </x-ui.button>
            @endcan
            @can('products.view')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.products.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
            @can('products.create')
                <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Jenis Barang" :value="$summary['total']" icon="box" accent hint="SKU terdaftar" />
        <x-ui.stat-card label="Total Unit" :value="number_format($summary['units'], 0, ',', '.')" icon="chart" hint="Seluruh stok tersedia" />
        <x-ui.stat-card label="Stok Menipis" :value="$summary['low']" icon="warning" hint="Di bawah batas minimum" />
        <x-ui.stat-card label="Stok Habis" :value="$summary['out']" icon="x-circle" hint="Perlu segera diisi" />
    </div>

    <form method="GET" action="{{ route('admin.products.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama, SKU, atau barcode..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="category" class="sm:w-44">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="stock" class="sm:w-40">
                <option value="">Semua stok</option>
                <option value="safe" @selected(request('stock') === 'safe')>Aman</option>
                <option value="low" @selected(request('stock') === 'low')>Menipis</option>
                <option value="out" @selected(request('stock') === 'out')>Habis</option>
            </x-ui.select>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'category', 'stock', 'status']))
                <x-ui.button :href="route('admin.products.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($products->isEmpty())
            <x-ui.empty-state icon="box" title="Barang tidak ditemukan"
                              description="Tambahkan barang satu per satu, atau import banyak sekaligus dari Excel beserta stoknya.">
                <x-slot name="action">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        @can('products.create')
                            <x-ui.button :href="route('admin.products.create')" icon="plus">Tambah Barang</x-ui.button>
                        @endcan
                        @can('products.import')
                            <x-ui.button :href="route('admin.products.import')" variant="secondary" icon="login">
                                Import dari Excel
                            </x-ui.button>
                        @endcan
                    </div>
                </x-slot>
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kategori</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Lokasi</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Stok</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($products as $product)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <x-ui.sku :value="$product->sku" />
                                        <p class="truncate text-sm font-medium text-ink-950">{{ $product->name }}</p>
                                    </div>
                                    @if ($product->barcode)
                                        <p class="mt-1 font-mono text-[11px] text-ink-400">Barcode {{ $product->barcode }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-ink-600">{{ $product->category ?: '—' }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-ink-500">{{ $product->location ?: '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <span class="text-sm font-semibold text-ink-950">{{ number_format($product->stock, 0, ',', '.') }}</span>
                                    <span class="text-xs text-ink-400">{{ $product->unit }}</span>
                                    <p class="text-[11px] text-ink-400">min. {{ $product->min_stock }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <x-ui.stock-badge :product="$product" />
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.products.show', $product) }}" title="Kartu stok"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="document" class="h-4 w-4" />
                                        </a>
                                        @can('products.update')
                                            <a href="{{ route('admin.products.edit', $product) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('products.delete')
                                            <x-ui.confirm-delete :action="route('admin.products.destroy', $product)"
                                                                 title="Hapus barang ini?"
                                                                 :description="$product->name.' akan dihapus dari master data.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($products as $product)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <x-ui.sku :value="$product->sku" />
                                <p class="mt-1 truncate text-sm font-semibold text-ink-950">{{ $product->name }}</p>
                            </div>
                            <x-ui.stock-badge :product="$product" />
                        </div>

                        <div class="mt-3 flex items-end justify-between gap-3">
                            <div>
                                <p class="text-2xl font-semibold tracking-tight text-ink-950">
                                    {{ number_format($product->stock, 0, ',', '.') }}
                                    <span class="text-xs font-normal text-ink-400">{{ $product->unit }}</span>
                                </p>
                                <p class="text-[11px] text-ink-400">
                                    min. {{ $product->min_stock }} &middot; {{ $product->location ?: 'tanpa lokasi' }}
                                </p>
                            </div>

                            <div class="flex items-center gap-1">
                                <x-ui.button :href="route('admin.products.show', $product)" variant="ghost" size="sm" icon="document">Kartu</x-ui.button>
                                @can('products.update')
                                    <x-ui.button :href="route('admin.products.edit', $product)" variant="secondary" size="sm" icon="pencil">Ubah</x-ui.button>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$products" />
        @endif
    </div>
</x-app-layout>
