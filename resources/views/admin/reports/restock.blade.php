{{--
    Kebutuhan restock.

    Laporan Stok menjawab "bagaimana stok bergerak". Halaman ini menjawab satu
    pertanyaan yang lebih sempit dan lebih mendesak: hari ini perlu memesan apa,
    berapa banyak.

    Tiga hal yang biasanya dilihat terpisah disatukan di sini — saldo di rak,
    unit yang sudah terlanjur dijanjikan ke pembeli, dan laju keluarnya.
--}}
<x-app-layout title="Kebutuhan Restock">
    <x-ui.page-header title="Kebutuhan Restock" icon="login"
                      subtitle="Barang yang perlu dipesan beserta jumlahnya, dihitung dari saldo bebas dan laju keluar.">
        <x-slot name="actions">
            @can('reports.export')
                {{-- data-no-ajax: unduhan berkas tidak boleh lewat navigasi AJAX. --}}
                <x-ui.button :href="route('admin.reports.restock.export', request()->query())"
                             variant="secondary" icon="document" data-no-ajax>
                    Export Excel
                </x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Perlu Dipesan" :value="number_format($summary['needing'], 0, ',', '.')"
                        icon="warning" accent
                        :hint="'dari '.number_format($summary['products'], 0, ',', '.').' barang aktif'" />

        <x-ui.stat-card label="Total Saran Pesan" :value="number_format($summary['units'], 0, ',', '.')"
                        icon="login" hint="Unit, untuk seluruh barang di atas" />

        <x-ui.stat-card label="Rak Kosong" :value="number_format($summary['empty'], 0, ',', '.')"
                        icon="x-circle"
                        :hint="number_format($summary['thin'], 0, ',', '.').' barang di bawah batas menipis'" />

        <x-ui.stat-card label="Terikat Pesanan" :value="number_format($summary['committed'], 0, ',', '.')"
                        icon="clock" hint="Unit sudah dijanjikan, belum dikirim" />
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
        <span class="rounded-lg bg-white px-2.5 py-1.5 text-ink-600 ring-1 ring-inset ring-ink-200">
            Laju keluar diamati <span class="font-semibold text-ink-950">{{ $filters->label() }}</span>
            ({{ $filters->days() }} hari)
        </span>
        <span class="rounded-lg bg-ink-950 px-2.5 py-1.5 font-medium text-white">
            Disiapkan untuk {{ $filters->coverDays }} hari ke depan
        </span>
        <span class="rounded-lg bg-ink-50 px-2.5 py-1.5 text-ink-500 ring-1 ring-inset ring-ink-200">
            Barang nonaktif tidak dihitung
        </span>
    </div>

    <form method="GET" action="{{ route('admin.reports.restock') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="$filters->search"
                              placeholder="Cari barang, SKU, atau kategori..." class="pl-10" />
            </div>

            <x-ui.select name="view" class="sm:w-44">
                @foreach (\App\Support\RestockFilters::VIEWS as $key => $label)
                    <option value="{{ $key }}" @selected($filters->view === $key)>{{ $label }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="sort" class="sm:w-52">
                @foreach (\App\Support\RestockFilters::SORTS as $key => $label)
                    <option value="{{ $key }}" @selected($filters->sort === $key)>Urut: {{ $label }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="category" class="sm:w-44">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected($filters->category === $category)>{{ $category }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-wrap items-end gap-3">
                <x-ui.date-filter label="Periode pengamatan laju keluar" />

                {{--
                    Berapa hari ke depan yang ingin diamankan. Angka inilah yang
                    mengubah laporan dari "apa yang sudah menipis" menjadi "apa
                    yang akan menipis" — barang laris bisa masih di atas batas
                    hari ini dan tetap habis pekan depan.
                --}}
                <label class="flex h-[2.625rem] items-center gap-2 rounded-xl border border-ink-200 bg-white px-3 shadow-soft">
                    <span class="whitespace-nowrap text-xs text-ink-500">Siapkan untuk</span>
                    <input type="number" name="cover" min="1" max="365" inputmode="numeric"
                           value="{{ $filters->coverDays }}"
                           class="w-14 border-0 bg-transparent p-0 text-center text-sm font-semibold text-ink-950 focus:ring-0">
                    <span class="text-xs text-ink-500">hari</span>
                </label>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'view', 'sort', 'category', 'cover', 'from', 'to']))
                    <x-ui.button :href="route('admin.reports.restock')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($rows->isEmpty())
            <x-ui.empty-state icon="check-circle" title="Tidak ada yang perlu dipesan"
                              description="Seluruh barang yang cocok dengan saringan ini masih cukup untuk periode yang disiapkan." />
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Stok</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Terikat</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Tersedia</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Batas</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Laju</th>
                            <th class="px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kondisi</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Saran Pesan</th>
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
                                        @if ($row->location)
                                            <span class="font-mono text-[11px] text-ink-400">{{ $row->location }}</span>
                                        @endif
                                        @if ($row->damaged > 0)
                                            <span class="rounded-md bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-red-700">
                                                {{ $row->damaged }} rusak
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums text-ink-500">
                                    {{ number_format($row->stock, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums {{ $row->committed > 0 ? 'font-medium text-amber-700' : 'text-ink-300' }}">
                                    {{ $row->committed > 0 ? '−'.number_format($row->committed, 0, ',', '.') : '—' }}
                                </td>

                                <td class="px-4 py-4 text-right align-top">
                                    <p class="text-sm font-semibold tabular-nums {{ $row->isOutOfStock() ? 'text-red-700' : 'text-ink-950' }}">
                                        {{ number_format($row->available(), 0, ',', '.') }}
                                    </p>
                                    <p class="text-[11px] text-ink-400">{{ $row->unit }}</p>
                                </td>

                                <td class="px-4 py-4 text-right align-top text-sm tabular-nums text-ink-500">
                                    {{ number_format($row->minStock, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-4 text-right align-top">
                                    <p class="text-sm tabular-nums text-ink-700">{{ number_format($row->perDay(), 1, ',', '.') }}</p>
                                    <p class="text-[11px] text-ink-400">/hari</p>
                                </td>

                                <td class="px-4 py-4 align-top">
                                    <x-ui.badge :variant="$badge['variant']">{{ $badge['label'] }}</x-ui.badge>
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    @if ($row->needsRestock())
                                        <p class="text-base font-semibold tabular-nums text-ink-950">
                                            {{ number_format($row->suggested(), 0, ',', '.') }}
                                            <span class="text-xs font-normal text-ink-400">{{ $row->unit }}</span>
                                        </p>
                                        <p class="text-[11px] text-ink-400">{{ $row->reason() }}</p>
                                    @else
                                        <span class="text-sm text-ink-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Di ponsel angkanya disusun berpasangan; tabel delapan kolom yang
                 digulir mendatar praktis tidak terbaca. --}}
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
                                    {{ $row->sku }}{{ $row->location ? ' · '.$row->location : '' }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$badge['variant']" class="shrink-0">{{ $badge['label'] }}</x-ui.badge>
                        </div>

                        <div class="mt-3 grid grid-cols-4 gap-2 text-center">
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-ink-400">Stok</p>
                                <p class="text-sm font-semibold tabular-nums text-ink-700">{{ number_format($row->stock, 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg bg-amber-50 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-amber-600">Terikat</p>
                                <p class="text-sm font-semibold tabular-nums text-amber-700">{{ number_format($row->committed, 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg py-2 {{ $row->isOutOfStock() ? 'bg-red-50' : 'bg-ink-50/70' }}">
                                <p class="text-[10px] uppercase tracking-wider {{ $row->isOutOfStock() ? 'text-red-600' : 'text-ink-400' }}">Tersedia</p>
                                <p class="text-sm font-semibold tabular-nums {{ $row->isOutOfStock() ? 'text-red-700' : 'text-ink-700' }}">
                                    {{ number_format($row->available(), 0, ',', '.') }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-ink-950 py-2">
                                <p class="text-[10px] uppercase tracking-wider text-white/60">Pesan</p>
                                <p class="text-sm font-semibold tabular-nums text-white">{{ number_format($row->suggested(), 0, ',', '.') }}</p>
                            </div>
                        </div>

                        <p class="mt-2 text-xs text-ink-500">
                            Batas {{ $row->minStock }} · keluar {{ number_format($row->perDay(), 1, ',', '.') }} {{ $row->unit }}/hari
                            @if ($row->needsRestock())
                                · <span class="font-medium text-ink-700">{{ $row->reason() }}</span>
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$rows" />
        @endif
    </div>

    <p class="mt-4 text-xs leading-relaxed text-ink-400">
        <span class="font-medium text-ink-500">Tersedia</span> adalah stok dikurangi unit yang sudah masuk dokumen barang
        keluar tetapi belum diproses — barangnya masih di rak, tetapi sudah dijanjikan ke pembeli.
        <span class="font-medium text-ink-500">Saran pesan</span> mengambil yang terbesar antara mengembalikan saldo ke
        batas menipis dan menutup kebutuhan {{ $filters->coverDays }} hari ke depan pada laju keluar sekarang.
    </p>
</x-app-layout>
