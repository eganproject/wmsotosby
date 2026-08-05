<x-app-layout title="Pemasok">
    <x-ui.page-header title="Pemasok" icon="users"
                      subtitle="Master data pemasok yang dipakai pada dokumen barang masuk.">
        <x-slot name="actions">
            @can('suppliers.create')
                <x-ui.button :href="route('admin.suppliers.create')" icon="plus">Tambah Pemasok</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <form method="GET" action="{{ route('admin.suppliers.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama, kode, kontak, atau telepon..." class="pl-10" />
        </div>

        <x-ui.select name="status" class="sm:w-40">
            <option value="">Semua status</option>
            <option value="active" @selected(request('status') === 'active')>Aktif</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
        </x-ui.select>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
            @if (request()->hasAny(['search', 'status']))
                <x-ui.button :href="route('admin.suppliers.index')" variant="ghost" size="icon" title="Reset filter">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($suppliers->isEmpty())
            <x-ui.empty-state icon="users" title="Belum ada pemasok"
                              description="Tambahkan pemasok agar bisa dipilih saat membuat dokumen barang masuk.">
                @can('suppliers.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.suppliers.create')" icon="plus">Tambah Pemasok</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Pemasok</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Kontak</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Dokumen</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($suppliers as $supplier)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-md bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-ink-800 ring-1 ring-inset ring-ink-200">
                                            {{ $supplier->code }}
                                        </span>
                                        <p class="truncate text-sm font-medium text-ink-950">{{ $supplier->name }}</p>
                                    </div>
                                    @if ($supplier->address)
                                        <p class="mt-1 max-w-md truncate text-[11px] text-ink-400">{{ $supplier->address }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink-700">{{ $supplier->contact_name ?: '—' }}</p>
                                    <p class="text-[11px] text-ink-400">
                                        {{ $supplier->phone ?: 'tanpa telepon' }}@if ($supplier->email) &middot; {{ $supplier->email }} @endif
                                    </p>
                                </td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-ink-950">{{ $supplier->inbounds_count }}</td>
                                <td class="px-6 py-4">
                                    <x-ui.badge :variant="$supplier->is_active ? 'success' : 'danger'">
                                        {{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('admin.suppliers.show', $supplier) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('suppliers.update')
                                            <a href="{{ route('admin.suppliers.edit', $supplier) }}" title="Ubah"
                                               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                <x-icon name="pencil" class="h-4 w-4" />
                                            </a>
                                        @endcan
                                        @can('suppliers.delete')
                                            <x-ui.confirm-delete :action="route('admin.suppliers.destroy', $supplier)"
                                                                 title="Hapus pemasok ini?"
                                                                 :description="$supplier->name.' akan dihapus dari master data.'" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($suppliers as $supplier)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[11px] text-ink-400">{{ $supplier->code }}</p>
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $supplier->name }}</p>
                                <p class="truncate text-xs text-ink-400">{{ $supplier->phone ?: 'tanpa telepon' }}</p>
                            </div>
                            <x-ui.badge :variant="$supplier->is_active ? 'success' : 'danger'">
                                {{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}
                            </x-ui.badge>
                        </div>
                        <div class="mt-3 flex items-center justify-end gap-1 border-t border-ink-50 pt-3">
                            <x-ui.button :href="route('admin.suppliers.show', $supplier)" variant="ghost" size="sm" icon="eye">Detail</x-ui.button>
                            @can('suppliers.update')
                                <x-ui.button :href="route('admin.suppliers.edit', $supplier)" variant="secondary" size="sm" icon="pencil">Ubah</x-ui.button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$suppliers" />
        @endif
    </div>
</x-app-layout>
