<x-app-layout title="Detail Barang Keluar">
    <x-ui.page-header :title="$outbound->code"
                      :subtitle="$outbound->date->translatedFormat('d F Y').' · '.$outbound->recipient"
                      :back="route('admin.outbounds.index')">
        <x-slot name="actions">
            @if ($outbound->isEditable())
                @if ($outbound->isMarketplace() && ! $outbound->isReadyToPost())
                    @can('outbounds.scan')
                        <x-ui.button :href="route('admin.outbounds.scan', $outbound)" icon="search">Verifikasi Scan</x-ui.button>
                    @endcan
                @elseif (auth()->user()->can('outbounds.post'))
                    <form method="POST" action="{{ route('admin.outbounds.submit', $outbound) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('outbounds.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('outbounds.approve') ? 'Setujui & Kirim' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endif

                @can('outbounds.update')
                    <x-ui.button :href="route('admin.outbounds.edit', $outbound)" variant="secondary" icon="pencil">Ubah</x-ui.button>
                @endcan
                @can('outbounds.delete')
                    <x-ui.confirm-delete :action="route('admin.outbounds.destroy', $outbound)"
                                         title="Hapus dokumen ini?"
                                         :description="'Draft '.$outbound->code.' akan dihapus permanen.'" />
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $outbound, 'prefix' => 'outbounds'])
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Status verifikasi marketplace --}}
            @if ($outbound->isMarketplace())
                <div class="overflow-hidden rounded-2xl border {{ $outbound->isReadyToPost() ? 'border-emerald-200 bg-emerald-50' : 'border-ink-100 bg-white' }} p-5 shadow-card sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $outbound->isReadyToPost() ? 'bg-emerald-600 text-white' : 'bg-ink-950 text-white' }}">
                                <x-icon :name="$outbound->isReadyToPost() ? 'check-circle' : 'shield'" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="text-sm font-semibold {{ $outbound->isReadyToPost() ? 'text-emerald-800' : 'text-ink-950' }}">
                                    {{ $outbound->isPosted() ? 'Terverifikasi & terkirim' : ($outbound->isReadyToPost() ? 'Verifikasi selesai' : 'Verifikasi scan belum selesai') }}
                                </p>
                                <p class="mt-0.5 text-xs {{ $outbound->isReadyToPost() ? 'text-emerald-700/80' : 'text-ink-500' }}">
                                    Pengiriman {{ $outbound->marketplace }} &middot; resi
                                    <span class="font-mono">{{ $outbound->tracking_number }}</span>
                                </p>
                            </div>
                        </div>

                        @if ($outbound->isEditable())
                            @can('outbounds.scan')
                                <x-ui.button :href="route('admin.outbounds.scan', $outbound)" variant="secondary" size="sm" icon="search">
                                    Buka Panel Scan
                                </x-ui.button>
                            @endcan
                        @endif
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="flex items-center gap-2.5 rounded-xl bg-white/70 p-3 ring-1 ring-inset ring-ink-100">
                            <x-icon :name="$outbound->isResiVerified() ? 'check-circle' : 'x-circle'"
                                    class="h-4 w-4 shrink-0 {{ $outbound->isResiVerified() ? 'text-emerald-600' : 'text-ink-300' }}" />
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-ink-950">Scan resi</p>
                                <p class="text-[11px] text-ink-500">
                                    {{ $outbound->isResiVerified()
                                        ? 'Terverifikasi '.$outbound->resi_verified_at->translatedFormat('d M Y H:i')
                                        : 'Belum discan' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 rounded-xl bg-white/70 p-3 ring-1 ring-inset ring-ink-100">
                            <x-icon :name="$outbound->isFullyScanned() ? 'check-circle' : 'x-circle'"
                                    class="h-4 w-4 shrink-0 {{ $outbound->isFullyScanned() ? 'text-emerald-600' : 'text-ink-300' }}" />
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-ink-950">Scan barang</p>
                                <p class="text-[11px] text-ink-500">
                                    {{ $outbound->totalScanned() }} dari {{ $outbound->totalQuantity() }} unit discan
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Baris barang --}}
            <x-ui.card title="Baris Barang"
                       :subtitle="$outbound->items->count().' baris · total '.number_format($outbound->totalQuantity(), 0, ',', '.').' unit'"
                       padding="p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 text-left">
                        <thead class="bg-ink-50/60">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                @if ($outbound->isMarketplace())
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-ink-500">Discan</th>
                                @endif
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            @foreach ($outbound->items as $item)
                                <tr>
                                    <td class="px-6 py-3.5">
                                        <a href="{{ route('admin.products.show', $item->product) }}"
                                           class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $item->product->name }}
                                        </a>
                                        <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                    </td>
                                    @if ($outbound->isMarketplace())
                                        <td class="px-6 py-3.5 text-center">
                                            <x-ui.badge :variant="$item->isFullyScanned() ? 'success' : 'neutral'">
                                                {{ $item->scanned_quantity }}/{{ $item->quantity }}
                                            </x-ui.badge>
                                        </td>
                                    @endif
                                    <td class="px-6 py-3.5 text-right">
                                        <span class="text-sm font-semibold text-ink-950">{{ number_format($item->quantity, 0, ',', '.') }}</span>
                                        <span class="text-xs text-ink-400">{{ $item->product->unit }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            @if ($outbound->note)
                <x-ui.card title="Catatan">
                    <p class="whitespace-pre-line text-sm text-ink-600">{{ $outbound->note }}</p>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Status Dokumen">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $outbound])

                @if ($outbound->isEditable() && ! $outbound->isReadyToPost())
                    <ul class="space-y-2">
                        @foreach ($outbound->postingBlockers() as $blocker)
                            <li class="flex items-start gap-2 text-xs text-ink-600">
                                <x-icon name="warning" class="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" />
                                {{ $blocker }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <dl class="space-y-3 border-t border-ink-100 pt-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Jenis</dt>
                        <dd class="font-medium text-ink-950">{{ $outbound->isMarketplace() ? $outbound->marketplace : 'Reguler' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-500">Resi</dt>
                        <dd class="break-all text-right font-mono text-xs text-ink-950">{{ $outbound->tracking_number ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $outbound->user?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat pada</dt>
                        <dd class="font-medium text-ink-950">{{ $outbound->created_at->translatedFormat('d M Y H:i') }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
