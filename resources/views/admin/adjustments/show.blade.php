<x-app-layout title="Detail Penyesuaian Stok">
    <x-ui.page-header :title="$adjustment->code"
                      :subtitle="$adjustment->date->translatedFormat('d F Y').' · '.$adjustment->reason"
                      :back="route('admin.adjustments.index')">
        <x-slot name="actions">
            @if ($adjustment->isEditable())
                @can('adjustments.update')
                    <x-ui.button :href="route('admin.adjustments.edit', $adjustment)" variant="secondary" icon="pencil">Ubah</x-ui.button>
                @endcan

                @can('adjustments.post')
                    <form method="POST" action="{{ route('admin.adjustments.submit', $adjustment) }}">
                        @csrf
                        <x-ui.button type="submit" :icon="auth()->user()->can('adjustments.approve') ? 'check' : 'clock'">
                            {{ auth()->user()->can('adjustments.approve') ? 'Terapkan Penyesuaian' : 'Ajukan Persetujuan' }}
                        </x-ui.button>
                    </form>
                @endcan

                @can('adjustments.delete')
                    <x-ui.confirm-delete :action="route('admin.adjustments.destroy', $adjustment)"
                                         title="Hapus dokumen ini?"
                                         :description="'Draft '.$adjustment->code.' akan dihapus permanen.'" />
                @endcan
            @else
                @include('admin.partials.approval-actions', ['document' => $adjustment, 'prefix' => 'adjustments'])
            @endif
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Ringkasan selisih --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-2xl border border-ink-100 bg-white p-5 shadow-card">
                    <p class="text-xs font-medium uppercase tracking-wider text-ink-400">Baris Berselisih</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight text-ink-950">{{ $adjustment->changedItems()->count() }}</p>
                    <p class="text-[11px] text-ink-400">dari {{ $adjustment->items->count() }} baris</p>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <p class="text-xs font-medium uppercase tracking-wider text-emerald-700/70">Stok Bertambah</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight text-emerald-700">+{{ $adjustment->increaseQuantity() }}</p>
                    <p class="text-[11px] text-emerald-700/70">unit</p>
                </div>
                <div class="rounded-2xl border border-red-200 bg-red-50 p-5">
                    <p class="text-xs font-medium uppercase tracking-wider text-red-700/70">Stok Berkurang</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight text-red-700">−{{ $adjustment->decreaseQuantity() }}</p>
                    <p class="text-[11px] text-red-700/70">unit</p>
                </div>
            </div>

            {{-- Baris barang --}}
            <x-ui.card title="Hasil Hitung"
                       :subtitle="$adjustment->items->count().' baris diperiksa'" padding="p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-ink-100 text-left">
                        <thead class="bg-ink-50/60">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Barang</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Tercatat</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Hitung Fisik</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Selisih</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            @foreach ($adjustment->items as $item)
                                <tr class="{{ $item->difference() === 0 ? '' : 'bg-ink-50/30' }}">
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
                                    <td class="px-6 py-3.5 text-right text-sm text-ink-500">
                                        {{ $item->system_quantity }} <span class="text-xs text-ink-400">{{ $item->product->unit }}</span>
                                    </td>
                                    <td class="px-6 py-3.5 text-right text-sm font-semibold text-ink-950">
                                        {{ $item->actual_quantity }} <span class="text-xs font-normal text-ink-400">{{ $item->product->unit }}</span>
                                    </td>
                                    <td class="px-6 py-3.5 text-right">
                                        <span @class([
                                            'text-sm font-semibold',
                                            'text-emerald-600' => $item->difference() > 0,
                                            'text-red-600' => $item->difference() < 0,
                                            'text-ink-300' => $item->difference() === 0,
                                        ])>{{ $item->differenceLabel() }}</span>

                                        @if ($item->wasAppliedDifferently())
                                            <p class="text-[11px] text-amber-600">
                                                dibukukan {{ $item->applied_difference > 0 ? '+' : '' }}{{ $item->applied_difference }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($adjustment->isPosted() && $adjustment->items->contains(fn ($item) => $item->wasAppliedDifferently()))
                    <div class="flex items-start gap-2.5 border-t border-amber-100 bg-amber-50 px-5 py-3.5 text-xs text-amber-800 sm:px-6">
                        <x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Saldo tercatat sempat berubah antara dokumen disusun dan disetujui, sehingga selisih
                            yang dibukukan berbeda. Stok akhirnya tetap sama dengan hasil hitung fisik.
                        </span>
                    </div>
                @endif
            </x-ui.card>

            @if ($adjustment->note)
                <x-ui.card title="Catatan">
                    <p class="whitespace-pre-line text-sm text-ink-600">{{ $adjustment->note }}</p>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Status Dokumen">
            <div class="space-y-5">
                @include('admin.partials.approval-status', ['document' => $adjustment])

                @if ($adjustment->isEditable() && ! $adjustment->isReadyToPost())
                    <ul class="space-y-2">
                        @foreach ($adjustment->postingBlockers() as $blocker)
                            <li class="flex items-start gap-2 text-xs text-ink-600">
                                <x-icon name="warning" class="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" />
                                {{ $blocker }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <dl class="space-y-3 border-t border-ink-100 pt-4 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-ink-500">Alasan</dt>
                        <dd class="text-right font-medium text-ink-950">{{ $adjustment->reason }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Tanggal hitung</dt>
                        <dd class="font-medium text-ink-950">{{ $adjustment->date->translatedFormat('d M Y') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat oleh</dt>
                        <dd class="font-medium text-ink-950">{{ $adjustment->user?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">Dibuat pada</dt>
                        <dd class="font-medium text-ink-950">{{ $adjustment->created_at->translatedFormat('d M Y H:i') }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>
</x-app-layout>
