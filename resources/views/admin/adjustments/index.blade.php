<x-app-layout title="Penyesuaian Stok">
    <x-ui.page-header title="Penyesuaian Stok" icon="sliders"
                      subtitle="Selaraskan saldo tercatat dengan hasil hitung fisik di rak.">
        <x-slot name="actions">
            @can('adjustments.create')
                <x-ui.button :href="route('admin.adjustments.create')" icon="plus">Buat Penyesuaian</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="adjustment" />

    <form method="GET" action="{{ route('admin.adjustments.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, alasan, atau catatan..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="reason" class="sm:w-52">
                <option value="">Semua alasan</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason }}" @selected(request('reason') === $reason)>{{ $reason }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="status" class="sm:w-40">
                <option value="">Semua status</option>
                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                <option value="pending" @selected(request('status') === 'pending')>Menunggu</option>
                <option value="posted" @selected(request('status') === 'posted')>Disesuaikan</option>
                <option value="rejected" @selected(request('status') === 'rejected')>Ditolak</option>
            </x-ui.select>
        </div>

        <x-ui.date-filter label="Tanggal penyesuaian" />

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'status', 'reason', 'from', 'to']))
                <x-ui.button :href="route('admin.adjustments.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($adjustments->isEmpty())
            <x-ui.empty-state icon="sliders" title="Belum ada penyesuaian"
                              description="Buat dokumen penyesuaian setelah menghitung fisik barang di gudang.">
                @can('adjustments.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.adjustments.create')" icon="plus">Buat Penyesuaian</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Dokumen</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Alasan</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Baris</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Selisih</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($adjustments as $adjustment)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $adjustment->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $adjustment->date->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink-800">{{ $adjustment->reason }}</p>
                                    @if ($adjustment->note)
                                        <p class="max-w-xs truncate text-[11px] text-ink-400">{{ $adjustment->note }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-ink-600">{{ $adjustment->items_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <span class="text-sm font-semibold text-emerald-600">+{{ $adjustment->increaseQuantity() }}</span>
                                    <span class="text-ink-300">/</span>
                                    <span class="text-sm font-semibold text-red-600">−{{ $adjustment->decreaseQuantity() }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <x-ui.badge :variant="$adjustment->statusVariant()" :icon="$adjustment->statusIcon()">
                                        {{ $adjustment->statusLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.adjustments.show', $adjustment) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('adjustments.update')
                                            @if ($adjustment->isEditable())
                                                <a href="{{ route('admin.adjustments.edit', $adjustment) }}" title="Ubah"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                            @endif
                                        @endcan
                                        @can('adjustments.delete')
                                            @if ($adjustment->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.adjustments.destroy', $adjustment)"
                                                                     title="Hapus dokumen ini?"
                                                                     :description="'Draft '.$adjustment->code.' akan dihapus permanen.'" />
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($adjustments as $adjustment)
                    <a href="{{ route('admin.adjustments.show', $adjustment) }}" class="block p-4 transition hover:bg-ink-50/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $adjustment->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $adjustment->date->translatedFormat('d M Y') }} &middot; {{ $adjustment->reason }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$adjustment->statusVariant()">{{ $adjustment->statusLabel() }}</x-ui.badge>
                        </div>
                        <p class="mt-2 text-xs">
                            <span class="font-semibold text-emerald-600">+{{ $adjustment->increaseQuantity() }}</span>
                            <span class="text-ink-300">/</span>
                            <span class="font-semibold text-red-600">−{{ $adjustment->decreaseQuantity() }}</span>
                            <span class="text-ink-400">&middot; {{ $adjustment->items_count }} baris</span>
                        </p>
                    </a>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$adjustments" />
        @endif
    </div>
</x-app-layout>
