<x-app-layout title="Detail Penerimaan Retur">
    <x-ui.page-header :title="$return->code"
                      :subtitle="$return->date->translatedFormat('d F Y').' · '.$return->sender"
                      :back="route('admin.returns.index')">
        <x-slot name="actions">
            @if ($return->isEditable())
                @if ($return->requiresResiScan() && ! $return->isResiVerified())
                    @can('returns.scan')
                        <x-ui.button :href="route('admin.returns.scan', $return)" icon="search">Scan Resi Retur</x-ui.button>
                    @endcan
                @elseif (auth()->user()->can('returns.post'))
                    <form method="POST" action="{{ route('admin.returns.submit', $return) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('returns.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('returns.approve') ? 'Setujui & Terima' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endif

                @can('returns.update')
                    <x-ui.button :href="route('admin.returns.edit', $return)" variant="secondary" icon="pencil">Ubah</x-ui.button>
                @endcan
                @can('returns.delete')
                    <x-ui.confirm-delete :action="route('admin.returns.destroy', $return)"
                                         title="Hapus dokumen retur ini?"
                                         :description="'Draft '.$return->code.' akan dihapus permanen.'" />
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $return, 'prefix' => 'returns'])
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Verifikasi resi --}}
            @if ($return->requiresResiScan())
                <div class="overflow-hidden rounded-2xl border {{ $return->isResiVerified() ? 'border-emerald-200 bg-emerald-50' : 'border-ink-100 bg-white' }} p-5 shadow-card sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $return->isResiVerified() ? 'bg-emerald-600 text-white' : 'bg-ink-950 text-white' }}">
                                <x-icon :name="$return->isResiVerified() ? 'check-circle' : 'key'" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold {{ $return->isResiVerified() ? 'text-emerald-800' : 'text-ink-950' }}">
                                    {{ $return->isResiVerified() ? 'Resi retur terverifikasi' : 'Resi retur belum discan' }}
                                </p>
                                <p class="mt-0.5 break-all text-xs {{ $return->isResiVerified() ? 'text-emerald-700/80' : 'text-ink-500' }}">
                                    Resi <span class="font-mono">{{ $return->tracking_number }}</span>
                                    @if ($return->isResiVerified())
                                        &middot; {{ $return->resi_verified_at->translatedFormat('d M Y H:i') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($return->isEditable())
                            @can('returns.scan')
                                <x-ui.button :href="route('admin.returns.scan', $return)" variant="secondary" size="sm" icon="search">
                                    Buka Panel Scan
                                </x-ui.button>
                            @endcan
                        @endif
                    </div>
                </div>
            @endif

            {{-- Baris barang --}}
            <x-ui.card title="Barang Retur"
                       :subtitle="$return->items->count().' baris · '.$return->goodQuantity().' layak jual · '.$return->damagedQuantity().' rusak · '.$return->missingQuantity().' hilang'"
                       padding="p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 text-left">
                        <thead class="bg-ink-50/60">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kondisi</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            @foreach ($return->items as $item)
                                <tr>
                                    <td class="px-6 py-3.5">
                                        <a href="{{ route('admin.products.show', $item->product) }}"
                                           class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $item->product->name }}
                                        </a>
                                        <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                        @if ($item->note)
                                            <p class="mt-0.5 text-[11px] text-ink-500">{{ $item->note }}</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <div class="flex flex-wrap gap-1.5">
                                            @if ($item->good_quantity > 0)
                                                <x-ui.badge variant="success" icon="check-circle">{{ $item->good_quantity }} layak jual</x-ui.badge>
                                            @endif
                                            @if ($item->damaged_quantity > 0)
                                                <x-ui.badge variant="danger" icon="warning">{{ $item->damaged_quantity }} rusak</x-ui.badge>
                                            @endif
                                            @if ($item->hasMissing())
                                                <x-ui.badge variant="warning" icon="x-circle">{{ $item->missingQuantity() }} hilang</x-ui.badge>
                                            @endif
                                            @unless ($item->isChecked())
                                                <x-ui.badge variant="neutral">Belum diperiksa</x-ui.badge>
                                            @endunless
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5 text-right">
                                        <span class="text-sm font-semibold text-ink-950">{{ number_format($item->quantity, 0, ',', '.') }}</span>
                                        <span class="text-xs text-ink-400">{{ $item->product->unit }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($return->damagedQuantity() > 0 || $return->hasMissing())
                    <div class="flex items-start gap-2.5 border-t border-amber-100 bg-amber-50 px-5 py-3.5 text-xs text-amber-800 sm:px-6">
                        <x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            @if ($return->damagedQuantity() > 0)
                                {{ $return->damagedQuantity() }} unit rusak tidak ditambahkan ke stok siap jual, tetapi tetap tercatat.
                            @endif
                            @if ($return->hasMissing())
                                {{ $return->missingQuantity() }} unit tercatat pada resi namun tidak ditemukan di dalam paket.
                            @endif
                        </span>
                    </div>
                @endif
            </x-ui.card>

            @if ($return->note)
                <x-ui.card title="Catatan">
                    <p class="whitespace-pre-line text-sm text-ink-600">{{ $return->note }}</p>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Status Dokumen">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $return])

                @if ($return->isEditable() && ! $return->isReadyToPost())
                    <ul class="space-y-2">
                        @foreach ($return->postingBlockers() as $blocker)
                            <li class="flex items-start gap-2 text-xs text-ink-600">
                                <x-icon name="warning" class="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" />
                                {{ $blocker }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <dl class="space-y-3 border-t border-ink-100 pt-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Asal</dt>
                        <dd class="font-medium text-ink-950">{{ $return->isMarketplace() ? $return->marketplace : 'Non-marketplace' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Alasan</dt>
                        <dd class="text-right font-medium text-ink-950">{{ $return->reason ?: '—' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-500">Resi retur</dt>
                        <dd class="break-all text-right font-mono text-xs text-ink-950">{{ $return->tracking_number ?: 'Tanpa resi' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-500">Nomor pesanan</dt>
                        <dd class="break-all text-right font-mono text-xs text-ink-950">{{ $return->reference ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $return->user?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
