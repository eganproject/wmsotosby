<x-app-layout title="Stok Opname">
    <x-ui.page-header title="Stok Opname" icon="cube"
                      subtitle="Sesi penghitungan fisik barang di rak, lengkap dengan selisihnya.">
        <x-slot name="actions">
            @can('opnames.create')
                <x-ui.button :href="route('admin.opnames.create')" icon="plus">Buka Sesi Opname</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="adjustment" />

    <form method="GET" action="{{ route('admin.opnames.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor sesi, cakupan, atau catatan..." class="pl-10" />
        </div>

        <x-ui.select name="status" class="sm:w-44">
            <option value="">Semua status</option>
            <option value="draft" @selected(request('status') === 'draft')>Sedang dihitung</option>
            <option value="pending" @selected(request('status') === 'pending')>Menunggu persetujuan</option>
            <option value="posted" @selected(request('status') === 'posted')>Selesai</option>
            <option value="rejected" @selected(request('status') === 'rejected')>Ditolak</option>
        </x-ui.select>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'status']))
                <x-ui.button :href="route('admin.opnames.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($opnames->isEmpty())
            <x-ui.empty-state icon="cube" title="Belum ada sesi opname"
                              description="Buka sesi untuk menghitung fisik barang di rak dan menemukan selisihnya.">
                @can('opnames.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.opnames.create')" icon="plus">Buka Sesi Opname</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Sesi</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Cakupan</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Progres Hitung</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($opnames as $opname)
                            @php
                                $total = (int) $opname->items_count;
                                $counted = (int) $opname->counted_items_count;
                                $percentage = $total > 0 ? (int) round($counted / $total * 100) : 0;
                            @endphp
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $opname->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $opname->date->translatedFormat('d M Y') }}</p>
                                </td>

                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink-800">{{ $opname->scopeLabel() }}</p>
                                    <p class="text-[11px] text-ink-400">{{ $total }} SKU dipotret</p>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-1.5 w-28 overflow-hidden rounded-full bg-ink-100">
                                            <div class="h-full rounded-full {{ $percentage === 100 ? 'bg-emerald-500' : 'bg-ink-950' }}"
                                                 style="width: {{ $percentage }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium tabular-nums text-ink-600">{{ $counted }}/{{ $total }}</span>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <x-ui.badge :variant="$opname->statusVariant()" :icon="$opname->statusIcon()">
                                        {{ $opname->statusLabel() }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.opnames.show', $opname) }}"
                                           class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-ink-950 px-3 text-xs font-medium text-white transition hover:bg-ink-800">
                                            <x-icon :name="$opname->isEditable() ? 'pencil' : 'eye'" class="h-3.5 w-3.5" />
                                            {{ $opname->isEditable() ? 'Hitung' : 'Lihat' }}
                                        </a>

                                        @can('opnames.delete')
                                            @if ($opname->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.opnames.destroy', $opname)"
                                                                     title="Hapus sesi ini?"
                                                                     :description="'Sesi '.$opname->code.' beserta seluruh hitungannya akan dihapus permanen.'" />
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
                @foreach ($opnames as $opname)
                    @php
                        $total = (int) $opname->items_count;
                        $counted = (int) $opname->counted_items_count;
                    @endphp
                    <a href="{{ route('admin.opnames.show', $opname) }}" class="block p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $opname->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $opname->date->translatedFormat('d M Y') }} &middot; {{ $opname->scopeLabel() }}
                                </p>
                            </div>
                            <x-ui.badge :variant="$opname->statusVariant()">{{ $opname->statusLabel() }}</x-ui.badge>
                        </div>

                        <p class="mt-2 text-xs text-ink-500">{{ $counted }} dari {{ $total }} SKU dihitung</p>
                    </a>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$opnames" />
        @endif
    </div>
</x-app-layout>
