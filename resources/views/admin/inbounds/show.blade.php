<x-app-layout title="Detail Barang Masuk">
    <x-ui.page-header :title="$inbound->code" :subtitle="$inbound->date->translatedFormat('d F Y').' · '.($inbound->supplier?->name ?: 'Tanpa pemasok')"
                      :back="route('admin.inbounds.index')">
        <x-slot name="actions">
            @if ($inbound->isEditable())
                @can('inbounds.update')
                    <x-ui.button :href="route('admin.inbounds.edit', $inbound)" variant="secondary" icon="pencil">Ubah</x-ui.button>
                @endcan
                @can('inbounds.post')
                    <form method="POST" action="{{ route('admin.inbounds.submit', $inbound) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('inbounds.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('inbounds.approve') ? 'Setujui & Posting' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endcan
                @can('inbounds.delete')
                    <x-ui.confirm-delete :action="route('admin.inbounds.destroy', $inbound)"
                                         title="Hapus dokumen ini?"
                                         :description="'Draft '.$inbound->code.' akan dihapus permanen.'" />
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $inbound, 'prefix' => 'inbounds'])
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Baris Barang" :subtitle="$inbound->items->count().' baris · total '.number_format($inbound->totalQuantity(), 0, ',', '.').' unit'" padding="p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 text-left">
                        <thead class="bg-ink-50/60">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Catatan</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            @foreach ($inbound->items as $item)
                                <tr>
                                    <td class="px-6 py-3.5">
                                        <a href="{{ route('admin.products.show', $item->product) }}"
                                           class="text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                            {{ $item->product->name }}
                                        </a>
                                        <div class="mt-1"><x-ui.sku :value="$item->product->sku" /></div>
                                    </td>
                                    <td class="px-6 py-3.5 text-sm text-ink-500">{{ $item->note ?: '—' }}</td>
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

            @if ($inbound->note)
                <x-ui.card title="Catatan">
                    <p class="whitespace-pre-line text-sm text-ink-600">{{ $inbound->note }}</p>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Status Dokumen">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $inbound])

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Pemasok</dt>
                        <dd class="text-right font-medium text-ink-950">{{ $inbound->supplier?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Tanggal</dt>
                        <dd class="font-medium text-ink-950">{{ $inbound->date->translatedFormat('d M Y') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Referensi</dt>
                        <dd class="font-mono text-xs text-ink-950">{{ $inbound->reference ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $inbound->user?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat pada</dt>
                        <dd class="font-medium text-ink-950">{{ $inbound->created_at->translatedFormat('d M Y H:i') }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
