{{--
    Status resi: posisi tiap nomor resi di dalam alur gudang.

    Tahapannya dihitung dari data dokumen, bukan diketik manual, jadi tidak
    mungkin ada resi yang tertulis "dikirim" padahal stoknya belum bergerak.
--}}
<x-app-layout title="Status Resi">
    <x-ui.page-header title="Status Resi" icon="search"
                      subtitle="Posisi setiap resi hasil import: belum QC, siap dikirim, sudah dikirim, atau dibatalkan.">
        <x-slot name="actions">
            @can('outbounds.scan')
                <x-ui.button :href="route('admin.outbounds.marketplace')" variant="secondary" icon="search">
                    Stasiun Packing
                </x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="waybill" />

    {{--
        Kartu tahap sekaligus filter: angkanya untuk seluruh data, bukan
        halaman yang sedang tampil.
    --}}
    @php
        $cards = [
            '' => ['label' => 'Semua Resi', 'icon' => 'document', 'hint' => 'Seluruh resi hasil import',
                   'count' => array_sum($counts), 'ring' => 'ring-ink-200', 'text' => 'text-ink-950'],
            \App\Models\ShipmentOrder::STAGE_AWAITING_QC => ['label' => 'Belum QC', 'icon' => 'clock',
                   'hint' => 'Resi atau barangnya belum tuntas discan',
                   'count' => $counts[\App\Models\ShipmentOrder::STAGE_AWAITING_QC], 'ring' => 'ring-amber-200', 'text' => 'text-amber-700'],
            \App\Models\ShipmentOrder::STAGE_CHECKED => ['label' => 'Siap Dikirim', 'icon' => 'check-circle',
                   'hint' => 'QC selesai, menunggu diproses',
                   'count' => $counts[\App\Models\ShipmentOrder::STAGE_CHECKED], 'ring' => 'ring-ink-950', 'text' => 'text-ink-950'],
            \App\Models\ShipmentOrder::STAGE_SHIPPED => ['label' => 'Dikirim', 'icon' => 'logout',
                   'hint' => 'Disetujui, stok sudah berkurang',
                   'count' => $counts[\App\Models\ShipmentOrder::STAGE_SHIPPED], 'ring' => 'ring-emerald-200', 'text' => 'text-emerald-700'],
            \App\Models\ShipmentOrder::STAGE_CANCELLED => ['label' => 'Dibatalkan', 'icon' => 'x-circle',
                   'hint' => 'Batal sebelum berangkat — jangan dikirim',
                   'count' => $counts[\App\Models\ShipmentOrder::STAGE_CANCELLED], 'ring' => 'ring-red-200', 'text' => 'text-red-700'],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
        @foreach ($cards as $key => $card)
            <a href="{{ route('admin.imports.status', array_filter(array_merge(request()->query(), ['stage' => $key, 'page' => null]))) }}"
               @class([
                   'group rounded-2xl border bg-white p-5 shadow-card transition hover:shadow-lift',
                   'border-ink-950 ring-1 ring-inset '.$card['ring'] => $stage === $key,
                   'border-ink-100' => $stage !== $key,
               ])>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wider text-ink-400">{{ $card['label'] }}</p>
                        <p class="mt-1.5 text-2xl font-semibold tracking-tight {{ $card['text'] }}">
                            {{ number_format($card['count'], 0, ',', '.') }}
                        </p>
                    </div>
                    <span @class([
                        'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition',
                        'bg-ink-950 text-white' => $stage === $key,
                        'bg-ink-50 text-ink-400 group-hover:bg-ink-100' => $stage !== $key,
                    ])>
                        <x-icon :name="$card['icon']" class="h-4 w-4" />
                    </span>
                </div>
                <p class="mt-2 text-[11px] leading-relaxed text-ink-400">{{ $card['hint'] }}</p>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.imports.status') }}" data-auto-submit
          class="my-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        {{-- Tahap yang sedang dipilih ikut terbawa saat mencari. --}}
        <input type="hidden" name="stage" value="{{ $stage }}">

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                    <x-icon name="search" class="h-4 w-4" />
                </span>
                <x-text-input type="search" name="search" :value="request('search')"
                              placeholder="Cari nomor resi, pesanan, pembeli, atau SKU..." class="pl-10" />
            </div>

            <x-ui.select name="courier" class="sm:w-48">
                <option value="">Semua ekspedisi</option>
                @foreach ($couriers as $courier)
                    <option value="{{ $courier }}" @selected(request('courier') === $courier)>{{ $courier }}</option>
                @endforeach
            </x-ui.select>
        </div>

        {{-- Rentang tanggal berbaris sendiri: kolomnya membawa pintasan periode
             di bawahnya, dan itu tidak muat disisipkan di antara dropdown. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal unggah berkas" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'courier', 'stage', 'from', 'to']))
                    <x-ui.button :href="route('admin.imports.status')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($orders->isEmpty())
            <x-ui.empty-state icon="search" title="Tidak ada resi pada tahap ini"
                              description="Resi muncul di sini setelah berkas pesanan dari Ginee diimport." />
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Resi</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Pesanan</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Ekspedisi</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Isi</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Tahap</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($orders as $order)
                            @php $orderStage = $order->stage(); @endphp
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4 align-top">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $order->tracking_number }}</p>
                                    @if ($order->marketplace)
                                        <p class="mt-1 text-[11px] text-ink-400">{{ $order->marketplace }}</p>
                                    @endif
                                </td>

                                <td class="px-6 py-4 align-top">
                                    <p class="font-mono text-xs text-ink-700">{{ $order->order_number ?: '—' }}</p>
                                    <p class="truncate text-[11px] text-ink-400">
                                        {{ $order->buyer_name ?: $order->store_name ?: 'Tanpa nama pembeli' }}
                                    </p>
                                </td>

                                <td class="px-6 py-4 align-top">
                                    <p class="text-sm text-ink-800">{{ $order->courier ?: '—' }}</p>
                                    @if ($order->order_date)
                                        <p class="text-[11px] text-ink-400">{{ $order->order_date->translatedFormat('d M Y') }}</p>
                                    @endif
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    <p class="text-sm font-semibold text-ink-950">{{ $order->totalQuantity() }} unit</p>
                                    <p class="text-[11px] text-ink-400">{{ $order->items->count() }} SKU</p>
                                </td>

                                <td class="px-6 py-4 align-top">
                                    <x-ui.badge :variant="match ($orderStage) {
                                        \App\Models\ShipmentOrder::STAGE_SHIPPED => 'success',
                                        \App\Models\ShipmentOrder::STAGE_CANCELLED => 'danger',
                                        default => 'warning',
                                    }">{{ $order->stageLabel() }}</x-ui.badge>
                                    <p class="mt-1 text-[11px] text-ink-500">{{ $order->stageDetail() }}</p>
                                </td>

                                <td class="px-6 py-4 text-right align-top">
                                    <div class="flex flex-col items-end gap-1">
                                        @if ($order->outbound)
                                            <a href="{{ route('admin.outbounds.show', $order->outbound) }}"
                                               class="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-medium text-ink-600 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="eye" class="h-3.5 w-3.5" />
                                                {{ $order->outbound->code }}
                                            </a>
                                        @elseif (! $order->isFullyMatched())
                                            <span class="text-[11px] text-red-600">SKU belum terdaftar</span>
                                        @else
                                            <span class="text-[11px] text-ink-400">Belum ada dokumen</span>
                                        @endif

                                        {{-- Yang sudah berangkat tidak bisa dibatalkan di sini: itu urusan retur. --}}
                                        @if ($orderStage !== \App\Models\ShipmentOrder::STAGE_SHIPPED)
                                            @include('admin.imports.partials.cancel-control')
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($orders as $order)
                    @php $orderStage = $order->stage(); @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm font-semibold text-ink-950">{{ $order->tracking_number }}</p>
                                <p class="truncate text-[11px] text-ink-400">
                                    {{ $order->order_number }} &middot; {{ $order->courier ?: 'Tanpa ekspedisi' }}
                                </p>
                            </div>
                            <x-ui.badge :variant="match ($orderStage) {
                                \App\Models\ShipmentOrder::STAGE_SHIPPED => 'success',
                                \App\Models\ShipmentOrder::STAGE_CHECKED => 'dark',
                                \App\Models\ShipmentOrder::STAGE_CANCELLED => 'danger',
                                default => 'warning',
                            }">{{ $order->stageLabel() }}</x-ui.badge>
                        </div>

                        <p class="mt-2 text-xs text-ink-500">{{ $order->stageDetail() }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="outline">{{ $order->totalQuantity() }} unit</x-ui.badge>
                            <x-ui.badge variant="outline">{{ $order->items->count() }} SKU</x-ui.badge>
                            @if ($order->outbound)
                                <a href="{{ route('admin.outbounds.show', $order->outbound) }}"
                                   class="font-mono text-[11px] font-medium text-ink-600 underline-offset-4 hover:underline">
                                    {{ $order->outbound->code }}
                                </a>
                            @endif
                        </div>

                        @if ($orderStage !== \App\Models\ShipmentOrder::STAGE_SHIPPED)
                            <div class="mt-2">
                                @include('admin.imports.partials.cancel-control')
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$orders" />
        @endif
    </div>
</x-app-layout>
