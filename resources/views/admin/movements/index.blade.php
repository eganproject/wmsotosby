{{--
    Mutasi stok: seluruh pergerakan barang dari semua dokumen.

    Setiap baris menunjukkan berapa yang bergerak sekaligus saldo sesudahnya,
    jadi angka stok hari ini selalu bisa ditelusuri ke dokumen asalnya.
--}}
<x-app-layout title="Mutasi Stok">
    <x-ui.page-header title="Mutasi Stok" icon="chart"
                      subtitle="Riwayat keluar-masuk barang dari seluruh dokumen, lengkap dengan saldo setiap saat.">
        <x-slot name="actions">
            @can('movements.export')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.movements.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Barang Masuk" :value="'+'.number_format($summary['incoming'], 0, ',', '.')"
                        icon="login" hint="Unit bertambah pada periode ini" />
        <x-ui.stat-card label="Barang Keluar" :value="'-'.number_format($summary['outgoing'], 0, ',', '.')"
                        icon="logout" hint="Unit berkurang pada periode ini" />
        <x-ui.stat-card label="Selisih Bersih"
                        :value="($summary['net'] >= 0 ? '+' : '-').number_format(abs($summary['net']), 0, ',', '.')"
                        icon="chart" accent hint="Masuk dikurangi keluar" />
        <x-ui.stat-card label="Jumlah Mutasi" :value="number_format($summary['entries'], 0, ',', '.')"
                        icon="document" :hint="$summary['products'].' barang terlibat'" />
    </div>

    <form method="GET" action="{{ route('admin.movements.index') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari barang, SKU, atau keterangan..." class="pl-10" />
            </div>

            <x-ui.select name="product_id" class="sm:w-56">
                <option value="">Semua barang</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected((int) request('product_id') === $product->id)>
                        {{ $product->sku }} — {{ $product->name }}
                    </option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="bucket" class="sm:w-40">
                <option value="">Semua saldo</option>
                <option value="good" @selected(request('bucket') === 'good')>Layak jual</option>
                <option value="damaged" @selected(request('bucket') === 'damaged')>Barang rusak</option>
            </x-ui.select>

            <x-ui.select name="type" class="sm:w-36">
                <option value="">Masuk & keluar</option>
                <option value="in" @selected(request('type') === 'in')>Masuk saja</option>
                <option value="out" @selected(request('type') === 'out')>Keluar saja</option>
            </x-ui.select>

            <x-ui.select name="source" class="sm:w-48">
                <option value="">Semua sumber</option>
                @foreach ($sources as $key => $source)
                    <option value="{{ $key }}" @selected(request('source') === $key)>{{ $source['label'] }}</option>
                @endforeach
            </x-ui.select>

            <x-text-input type="date" name="from" :value="request('from')" class="sm:w-40" title="Dari tanggal" />
            <x-text-input type="date" name="to" :value="request('to')" class="sm:w-40" title="Sampai tanggal" />

            <div class="col-span-2 flex items-center gap-2 sm:ml-auto">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'product_id', 'type', 'bucket', 'source', 'from', 'to']))
                    <x-ui.button :href="route('admin.movements.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($movements->isEmpty())
            <x-ui.empty-state icon="chart" title="Belum ada mutasi stok"
                              description="Setiap barang masuk, barang keluar, retur, dan penyesuaian yang disetujui akan tercatat di sini." />
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Waktu</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Sumber</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Mutasi</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Saldo</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($movements as $movement)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4 align-top">
                                    <p class="text-sm text-ink-800">{{ $movement->created_at->translatedFormat('d M Y') }}</p>
                                    <p class="font-mono text-[11px] text-ink-400">{{ $movement->created_at->format('H:i') }}</p>
                                </td>

                                <td class="px-6 py-4 align-top">
                                    @if ($movement->product)
                                        <a href="{{ route('admin.products.show', $movement->product) }}"
                                           class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $movement->product->name }}
                                        </a>
                                        <div class="mt-1"><x-ui.sku :value="$movement->product->sku" /></div>
                                    @else
                                        <span class="text-sm text-ink-400">Barang sudah dihapus</span>
                                    @endif
                                </td>

                                <td class="px-6 py-4 align-top">
                                    @if ($url = $movement->sourceUrl())
                                        <a href="{{ $url }}" class="inline-flex">
                                            <x-ui.badge variant="outline">{{ $movement->sourceLabel() }}</x-ui.badge>
                                        </a>
                                    @else
                                        <x-ui.badge variant="outline">{{ $movement->sourceLabel() }}</x-ui.badge>
                                    @endif
                                    @if ($movement->isDamaged())
                                        <span class="ml-1 rounded-md bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-red-700">rusak</span>
                                    @endif
                                    <p class="mt-1 max-w-xs truncate text-xs text-ink-400" title="{{ $movement->description }}">
                                        {{ $movement->description ?: '—' }}
                                    </p>
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-sm font-semibold tabular-nums',
                                        'bg-emerald-50 text-emerald-700' => $movement->isIncoming(),
                                        'bg-red-50 text-red-700' => ! $movement->isIncoming(),
                                    ])>
                                        <x-icon :name="$movement->isIncoming() ? 'login' : 'logout'" class="h-3.5 w-3.5" />
                                        {{ $movement->isIncoming() ? '+' : '−' }}{{ number_format($movement->quantity, 0, ',', '.') }}
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    <p class="text-sm font-semibold tabular-nums text-ink-950">
                                        {{ number_format($movement->balance_after, 0, ',', '.') }}
                                    </p>
                                    <p class="text-[11px] text-ink-400">{{ $movement->product?->unit }}</p>
                                </td>

                                <td class="px-6 py-4 align-top text-sm text-ink-500">
                                    {{ $movement->user?->name ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($movements as $movement)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-950">
                                    {{ $movement->product?->name ?? 'Barang sudah dihapus' }}
                                </p>
                                <p class="mt-0.5 truncate font-mono text-[11px] text-ink-400">
                                    {{ $movement->product?->sku }} &middot;
                                    {{ $movement->created_at->translatedFormat('d M Y H:i') }}
                                </p>
                            </div>

                            <span @class([
                                'shrink-0 rounded-lg px-2.5 py-1 text-sm font-semibold tabular-nums',
                                'bg-emerald-50 text-emerald-700' => $movement->isIncoming(),
                                'bg-red-50 text-red-700' => ! $movement->isIncoming(),
                            ])>
                                {{ $movement->isIncoming() ? '+' : '−' }}{{ number_format($movement->quantity, 0, ',', '.') }}
                            </span>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="outline">{{ $movement->sourceLabel() }}</x-ui.badge>
                            <x-ui.badge variant="neutral">saldo {{ number_format($movement->balance_after, 0, ',', '.') }}</x-ui.badge>
                        </div>

                        @if ($movement->description)
                            <p class="mt-2 text-xs text-ink-500">{{ $movement->description }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$movements" />
        @endif
    </div>
</x-app-layout>
