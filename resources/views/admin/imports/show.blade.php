<x-app-layout title="Detail Import">
    <x-ui.page-header :title="$import->filename" :subtitle="'Diimport '.$import->created_at->translatedFormat('d F Y H:i').' oleh '.($import->user?->name ?? 'sistem')"
                      :back="route('admin.imports.batches')">
        <x-slot name="actions">
            @can('imports.create')
                <x-ui.button :href="route('admin.imports.create')" variant="secondary" icon="plus">Import Lagi</x-ui.button>
            @endcan
            @can('imports.delete')
                <x-ui.confirm-delete :action="route('admin.imports.destroy', $import)"
                                     title="Hapus data import ini?"
                                     :description="'Seluruh '.$import->order_count.' resi dari berkas ini akan ikut terhapus.'">
                    <x-slot name="trigger">
                        <x-ui.button type="button" variant="danger-soft" icon="trash">Hapus</x-ui.button>
                    </x-slot>
                </x-ui.confirm-delete>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-ui.stat-card label="Resi" :value="$import->order_count" icon="document" accent />
        <x-ui.stat-card label="Baris Barang" :value="$import->item_count" icon="box" />
        <x-ui.stat-card label="SKU Belum Cocok" :value="$import->unmatched_sku_count" icon="warning" />
        <x-ui.stat-card label="Baris Dibaca" :value="$import->row_count" icon="chart" hint="Termasuk baris tanpa resi" />
    </div>

    @if ($import->detected_columns)
        <div class="mt-5 rounded-2xl border border-ink-100 bg-white p-5 shadow-card">
            <p class="text-xs font-semibold uppercase tracking-wider text-ink-400">Kolom yang terdeteksi</p>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($import->detected_columns as $column)
                    <x-ui.badge variant="outline" icon="check">{{ str($column)->replace('_', ' ')->title() }}</x-ui.badge>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6 overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        <div class="border-b border-ink-100 px-5 py-4 sm:px-6">
            <h2 class="text-sm font-semibold tracking-tight text-ink-950">Resi pada Berkas Ini</h2>
        </div>

        @if ($orders->isEmpty())
            <x-ui.empty-state icon="document" title="Tidak ada resi"
                              description="Berkas ini tidak menghasilkan data resi apa pun." />
        @else
            <div class="divide-y divide-ink-50">
                @foreach ($orders as $order)
                    <div class="p-5 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="break-all font-mono text-sm font-semibold text-ink-950">{{ $order->tracking_number }}</p>
                                    @if ($order->marketplace)
                                        <x-ui.badge variant="dark">{{ $order->marketplace }}</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-ink-500">
                                    {{ $order->order_number ?: 'Tanpa nomor pesanan' }}
                                    @if ($order->buyer_name) &middot; {{ $order->buyer_name }} @endif
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold text-ink-950">{{ $order->totalQuantity() }} unit</p>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($order->items as $item)
                                <span class="inline-flex items-center gap-1.5 rounded-lg border border-ink-100 px-2 py-1">
                                    <x-ui.sku :value="$item->sku" :variant="$item->isMatched() ? 'soft' : 'danger'" :label="false" />
                                    <span class="text-[11px] text-ink-500">{{ $item->quantity }}×</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$orders" />
        @endif
    </div>
</x-app-layout>
