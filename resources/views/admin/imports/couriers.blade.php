{{--
    Beban kerja per ekspedisi.

    Status Resi menjawab "resi ini sampai mana". Halaman ini menjawab pertanyaan
    yang ditanyakan tiap sore menjelang kurir datang: masing-masing ekspedisi
    hari ini ada berapa, dan berapa yang belum siap.

    Yang tampil hanya ekspedisi yang benar-benar punya resi pada rentang itu.
    Ekspedisi tanpa resi bukan baris bernilai nol — ia memang tidak ada urusannya
    hari itu.
--}}
@php
    use App\Models\ShipmentOrder;

    $stages = [
        'awaiting' => ['label' => 'Belum QC', 'tone' => 'text-amber-700', 'bg' => 'bg-amber-50', 'stage' => ShipmentOrder::STAGE_AWAITING_QC],
        'checked' => ['label' => 'Siap', 'tone' => 'text-ink-950', 'bg' => 'bg-ink-100', 'stage' => ShipmentOrder::STAGE_CHECKED],
        'shipped' => ['label' => 'Dikirim', 'tone' => 'text-emerald-700', 'bg' => 'bg-emerald-50', 'stage' => ShipmentOrder::STAGE_SHIPPED],
        'cancelled' => ['label' => 'Batal', 'tone' => 'text-red-700', 'bg' => 'bg-red-50', 'stage' => ShipmentOrder::STAGE_CANCELLED],
    ];

    // Tautan ke Status Resi dengan rentang tanggal dan ekspedisi yang sama,
    // supaya angka di sini selalu bisa dibuka menjadi daftar resinya.
    $detail = fn (string $courier, ?string $stage = null) => route('admin.imports.status', array_filter(
        $range->toQuery() + ['courier' => $courier, 'stage' => $stage],
        fn ($value) => filled($value),
    ));
@endphp

<x-app-layout title="Per Ekspedisi">
    <x-ui.page-header title="Beban per Ekspedisi" icon="chart"
                      subtitle="Berapa resi yang harus disiapkan tiap ekspedisi, dan berapa yang belum siap." />

    <x-ui.tabs group="waybill" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Ekspedisi" :value="number_format($totals['couriers'], 0, ',', '.')"
                        icon="chart" accent hint="Yang punya resi pada rentang ini" />
        <x-ui.stat-card label="Total Resi" :value="number_format($totals['orders'], 0, ',', '.')"
                        icon="document" :hint="number_format($totals['units'], 0, ',', '.').' unit dipesan'" />
        <x-ui.stat-card label="Belum QC" :value="number_format($totals['awaiting'], 0, ',', '.')"
                        icon="clock" hint="Belum tuntas discan di packing" />
        <x-ui.stat-card label="Sudah Dikirim" :value="number_format($totals['shipped'], 0, ',', '.')"
                        icon="logout" :hint="number_format($totals['checked'], 0, ',', '.').' siap, menunggu diproses'" />
    </div>

    <form method="GET" action="{{ route('admin.imports.couriers') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama ekspedisi..." class="pl-10" />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal pesanan" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'range', 'from', 'to']))
                    <x-ui.button :href="route('admin.imports.couriers')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($couriers->isEmpty())
            <x-ui.empty-state icon="chart" title="Tidak ada resi pada rentang ini"
                              description="Ekspedisi baru muncul begitu ada resi bertanggal di dalam rentang yang dipilih. Coba lebarkan tanggalnya." />
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Ekspedisi</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Resi</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Unit</th>
                            @foreach ($stages as $meta)
                                <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">
                                    {{ $meta['label'] }}
                                </th>
                            @endforeach
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kesiapan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($couriers as $courier)
                            @php $ready = $courier->total > 0 ? round(($courier->total - $courier->awaiting) / $courier->total * 100) : 100; @endphp
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <a href="{{ $detail($courier->courier) }}"
                                       class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                        {{ $courier->courier }}
                                    </a>
                                </td>

                                <td class="px-4 py-4 text-right text-sm font-semibold tabular-nums text-ink-950">
                                    {{ number_format($courier->total, 0, ',', '.') }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm tabular-nums text-ink-500">
                                    {{ number_format($courier->units, 0, ',', '.') }}
                                </td>

                                @foreach ($stages as $key => $meta)
                                    <td class="px-4 py-4 text-right">
                                        @if ($courier->{$key} > 0)
                                            <a href="{{ $detail($courier->courier, $meta['stage']) }}"
                                               class="inline-flex rounded-lg px-2 py-1 text-sm font-semibold tabular-nums transition hover:opacity-80 {{ $meta['bg'] }} {{ $meta['tone'] }}">
                                                {{ number_format($courier->{$key}, 0, ',', '.') }}
                                            </a>
                                        @else
                                            <span class="text-sm text-ink-300">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-ink-100">
                                            <div class="h-full rounded-full {{ $ready === 100 ? 'bg-emerald-500' : 'bg-ink-950' }}"
                                                 style="width: {{ $ready }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums text-ink-500">{{ $ready }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Di ponsel tiap ekspedisi jadi satu kartu; tabel delapan kolom
                 yang digulir mendatar praktis tidak terbaca. --}}
            <div class="divide-y divide-ink-50 lg:hidden">
                @foreach ($couriers as $courier)
                    @php $ready = $courier->total > 0 ? round(($courier->total - $courier->awaiting) / $courier->total * 100) : 100; @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ $detail($courier->courier) }}" class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $courier->courier }}</p>
                                <p class="mt-0.5 text-[11px] text-ink-400">
                                    {{ number_format($courier->units, 0, ',', '.') }} unit dipesan
                                </p>
                            </a>
                            <div class="shrink-0 text-right">
                                <p class="text-lg font-semibold tabular-nums leading-tight text-ink-950">
                                    {{ number_format($courier->total, 0, ',', '.') }}
                                </p>
                                <p class="text-[10px] uppercase tracking-wider text-ink-400">resi</p>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-4 gap-2 text-center">
                            @foreach ($stages as $key => $meta)
                                <a href="{{ $detail($courier->courier, $meta['stage']) }}"
                                   class="rounded-lg py-2 {{ $courier->{$key} > 0 ? $meta['bg'] : 'bg-ink-50/70' }}">
                                    <p class="text-[10px] uppercase tracking-wider {{ $courier->{$key} > 0 ? $meta['tone'] : 'text-ink-400' }}">
                                        {{ $meta['label'] }}
                                    </p>
                                    <p class="text-sm font-semibold tabular-nums {{ $courier->{$key} > 0 ? $meta['tone'] : 'text-ink-300' }}">
                                        {{ number_format($courier->{$key}, 0, ',', '.') }}
                                    </p>
                                </a>
                            @endforeach
                        </div>

                        <div class="mt-3 flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full {{ $ready === 100 ? 'bg-emerald-500' : 'bg-ink-950' }}"
                                     style="width: {{ $ready }}%"></div>
                            </div>
                            <span class="text-xs tabular-nums text-ink-500">{{ $ready }}% siap</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs leading-relaxed text-ink-400">
        Angkanya bisa ditekan untuk membuka daftar resinya di halaman Status Resi, dengan rentang tanggal dan
        ekspedisi yang sama. <span class="font-medium text-ink-500">Kesiapan</span> adalah bagian resi yang sudah
        lolos QC, sudah dikirim, atau dibatalkan — dengan kata lain yang tidak lagi menunggu pekerjaan packing.
    </p>
</x-app-layout>
