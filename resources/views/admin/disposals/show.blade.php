<x-app-layout title="Detail Penanganan Barang Rusak">
    <x-ui.page-header :title="$disposal->code"
                      :subtitle="$disposal->actionLabel().' · '.$disposal->date->translatedFormat('d F Y')"
                      :back="route('admin.disposals.index')">
        <x-slot name="actions">
            @if ($disposal->isEditable())
                @can('disposals.post')
                    <form method="POST" action="{{ route('admin.disposals.submit', $disposal) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('disposals.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('disposals.approve') ? 'Proses Sekarang' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endcan
                @can('disposals.delete')
                    <x-ui.confirm-delete :action="route('admin.disposals.destroy', $disposal)"
                                         title="Hapus dokumen ini?"
                                         :description="'Draft '.$disposal->code.' akan dihapus permanen.'" />
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $disposal, 'prefix' => 'disposals'])
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Barang yang Ditangani"
                   :subtitle="$disposal->items->count().' baris · total '.number_format($disposal->totalQuantity(), 0, ',', '.').' unit'"
                   padding="p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Sisa Rusak</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Ditangani</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($disposal->items as $item)
                            <tr>
                                <td class="px-6 py-3.5">
                                    <a href="{{ route('admin.products.show', $item->product) }}"
                                       class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                        {{ $item->product->name }}
                                    </a>
                                    <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                </td>
                                <td class="px-6 py-3.5 text-right text-sm tabular-nums text-ink-500">
                                    {{ $item->product->damaged_stock }} {{ $item->product->unit }}
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <span class="text-sm font-semibold tabular-nums text-ink-950">
                                        {{ number_format($item->quantity, 0, ',', '.') }}
                                    </span>
                                    <span class="text-xs text-ink-400">{{ $item->product->unit }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card title="Status Dokumen">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $disposal])

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Diambil dari</dt>
                        <dd class="text-right">
                            <x-ui.badge :variant="$disposal->isFromDamaged() ? 'danger' : 'outline'">
                                {{ $disposal->bucketLabel() }}
                            </x-ui.badge>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Tindakan</dt>
                        <dd class="text-right font-medium text-ink-950">{{ $disposal->actionLabel() }}</dd>
                    </div>
                    @if ($disposal->supplier)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Pemasok tujuan</dt>
                            <dd class="text-right font-medium text-ink-950">{{ $disposal->supplier->name }}</dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $disposal->user?->name ?? '—' }}</dd>
                    </div>
                </dl>

                {{-- Apa yang terjadi pada saldo, dikatakan sebelum diproses.
                     Pemindahan antar saldo mudah disangka pengeluaran, padahal
                     barangnya sama sekali tidak meninggalkan gudang. --}}
                @if (! $disposal->leavesTheWarehouse())
                    <p class="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-[11px] leading-relaxed text-emerald-800 ring-1 ring-inset ring-emerald-200">
                        <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>{{ $disposal->postedSummary() }} Barangnya tetap di gudang.</span>
                    </p>
                @else
                    <p class="flex items-start gap-2 rounded-xl bg-ink-50 px-3 py-2.5 text-[11px] leading-relaxed text-ink-600 ring-1 ring-inset ring-ink-200">
                        <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>{{ $disposal->postedSummary() }} Unitnya keluar dari gudang untuk selamanya.</span>
                    </p>
                @endif

                @if ($disposal->note)
                    <p class="border-t border-ink-100 pt-4 text-xs leading-relaxed text-ink-500">{{ $disposal->note }}</p>
                @endif
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
