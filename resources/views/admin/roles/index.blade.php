<x-app-layout title="Role">
    <x-ui.page-header title="Manajemen Role" icon="shield"
                      subtitle="Kelompokkan hak akses pengguna ke dalam role yang jelas.">
        <x-slot name="actions">
            @can('roles.create')
                <x-ui.button :href="route('admin.roles.create')" icon="plus">Tambah Role</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="access" />

    <form method="GET" action="{{ route('admin.roles.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nama role atau slug..." class="pl-10" />
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="secondary" icon="search" class="flex-1 sm:flex-none">Cari</x-ui.button>
            @if (request('search'))
                <x-ui.button :href="route('admin.roles.index')" variant="ghost" size="icon" title="Reset">
                    <x-icon name="refresh" class="h-4 w-4" />
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($roles->isEmpty())
        <div class="rounded-2xl border border-ink-100 bg-white shadow-card">
            <x-ui.empty-state icon="shield" title="Role tidak ditemukan"
                              description="Buat role baru untuk mengelompokkan hak akses pengguna.">
                @can('roles.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.roles.create')" icon="plus">Tambah Role</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($roles as $role)
                <div class="group flex flex-col rounded-2xl border border-ink-100 bg-white p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-lift">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $role->is_super_admin ? 'bg-ink-950 text-white' : 'bg-ink-50 text-ink-950 ring-1 ring-ink-100' }}">
                                <x-icon name="shield" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink-950">{{ $role->name }}</p>
                                <p class="truncate font-mono text-xs text-ink-400">{{ $role->slug }}</p>
                            </div>
                        </div>

                        @if ($role->is_super_admin)
                            <x-ui.badge variant="dark" icon="sparkles">Super</x-ui.badge>
                        @endif
                    </div>

                    <p class="mt-4 line-clamp-2 min-h-[2.5rem] text-sm text-ink-500">
                        {{ $role->description ?: 'Tidak ada deskripsi.' }}
                    </p>

                    <div class="mt-4 flex items-center gap-2">
                        <x-ui.badge variant="outline" icon="users">{{ $role->users_count }} pengguna</x-ui.badge>
                        <x-ui.badge variant="outline" icon="key">
                            {{ $role->is_super_admin ? 'Semua' : $role->permissions_count }} akses
                        </x-ui.badge>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-1 border-t border-ink-50 pt-4">
                        @can('roles.update')
                            <x-ui.button :href="route('admin.roles.edit', $role)" variant="secondary" size="sm" icon="pencil">
                                Ubah
                            </x-ui.button>
                        @endcan
                        @can('roles.delete')
                            @unless ($role->is_super_admin)
                                <x-ui.confirm-delete :action="route('admin.roles.destroy', $role)"
                                                     title="Hapus role ini?"
                                                     :description="'Role '.$role->name.' akan dihapus permanen.'" />
                            @endunless
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $roles->links() }}
        </div>
    @endif
</x-app-layout>
