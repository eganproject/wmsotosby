<x-app-layout title="Import Resi">
    <x-ui.page-header title="Import Resi" icon="document"
                      subtitle="Data resi hasil eksport Ginee yang dipakai saat scan barang keluar dan retur.">
        <x-slot name="actions">
            @can('imports.view')
                <x-ui.button :href="route('admin.imports.batches')" variant="secondary" icon="clock">Riwayat Import</x-ui.button>
            @endcan
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" icon="plus">Import Excel</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="waybill" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Resi Terdata" :value="number_format($summary['orders'], 0, ',', '.')" icon="document" accent
                        hint="Siap dipakai saat scan" />
        <x-ui.stat-card label="Baris Barang" :value="number_format($summary['items'], 0, ',', '.')" icon="box"
                        hint="Total baris SKU" />
        <x-ui.stat-card label="SKU Belum Cocok" :value="$summary['unmatched']" icon="warning"
                        hint="Belum ada di master barang" />
        <x-ui.stat-card label="Berkas Import" :value="$summary['batches']" icon="clock" hint="Riwayat unggahan" />
    </div>

    @if ($summary['unmatched'] > 0)
        <div class="mt-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <x-icon name="warning" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900">{{ $summary['unmatched'] }} baris memiliki SKU yang belum terdaftar</p>
                <p class="mt-0.5 text-xs text-amber-800">
                    Pesanan dengan SKU tak dikenal tidak bisa menarik barang otomatis saat scan. Daftarkan SKU-nya di menu Barang &amp; Stok.
                </p>
            </div>
            <x-ui.button :href="route('admin.imports.index', ['match' => 'unmatched'])" variant="secondary" size="sm">
                Lihat
            </x-ui.button>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.imports.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor resi, pesanan, SKU, atau pembeli..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="marketplace" class="sm:w-40">
                <option value="">Semua channel</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="courier" class="sm:w-44">
                <option value="">Semua ekspedisi</option>
                @foreach ($couriers as $courier)
                    <option value="{{ $courier }}" @selected(request('courier') === $courier)>{{ $courier }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="match" class="sm:w-44">
                <option value="">Semua SKU</option>
                <option value="matched" @selected(request('match') === 'matched')>SKU cocok semua</option>
                <option value="unmatched" @selected(request('match') === 'unmatched')>Ada SKU belum cocok</option>
            </x-ui.select>
        </div>

        {{-- Rentang tanggal berbaris sendiri: kolomnya membawa pintasan periode
             di bawahnya, dan itu tidak muat disisipkan di antara dropdown. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal unggah berkas" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'marketplace', 'match', 'courier', 'from', 'to']))
                    <x-ui.button :href="route('admin.imports.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($orders->isEmpty())
            <x-ui.empty-state icon="document" title="Belum ada data resi"
                              description="Import berkas eksport pesanan dari Ginee agar resi bisa dipakai saat scan.">
                @can('imports.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.imports.create')" icon="plus">Import Excel</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-ink-50">
                @foreach ($orders as $order)
                    <div class="p-5 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="break-all font-mono text-sm font-semibold text-ink-950">{{ $order->tracking_number }}</p>
                                    @if ($order->marketplace)
                                        <x-ui.badge variant="dark" icon="sparkles">{{ $order->marketplace }}</x-ui.badge>
                                    @endif
                                    @if ($order->isFullyMatched())
                                        <x-ui.badge variant="success" icon="check-circle">SKU cocok</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning" icon="warning">
                                            {{ $order->unmatchedItems()->count() }} SKU belum cocok
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-ink-500">
                                    {{ $order->order_number ?: 'Tanpa nomor pesanan' }}
                                    @if ($order->buyer_name) &middot; {{ $order->buyer_name }} @endif
                                    @if ($order->store_name) &middot; {{ $order->store_name }} @endif
                                    @if ($order->order_date) &middot; {{ $order->order_date->translatedFormat('d M Y H:i') }} @endif
                                </p>

                                @if ($order->courier || $order->shipping_method)
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        @if ($order->courier)
                                            <x-ui.badge variant="outline" icon="logout">{{ $order->courier }}</x-ui.badge>
                                        @endif
                                        @if ($order->shipping_method)
                                            <x-ui.badge variant="outline">{{ $order->shipping_method }}</x-ui.badge>
                                        @endif
                                    </div>
                                @endif

                                @if ($order->buyer_note)
                                    <p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-200">
                                        <span class="font-semibold">Catatan pembeli:</span> {{ $order->buyer_note }}
                                    </p>
                                @endif
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold text-ink-950">{{ $order->totalQuantity() }} unit</p>
                                <p class="text-[11px] text-ink-400">{{ $order->items->count() }} baris SKU</p>
                            </div>
                        </div>

                        {{-- Isi pesanan: SKU jadi identitas utama --}}
                        <div class="mt-3 space-y-1.5">
                            @foreach ($order->items as $item)
                                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-ink-100 px-3 py-2">
                                    <x-ui.sku :value="$item->sku" :variant="$item->isMatched() ? 'soft' : 'danger'" />
                                    <span class="min-w-0 flex-1 truncate text-xs text-ink-700">
                                        {{ $item->product?->name ?? $item->product_name ?? 'Nama produk tidak tersedia' }}
                                    </span>
                                    @unless ($item->isMatched())
                                        <span class="text-[11px] font-medium text-red-600">belum ada di master barang</span>
                                    @endunless
                                    <span class="shrink-0 text-xs font-semibold text-ink-950">{{ $item->quantity }}×</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$orders" />
        @endif
    </div>
</x-app-layout>
