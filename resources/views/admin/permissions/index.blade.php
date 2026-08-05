<x-app-layout title="Hak Akses">
    <x-ui.page-header title="Matriks Hak Akses" icon="key"
                      subtitle="Atur permission untuk setiap role dalam satu tampilan.">
    </x-ui.page-header>

    <x-ui.tabs group="access" />

    <form method="POST" action="{{ route('admin.permissions.update') }}">
        @csrf
        @method('PUT')

        <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
                <div>
                    <h2 class="text-sm font-semibold tracking-tight text-ink-950">Permission per Role</h2>
                    <p class="mt-0.5 text-xs text-ink-500">
                        {{ $roles->count() }} role &middot; {{ $permissionGroups->flatten()->count() }} permission
                    </p>
                </div>

                @can('permissions.update')
                    <x-ui.button type="submit" icon="check">Simpan Perubahan</x-ui.button>
                @endcan
            </div>

            <div class="overflow-x-auto scrollbar-thin">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 min-w-[240px] bg-ink-50/60 px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500 backdrop-blur">
                                Permission
                            </th>
                            @foreach ($roles as $role)
                                <th scope="col" class="whitespace-nowrap px-5 py-3.5 text-center">
                                    <span class="block text-xs font-semibold text-ink-950">{{ $role->name }}</span>
                                    <span class="mt-0.5 block font-mono text-[10px] font-normal text-ink-400">{{ $role->slug }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-ink-50">
                        @foreach ($permissionGroups as $group => $permissions)
                            <tr class="bg-ink-50/40">
                                <td colspan="{{ $roles->count() + 1 }}" class="px-6 py-2.5">
                                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink-500">{{ $group }}</span>
                                </td>
                            </tr>

                            @foreach ($permissions as $permission)
                                <tr class="transition hover:bg-ink-50/50">
                                    <td class="sticky left-0 z-10 bg-white px-6 py-3.5">
                                        <p class="text-sm font-medium text-ink-950">{{ $permission->name }}</p>
                                        <p class="font-mono text-[11px] text-ink-400">{{ $permission->slug }}</p>
                                    </td>

                                    @foreach ($roles as $role)
                                        <td class="px-5 py-3.5 text-center">
                                            @if ($role->is_super_admin)
                                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-ink-950 text-white"
                                                      title="Super admin selalu memiliki semua akses">
                                                    <x-icon name="check" class="h-3.5 w-3.5" />
                                                </span>
                                            @else
                                                <x-ui.checkbox name="matrix[{{ $role->id }}][]"
                                                               value="{{ $permission->id }}"
                                                               :checked="in_array($permission->id, $matrix[$role->id] ?? [], true)"
                                                               :disabled="! auth()->user()->can('permissions.update')" />
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @can('permissions.update')
                <div class="flex flex-col gap-3 border-t border-ink-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="flex items-start gap-2 text-xs text-ink-500">
                        <x-icon name="info" class="mt-px h-3.5 w-3.5 shrink-0 text-ink-300" />
                        Role super admin tidak dapat diubah karena selalu memiliki seluruh hak akses.
                    </p>
                    <x-ui.button type="submit" icon="check">Simpan Perubahan</x-ui.button>
                </div>
            @endcan
        </div>
    </form>
</x-app-layout>
