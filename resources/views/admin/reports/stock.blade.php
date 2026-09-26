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
         yang terjual, seberapa cepat berputar, dan berapa yang hampir habis. --}}
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Stok Akhir" :value="number_format($summary['closing'], 0, ',', '.')"
                        icon="box" :hint="number_format($summary['products'], 0, ',', '.').' barang · awal '.number_format($summary['opening'], 0, ',', '.')" />

        <x-ui.stat-card label="Qty Keluar" :value="number_format($summary['outgoing'], 0, ',', '.')"
                        icon="logout"
                        :hint="'Operasional · rata-rata '.number_format($summary['per_day'], 1, ',', '.').' unit/hari'" />

        <x-ui.stat-card label="Perputaran" accent icon="trending-up"
                        :value="$summary['turnover'] === null ? '—' : number_format($summary['turnover'], 2, ',', '.').'×'"
                        :hint="$summary['cover'] === null
                            ? 'Belum ada barang keluar pada periode ini'
                            : 'Stok cukup untuk ± '.number_format($summary['cover'], 0, ',', '.').' hari lagi'" />

        <x-ui.stat-card label="Stok Menipis" :value="number_format($summary['low'], 0, ',', '.')"
                        icon="warning"
                        hint="Barang dengan saldo di bawah atau sama dengan batas minimum" />
    </div>

    {{-- Rekap klasifikasi. Tiap kartu sekaligus tombol saring: klik sekali
         untuk melihat daftarnya, klik lagi untuk kembali ke semua barang. --}}
    @php
        $classCards = [
            \App\Support\StockVelocity::FAST => [
                'view' => 'fast', 'band' => 'Kontribusi kumulatif 0–70%',
                'bar' => 'bg-emerald-500', 'tint' => 'bg-emerald-50 text-emerald-700', 'ring' => 'ring-emerald-500',
            ],
            \App\Support\StockVelocity::MEDIUM => [
                'view' => 'medium', 'band' => 'Kontribusi kumulatif 70–90%',
                'bar' => 'bg-amber-500', 'tint' => 'bg-amber-50 text-amber-700', 'ring' => 'ring-amber-500',
            ],
            \App\Support\StockVelocity::SLOW => [
                'view' => 'slow', 'band' => 'Kontribusi kumulatif > 90%',
                'bar' => 'bg-ink-400', 'tint' => 'bg-ink-100 text-ink-700', 'ring' => 'ring-ink-400',
            ],
            \App\Support\StockVelocity::NON_MOVING => [
                'view' => 'mati', 'band' => 'Tanpa barang keluar operasional',
                'bar' => 'bg-ink-200', 'tint' => 'bg-ink-50 text-ink-500', 'ring' => 'ring-ink-300',
            ],
        ];
    @endphp
    <div class="mt-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ($classCards as $class => $card)
            @php
                $data = $classes[$class];
                $active = $filters->view === $card['view'];
                $href = route('admin.reports.stock', array_merge(
                    request()->except(['view', 'page']),
                    $active ? [] : ['view' => $card['view']],
                ));
            @endphp
            <a href="{{ $href }}"
               class="group relative overflow-hidden rounded-2xl border bg-white p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-lift {{ $active ? 'border-transparent ring-2 '.$card['ring'] : 'border-ink-100' }}">
                <span class="absolute inset-x-0 top-0 h-1 {{ $card['bar'] }}"></span>

                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wider text-ink-500">
                        {{ \App\Support\StockVelocity::classes()[$class] }}
                    </p>
                    @if ($class !== \App\Support\StockVelocity::NON_MOVING)
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums {{ $card['tint'] }}">
                            {{ number_format($data['share'], 1, ',', '.') }}%
                        </span>
                    @endif
                </div>

                <p class="mt-2 text-3xl font-semibold tracking-tight text-ink-950 tabular-nums">
                    {{ number_format($data['products'], 0, ',', '.') }}
                    <span class="text-sm font-medium text-ink-400">SKU</span>
                </p>

                <p class="mt-1 text-xs text-ink-500">
                    @if ($class === \App\Support\StockVelocity::NON_MOVING)
                        Tidak keluar selama {{ $summary['days'] }} hari
                    @else
                        {{ number_format($data['outgoing'], 0, ',', '.') }} unit keluar
                    @endif
                </p>
                <p class="mt-0.5 text-[11px] text-ink-400">{{ $card['band'] }}</p>
            </a>
        @endforeach
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
                <table class="min-w-full text-left">
                    <thead class="border-b border-ink-100 bg-ink-50/70">
                        <tr class="text-[11px] font-semibold uppercase tracking-wider text-ink-500">
                            <th class="w-12 py-3.5 pl-6 pr-2 text-center">No</th>
                            <th class="px-4 py-3.5">SKU / Item</th>
                            <th class="px-4 py-3.5">Klasifikasi</th>
                            <th class="px-4 py-3.5 text-right">Qty Keluar</th>
                            <th class="px-4 py-3.5 text-right">Rata-rata/Hari</th>
                            <th class="px-4 py-3.5 text-right">Kontribusi</th>
                            <th class="px-4 py-3.5 text-right" title="Jumlah dokumen barang keluar selama periode">Frequency</th>
                            <th class="px-4 py-3.5 text-right" title="Stok akhir dibagi rata-rata keluar per hari">Days Cover</th>
                            <th class="py-3.5 pl-4 pr-6 text-right">Terakhir Keluar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($rows as $row)
                            @php
                                $badge = $row->urgencyBadge();
                                $movementBadge = $row->movementBadge();
                            @endphp
                            <tr class="transition odd:bg-white even:bg-ink-50/30 hover:bg-ink-50/80">
                                <td class="py-3.5 pl-6 pr-2 text-center align-middle text-xs tabular-nums text-ink-400">
                                    {{ $rows->firstItem() + $loop->index }}
                                </td>

                                <td class="max-w-xs px-4 py-3.5 align-middle">
                                    <x-ui.sku :value="$row->sku" :label="false" />
                                    <a href="{{ route('admin.products.show', $row->id) }}"
                                       class="mt-1 block truncate text-sm font-medium text-ink-950 underline-offset-4 hover:underline"
                                       title="{{ $row->name }}">
                                        {{ $row->name }}
                                    </a>
                                    @if ($row->category || $row->damaged > 0)
                                        <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-ink-400">
                                            @if ($row->category)
                                                <span>{{ $row->category }}</span>
                                            @endif
                                            @if ($row->damaged > 0)
                                                <span class="font-semibold text-red-600">· {{ $row->damaged }} rusak</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 align-middle">
                                    <x-ui.badge :variant="$movementBadge['variant']">{{ $movementBadge['label'] }}</x-ui.badge>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 text-right align-middle">
                                    <span class="text-sm tabular-nums {{ $row->sold > 0 ? 'font-semibold text-ink-950' : 'text-ink-300' }}">
                                        {{ $row->sold > 0 ? number_format($row->sold, 0, ',', '.') : '—' }}
                                    </span>
                                    @if ($row->sold > 0)
                                        <span class="text-[11px] text-ink-400">{{ $row->unit }}</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 text-right align-middle text-sm tabular-nums {{ $row->sold > 0 ? 'text-ink-700' : 'text-ink-300' }}">
                                    {{ $row->sold > 0 ? number_format($row->perDay(), 2, ',', '.') : '—' }}
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 text-right align-middle">
                                    <p class="text-sm tabular-nums {{ $row->sold > 0 ? 'font-medium text-ink-950' : 'text-ink-300' }}">
                                        {{ $row->shareLabel() }}
                                    </p>
                                    @if ($row->sold > 0)
                                        <div class="ml-auto mt-1.5 h-1 w-20 overflow-hidden rounded-full bg-ink-100">
                                            <div class="h-full rounded-full bg-ink-950" style="width: {{ min(100, max(2, $row->share())) }}%"></div>
                                        </div>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 text-right align-middle">
                                    <span class="text-sm tabular-nums {{ $row->frequency > 0 ? 'text-ink-700' : 'text-ink-300' }}">
                                        {{ $row->frequency > 0 ? number_format($row->frequency, 0, ',', '.').'×' : '—' }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-4 py-3.5 text-right align-middle">
                                    <x-ui.badge :variant="$badge['variant']">{{ $badge['label'] }}</x-ui.badge>
                                    <p class="mt-1 text-[11px] tabular-nums text-ink-400">
                                        Stok {{ number_format($row->closing, 0, ',', '.') }} {{ $row->unit }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap py-3.5 pl-4 pr-6 text-right align-middle">
                                    @if ($row->lastOutAt)
                                        @php $lastOut = \Illuminate\Support\Carbon::parse($row->lastOutAt); @endphp
                                        <p class="text-sm tabular-nums text-ink-700">{{ $lastOut->translatedFormat('d M Y') }}</p>
                                        <p class="text-[11px] text-ink-400">{{ $lastOut->diffForHumans() }}</p>
                                    @else
                                        <span class="text-sm text-ink-300">Belum pernah</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Di ponsel angka disusun berpasangan label–nilai; tabel sembilan
                 kolom yang digulir mendatar praktis tidak terbaca. --}}
            <div class="divide-y divide-ink-100 lg:hidden">
                @foreach ($rows as $row)
                    @php
                        $badge = $row->urgencyBadge();
                        $movementBadge = $row->movementBadge();
                    @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 gap-3">
                                <span class="mt-0.5 w-6 shrink-0 text-right text-xs tabular-nums text-ink-400">
                                    {{ $rows->firstItem() + $loop->index }}
                                </span>
                                <div class="min-w-0">
                                    <x-ui.sku :value="$row->sku" :label="false" />
                                    <a href="{{ route('admin.products.show', $row->id) }}"
                                       class="mt-1 block truncate text-sm font-semibold text-ink-950">
                                        {{ $row->name }}
                                    </a>
                                </div>
                            </div>
                            <x-ui.badge :variant="$movementBadge['variant']" class="shrink-0">{{ $movementBadge['label'] }}</x-ui.badge>
                        </div>

                        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Qty Keluar</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ number_format($row->sold, 0, ',', '.') }}</dd>
                            </div>
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Rata²/Hari</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ number_format($row->perDay(), 2, ',', '.') }}</dd>
                            </div>
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Kontribusi</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ $row->shareLabel() }}</dd>
                            </div>
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Frequency</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ number_format($row->frequency, 0, ',', '.') }}×</dd>
                            </div>
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Days Cover</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ $row->coverLabel() }}</dd>
                            </div>
                            <div class="rounded-lg bg-ink-50/70 py-2">
                                <dt class="text-[10px] uppercase tracking-wider text-ink-400">Stok</dt>
                                <dd class="text-sm font-semibold tabular-nums text-ink-950">{{ number_format($row->closing, 0, ',', '.') }}</dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-xs text-ink-500">
                            Terakhir keluar
                            {{ $row->lastOutAt ? \Illuminate\Support\Carbon::parse($row->lastOutAt)->translatedFormat('d M Y') : 'belum pernah' }}
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
        Qty keluar, frequency, dan klasifikasi hanya menghitung barang keluar operasional (dokumen barang keluar);
        penyesuaian dan selisih opname tidak dihitung sebagai penjualan.
        Klasifikasi: {{ \App\Support\StockContribution::ruleLabel() }}.
        Kontribusi dihitung terhadap seluruh barang pada periode ini, tidak terpengaruh pencarian atau kategori.
        Days cover adalah stok akhir dibagi rata-rata keluar per hari.
    </p>
</x-app-layout>
