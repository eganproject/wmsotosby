{{--
    Laporan stok & perputaran barang.

    Mutasi stok menjawab "apa yang terjadi", halaman ini menjawab "bagaimana
    hasilnya": mana yang berputar cepat, mana yang menumpuk tanpa pernah
    keluar, dan mana yang akan habis lebih dulu.

    Semua angka di halaman ini adalah saldo layak jual. Barang rusak punya
    saldonya sendiri dan tidak pernah dijual, jadi tidak ikut dihitung dalam
    perputaran — disebut terpisah supaya tetap terlihat.
--}}
<x-app-layout title="Laporan Stok">
    <x-ui.page-header title="Laporan Stok" icon="trending-up"
                      subtitle="Saldo awal dan akhir, pergerakan, serta kecepatan perputaran tiap barang.">
        <x-slot name="actions">
            @can('reports.export')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.reports.stock.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    {{-- Empat angka yang menentukan tindakan: berapa yang tersimpan, berapa
         yang terjual, seberapa cepat berputar, dan berapa yang diam saja. --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Stok Akhir" :value="number_format($summary['closing'], 0, ',', '.')"
                        icon="box" :hint="number_format($summary['products'], 0, ',', '.').' barang · awal '.number_format($summary['opening'], 0, ',', '.')" />

        <x-ui.stat-card label="Barang Keluar" :value="number_format($summary['outgoing'], 0, ',', '.')"
                        icon="logout"
                        :hint="'Rata-rata '.number_format($summary['per_day'], 1, ',', '.').' unit/hari'" />

        <x-ui.stat-card label="Perputaran" accent icon="trending-up"
                        :value="$summary['turnover'] === null ? '—' : number_format($summary['turnover'], 2, ',', '.').'×'"
                        :hint="$summary['cover'] === null
                            ? 'Belum ada barang keluar pada periode ini'
                            : 'Stok cukup untuk ± '.number_format($summary['cover'], 0, ',', '.').' hari lagi'" />

        <x-ui.stat-card label="Tidak Bergerak" :value="number_format($summary['idle'], 0, ',', '.')"
                        icon="clock"
                        :hint="'Barang bersisa yang tidak keluar sama sekali selama '.$summary['days'].' hari'" />
    </div>

    {{-- Angka pendukung: penting untuk dilihat, tidak cukup penting untuk
         mengambil satu kartu sendiri. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
        <span class="rounded-lg bg-white px-2.5 py-1.5 text-ink-600 ring-1 ring-inset ring-ink-200">
            Periode <span class="font-semibold text-ink-950">{{ $filters->label() }}</span>
            ({{ $summary['days'] }} hari)
        </span>
        <span class="rounded-lg bg-emerald-50 px-2.5 py-1.5 font-medium text-emerald-700">
            Masuk +{{ number_format($summary['incoming'], 0, ',', '.') }}
        </span>
        <span class="rounded-lg bg-amber-50 px-2.5 py-1.5 font-medium text-amber-700">
            {{ number_format($summary['low'], 0, ',', '.') }} barang menipis
        </span>
        @if ($summary['damaged'] > 0)
            <a href="{{ route('admin.disposals.index') }}"
               class="rounded-lg bg-red-50 px-2.5 py-1.5 font-medium text-red-700 hover:bg-red-100">
                {{ number_format($summary['damaged'], 0, ',', '.') }} unit stok rusak
            </a>
        @endif
    </div>

    <form method="GET" action="{{ route('admin.reports.stock') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="$filters->search"
                              placeholder="Cari barang, SKU, atau kategori..." class="pl-10" />
            </div>

            <x-ui.select name="view" class="sm:w-48">
                @foreach (\App\Support\StockReportFilters::VIEWS as $key => $label)
                    <option value="{{ $key }}" @selected($filters->view === $key)>{{ $label }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="sort" class="sm:w-48">
                @foreach (\App\Support\StockReportFilters::SORTS as $key => $label)
                    <option value="{{ $key }}" @selected($filters->sort === $key)>Urut: {{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="category" class="sm:w-48">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected($filters->category === $category)>{{ $category }}</option>
                @endforeach
            </x-ui.select>

            <x-text-input type="date" name="from" :value="$filters->from->format('Y-m-d')" class="sm:w-40" title="Dari tanggal" />
            <x-text-input type="date" name="to" :value="$filters->to->format('Y-m-d')" class="sm:w-40" title="Sampai tanggal" />

            <div class="col-span-2 flex items-center gap-2 sm:ml-auto">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'view', 'sort', 'category', 'from', 'to']))
                    <x-ui.button :href="route('admin.reports.stock')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($rows->isEmpty())
            <x-ui.empty-state icon="trending-up" title="Tidak ada barang pada laporan ini"
                              description="Coba ubah periode atau saringannya. Laporan hanya menghitung dokumen yang sudah disetujui." />
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Awal</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Masuk</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Keluar</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Akhir</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Perputaran</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Perkiraan Habis</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($rows as $row)
                            @php $badge = $row->urgencyBadge(); @endphp
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4 align-top">
                                    <a href="{{ route('admin.products.show', $row->id) }}"
                                       class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                        {{ $row->name }}
                                    </a>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <x-ui.sku :value="$row->sku" />
                                        @if ($row->category)
                                            <span class="text-[11px] text-ink-400">{{ $row->category }}</span>
                                        @endif
                                        @if ($row->damaged > 0)
                                            <span class="rounded-md bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-red-700">
                                                {{ $row->damaged }} rusak
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums text-ink-500">
                                    {{ number_format($row->opening, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums {{ $row->incoming > 0 ? 'font-medium text-emerald-700' : 'text-ink-300' }}">
                                    {{ $row->incoming > 0 ? '+'.number_format($row->incoming, 0, ',', '.') : '—' }}
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums {{ $row->outgoing > 0 ? 'font-medium text-red-700' : 'text-ink-300' }}">
                                    {{ $row->outgoing > 0 ? '−'.number_format($row->outgoing, 0, ',', '.') : '—' }}
                                </td>

                                <td class="px-4 py-4 text-right align-top">
                                    <p class="text-sm font-semibold tabular-nums text-ink-950">
                                        {{ number_format($row->closing, 0, ',', '.') }}
                                    </p>
                                    <p class="text-[11px] text-ink-400">{{ $row->unit }}</p>
                                </td>

                                <td class="px-4 py-4 text-right align-top">
                                    <p class="text-sm font-semibold tabular-nums text-ink-950">{{ $row->turnoverLabel() }}</p>
                                    <p class="text-[11px] text-ink-400">
                                        {{ number_format($row->perDay(), 1, ',', '.') }}/hari
                                    </p>
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    <x-ui.badge :variant="$badge['variant']">{{ $badge['label'] }}</x-ui.badge>
                                    @if ($row->lastOutAt)
                                        <p class="mt-1 text-[11px] text-ink-400">
                                            Terakhir keluar {{ \Illuminate\Support\Carbon::parse($row->lastOutAt)->translatedFormat('d M Y') }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Di ponsel angka disusun berpasangan label–nilai; tabel tujuh
                 kolom yang digulir mendatar praktis tidak terbaca. --}}
            <div class="divide-y divide-ink-50 lg:hidden">
                @foreach ($rows as $row)
                    @php $badge = $row->urgencyBadge(); @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.products.show', $row->id) }}"
                                   class="block truncate text-sm font-semibold text-ink-950">
                                    {{ $row->name }}
                                </a>
                                <p class="mt-0.5 truncate font-mono text-[11px] text-ink-400">
                                    {{ $row->sku }}{{ $row->category ? ' · '.$row->category : '' }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$badge['variant']" class="shrink-0">{{ $badge['label'] }}</x-ui.badge>
                        </div>

                        <div class="mt-3 grid grid-cols-4 gap-2 text-center">
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-ink-400">Awal</p>
                                <p class="text-sm font-semibold tabular-nums text-ink-700">{{ number_format($row->opening, 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg bg-emerald-50 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-emerald-600">Masuk</p>
                                <p class="text-sm font-semibold tabular-nums text-emerald-700">{{ number_format($row->incoming, 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg bg-red-50 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-red-600">Keluar</p>
                                <p class="text-sm font-semibold tabular-nums text-red-700">{{ number_format($row->outgoing, 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg bg-ink-950 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-white/60">Akhir</p>
                                <p class="text-sm font-semibold tabular-nums text-white">{{ number_format($row->closing, 0, ',', '.') }}</p>
                            </div>
                        </div>

                        <p class="mt-2 text-xs text-ink-500">
                            Berputar {{ $row->turnoverLabel() }} ·
                            rata-rata {{ number_format($row->perDay(), 1, ',', '.') }} {{ $row->unit }}/hari
                            @if ($row->damaged > 0)
                                · <span class="font-medium text-red-600">{{ $row->damaged }} rusak</span>
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$rows" />
        @endif
    </div>

    <p class="mt-4 text-xs leading-relaxed text-ink-400">
        Stok berkurang saat dokumen barang keluar disetujui, bukan saat barang selesai discan di stasiun packing.
        Paket yang sudah discan tetapi belum diproses karena itu belum muncul sebagai barang keluar di laporan ini.
    </p>
</x-app-layout>
