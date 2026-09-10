{{--
    Laporan resi per hari.

    Tanggalnya adalah saat resi pertama kali masuk ke sistem melalui import,
    sama dengan tanggal yang dipakai Status Resi dan Per Ekspedisi. Tahap yang
    ditampilkan adalah posisi resi saat laporan dibuka.
--}}
@php
    use App\Models\ShipmentOrder;

    $stages = [
        'awaiting' => ['label' => 'Belum QC', 'stage' => ShipmentOrder::STAGE_AWAITING_QC, 'tone' => 'text-amber-700', 'bg' => 'bg-amber-50'],
        'checked' => ['label' => 'Siap', 'stage' => ShipmentOrder::STAGE_CHECKED, 'tone' => 'text-ink-950', 'bg' => 'bg-ink-100'],
        'shipped' => ['label' => 'Dikirim', 'stage' => ShipmentOrder::STAGE_SHIPPED, 'tone' => 'text-emerald-700', 'bg' => 'bg-emerald-50'],
        'cancelled' => ['label' => 'Batal', 'stage' => ShipmentOrder::STAGE_CANCELLED, 'tone' => 'text-red-700', 'bg' => 'bg-red-50'],
    ];

    $detail = fn (string $date, ?string $stage = null) => route('admin.imports.status', array_filter([
        'from' => $date,
        'to' => $date,
        'search' => $filters->search,
        'courier' => $filters->courier,
        'stage' => $stage,
    ], fn ($value) => filled($value)));

    $resolved = $summary['checked'] + $summary['shipped'] + $summary['cancelled'];
    $readiness = $summary['orders'] > 0 ? round($resolved / $summary['orders'] * 100) : 100;
@endphp

<x-app-layout title="Resi per Hari">
    <x-ui.page-header title="Laporan Resi per Hari" icon="calendar"
                      subtitle="Jumlah resi yang masuk setiap hari beserta posisi prosesnya saat ini.">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.imports.daily.export', request()->query())"
                         variant="secondary" icon="document" data-no-ajax>
                Export Excel
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="waybill" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Hari Aktif" :value="number_format($summary['days'], 0, ',', '.')"
                        icon="calendar" accent :hint="$filters->label()" />
        <x-ui.stat-card label="Total Resi" :value="number_format($summary['orders'], 0, ',', '.')"
                        icon="document" :hint="number_format($summary['units'], 0, ',', '.').' unit dipesan'" />
        <x-ui.stat-card label="Belum QC" :value="number_format($summary['awaiting'], 0, ',', '.')"
                        icon="clock" hint="Masih membutuhkan pekerjaan packing" />
        <x-ui.stat-card label="Kesiapan" :value="$readiness.'%'"
                        icon="check-circle" :hint="number_format($summary['shipped'], 0, ',', '.').' sudah dikirim'" />
    </div>

    <form method="GET" action="{{ route('admin.imports.daily') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="$filters->search"
                              placeholder="Cari nomor resi, pesanan, pembeli, atau SKU..." class="pl-10" />
            </div>

            <x-ui.select name="courier" class="sm:w-52">
                <option value="">Semua ekspedisi</option>
                @foreach ($couriers as $courier)
                    <option value="{{ $courier }}" @selected($filters->courier === $courier)>{{ $courier }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal masuk resi" :range="$filters->range" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'courier', 'range', 'from', 'to']))
                    <x-ui.button :href="route('admin.imports.daily')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($rows->isEmpty())
            <x-ui.empty-state icon="calendar" title="Tidak ada resi pada rentang ini"
                              description="Tanggal akan muncul setelah ada resi yang masuk melalui import pada rentang yang dipilih." />
        @else
            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Tanggal Masuk</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Resi</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Unit</th>
                            <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Ekspedisi</th>
                            @foreach ($stages as $stage)
                                <th class="px-4 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">
                                    {{ $stage['label'] }}
                                </th>
                            @endforeach
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kesiapan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <a href="{{ $detail($row->date) }}"
                                       class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                        {{ \Illuminate\Support\Carbon::parse($row->date)->translatedFormat('d F Y') }}
                                    </a>
                                </td>
                                <td class="px-4 py-4 text-right text-sm font-semibold tabular-nums text-ink-950">
                                    <a href="{{ $detail($row->date) }}" class="hover:underline">{{ number_format($row->total, 0, ',', '.') }}</a>
                                </td>
                                <td class="px-4 py-4 text-right text-sm tabular-nums text-ink-500">{{ number_format($row->units, 0, ',', '.') }}</td>
                                <td class="px-4 py-4 text-right text-sm tabular-nums text-ink-500">{{ number_format($row->couriers, 0, ',', '.') }}</td>

                                @foreach ($stages as $key => $stage)
                                    <td class="px-4 py-4 text-right">
                                        @if ($row->{$key} > 0)
                                            <a href="{{ $detail($row->date, $stage['stage']) }}"
                                               class="inline-flex rounded-lg px-2 py-1 text-sm font-semibold tabular-nums transition hover:opacity-80 {{ $stage['bg'] }} {{ $stage['tone'] }}">
                                                {{ number_format($row->{$key}, 0, ',', '.') }}
                                            </a>
                                        @else
                                            <span class="text-sm text-ink-300">—</span>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-ink-100">
                                            <div class="h-full rounded-full {{ $row->readiness() === 100 ? 'bg-emerald-500' : 'bg-ink-950' }}"
                                                 style="width: {{ $row->readiness() }}%"></div>
                                        </div>
                                        <span class="text-xs tabular-nums text-ink-500">{{ $row->readiness() }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 lg:hidden">
                @foreach ($rows as $row)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <a href="{{ $detail($row->date) }}" class="text-sm font-semibold text-ink-950">
                                    {{ \Illuminate\Support\Carbon::parse($row->date)->translatedFormat('d F Y') }}
                                </a>
                                <p class="mt-0.5 text-[11px] text-ink-400">
                                    {{ $row->units }} unit · {{ $row->couriers }} ekspedisi
                                </p>
                            </div>
                            <x-ui.badge :variant="$row->readiness() === 100 ? 'success' : 'neutral'">
                                {{ $row->readiness() }}% siap
                            </x-ui.badge>
                        </div>

                        <div class="mt-3 grid grid-cols-5 gap-1.5 text-center">
                            <a href="{{ $detail($row->date) }}" class="rounded-lg bg-ink-950 py-2 text-white">
                                <p class="text-[9px] uppercase tracking-wider text-white/60">Resi</p>
                                <p class="text-sm font-semibold tabular-nums">{{ $row->total }}</p>
                            </a>
                            @foreach ($stages as $key => $stage)
                                <a href="{{ $detail($row->date, $stage['stage']) }}" class="rounded-lg py-2 {{ $stage['bg'] }}">
                                    <p class="text-[9px] uppercase tracking-wider {{ $stage['tone'] }}">{{ $stage['label'] }}</p>
                                    <p class="text-sm font-semibold tabular-nums {{ $stage['tone'] }}">{{ $row->{$key} }}</p>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs leading-relaxed text-ink-400">
        Tanggal mengikuti saat resi pertama kali masuk melalui import, bukan tanggal pesanan marketplace.
        Status adalah posisi resi saat laporan dibuka. Klik tanggal atau angka tahap untuk membuka daftar resinya.
        Laporan ini hanya membaca data yang sudah ada dan tidak mengubah status maupun dokumen gudang.
    </p>
</x-app-layout>
