<x-app-layout title="Detail Pengguna">
    <x-ui.page-header title="Detail Pengguna" subtitle="Informasi lengkap akun dan hak akses yang dimiliki."
                      :back="route('admin.users.index')">
        <x-slot name="actions">
            @can('users.update')
                <x-ui.button :href="route('admin.users.edit', $user)" icon="pencil">Ubah</x-ui.button>
            @endcan
            @can('users.delete')
                @unless ($user->is(auth()->user()))
                    <x-ui.confirm-delete :action="route('admin.users.destroy', $user)"
                                         title="Hapus pengguna ini?"
                                         :description="'Akun '.$user->name.' akan dihapus permanen dari sistem.'">
                        <x-slot name="trigger">
                            <x-ui.button type="button" variant="danger-soft" icon="trash">Hapus</x-ui.button>
                        </x-slot>
                    </x-ui.confirm-delete>
                @endunless
            @endcan
        </x-slot>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Profile --}}
        <x-ui.card class="lg:col-span-1">
            <div class="flex flex-col items-center text-center">
                <x-ui.avatar :name="$user->name" size="xl" />
                <h2 class="mt-4 text-lg font-semibold tracking-tight text-ink-950">{{ $user->name }}</h2>
                <p class="text-sm text-ink-500">{{ $user->email }}</p>

                <div class="mt-3 flex flex-wrap items-center justify-center gap-1.5">
                    <x-ui.badge :variant="$user->is_active ? 'success' : 'danger'"
                                :icon="$user->is_active ? 'check-circle' : 'x-circle'">
                        {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                    </x-ui.badge>
                    @if ($user->role)
                        <x-ui.badge :variant="$user->role->is_super_admin ? 'dark' : 'neutral'" icon="shield">
                            {{ $user->role->name }}
                        </x-ui.badge>
                    @endif
                </div>
            </div>

            <dl class="mt-6 space-y-4 border-t border-ink-100 pt-6 text-sm">
                <div class="flex items-start justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="phone" class="h-4 w-4 text-ink-300" /> Telepon</dt>
                    <dd class="text-right font-medium text-ink-950">{{ $user->phone ?: '—' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="calendar" class="h-4 w-4 text-ink-300" /> Bergabung</dt>
                    <dd class="text-right font-medium text-ink-950">{{ $user->created_at->translatedFormat('d M Y') }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="clock" class="h-4 w-4 text-ink-300" /> Terakhir masuk</dt>
                    <dd class="text-right font-medium text-ink-950">{{ $user->last_login_at?->diffForHumans() ?? 'Belum pernah' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Permissions --}}
        <x-ui.card class="lg:col-span-2"
                   :title="'Hak Akses — '.($user->role?->name ?? 'Tanpa role')"
                   subtitle="Daftar permission yang diwarisi dari role pengguna.">
            @if (! $user->role)
                <x-ui.empty-state icon="shield" title="Belum memiliki role"
                                  description="Tetapkan role agar pengguna mendapatkan hak akses." />
            @elseif ($user->role->is_super_admin)
                <div class="flex items-start gap-3 rounded-xl bg-ink-950 p-4 text-white">
                    <x-icon name="sparkles" class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold">Akses penuh</p>
                        <p class="mt-0.5 text-sm text-white/60">
                            Role super admin memiliki seluruh hak akses sistem secara otomatis.
                        </p>
                    </div>
                </div>
            @elseif ($user->role->permissions->isEmpty())
                <x-ui.empty-state icon="key" title="Belum ada hak akses"
                                  description="Role ini belum diberi permission apa pun." />
            @else
                <div class="space-y-5">
                    @foreach ($user->role->permissions->groupBy('group') as $group => $permissions)
                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-400">{{ $group }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($permissions as $permission)
                                    <x-ui.badge variant="outline" icon="check">{{ $permission->name }}</x-ui.badge>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
