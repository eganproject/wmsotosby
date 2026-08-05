<x-app-layout title="Barang Masuk">
    <x-ui.page-header title="Barang Masuk" icon="login"
                      subtitle="Dokumen penerimaan barang dari pemasok ke gudang.">
        <x-slot name="actions">
            @can('inbounds.create')
                <x-ui.button :href="route('admin.inbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <form method="GET" action="{{ route('admin.inbounds.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, pemasok, atau referensi..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="status" class="sm:w-40">
                <option value="">Semua status</option>
                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                <option value="posted" @selected(request('status') === 'posted')>Diposting</option>
            </x-ui.select>

            <x-text-input type="date" name="from" :value="request('from')" class="sm:w-40" title="Dari tanggal" />
            <x-text-input type="date" name="to" :value="request('to')" class="sm:w-40" title="Sampai tanggal" />
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'status', 'from', 'to']))
                <x-ui.button :href="route('admin.inbounds.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($inbounds->isEmpty())
            <x-ui.empty-state icon="login" title="Belum ada dokumen"
                              description="Buat dokumen barang masuk untuk menambah stok gudang.">
                @can('inbounds.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.inbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Dokumen</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Pemasok</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Baris</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Total Unit</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($inbounds as $inbound)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $inbound->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $inbound->date->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink-800">{{ $inbound->supplier?->name ?: '—' }}</p>
                                    @if ($inbound->reference)
                                        <p class="font-mono text-[11px] text-ink-400">{{ $inbound->reference }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-ink-600">{{ $inbound->items_count }}</td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-ink-950">
                                    {{ number_format((int) $inbound->items_sum_quantity, 0, ',', '.') }}
                                </td>
                                <td class="px-6 py-4">
                                    <x-ui.badge :variant="$inbound->statusVariant()" :icon="$inbound->statusIcon()">
                                        {{ $inbound->statusLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.inbounds.show', $inbound) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('inbounds.update')
                                            @if ($inbound->isEditable())
                                                <a href="{{ route('admin.inbounds.edit', $inbound) }}" title="Ubah"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                            @endif
                                        @endcan
                                        @can('inbounds.delete')
                                            @if ($inbound->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.inbounds.destroy', $inbound)"
                                                                     title="Hapus dokumen ini?"
                                                                     :description="'Draft '.$inbound->code.' akan dihapus permanen.'" />
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
                @foreach ($inbounds as $inbound)
                    <a href="{{ route('admin.inbounds.show', $inbound) }}" class="block p-4 transition hover:bg-ink-50/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $inbound->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $inbound->date->translatedFormat('d M Y') }} &middot; {{ $inbound->supplier?->name ?: 'tanpa pemasok' }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$inbound->statusVariant()">{{ $inbound->statusLabel() }}</x-ui.badge>
                        </div>
                        <p class="mt-2 text-xs text-ink-500">
                            {{ $inbound->items_count }} baris &middot;
                            <span class="font-medium text-ink-950">{{ number_format((int) $inbound->items_sum_quantity, 0, ',', '.') }}</span> unit
                        </p>
                    </a>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$inbounds" />
        @endif
    </div>
</x-app-layout>
