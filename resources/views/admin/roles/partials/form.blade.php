@php
    $role = $role ?? null;
    $isEdit = (bool) $role;
    $selected = collect(old('permissions', $assigned))->map(fn ($id) => (int) $id)->all();
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.roles.update', $role) : route('admin.roles.store') }}"
      x-data="{ slugLocked: {{ $isEdit ? 'true' : 'false' }} }">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Identity --}}
        <div class="space-y-6 lg:col-span-1">
            <x-ui.card title="Informasi Role" subtitle="Nama dan identitas role.">
                <div class="space-y-5">
                    <x-ui.field label="Nama Role" for="name" :error="$errors->get('name')" required>
                        <x-text-input id="name" name="name" type="text" :value="old('name', $role?->name)"
                                      required autofocus placeholder="Contoh: Supervisor Gudang"
                                      x-on:input="if (! slugLocked) $refs.slug.value = $event.target.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')" />
                    </x-ui.field>

                    <x-ui.field label="Slug" for="slug" :error="$errors->get('slug')"
                                hint="Dipakai sebagai identitas unik role di sistem." required>
                        <x-text-input id="slug" name="slug" type="text" :value="old('slug', $role?->slug)"
                                      x-ref="slug" x-on:input="slugLocked = true"
                                      class="font-mono" placeholder="supervisor-gudang" />
                    </x-ui.field>

                    <x-ui.field label="Deskripsi" for="description" :error="$errors->get('description')">
                        <x-text-input id="description" name="description" type="text"
                                      :value="old('description', $role?->description)"
                                      placeholder="Ringkasan tugas role ini" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            @if ($isEdit && $role->is_super_admin)
                <div class="flex items-start gap-3 rounded-2xl bg-ink-950 p-4 text-white">
                    <x-icon name="sparkles" class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold">Role super admin</p>
                        <p class="mt-0.5 text-sm text-white/60">
                            Role ini selalu memiliki seluruh hak akses, apa pun pilihan di samping.
                        </p>
                    </div>
                </div>
            @endif

            <div class="hidden flex-col gap-2 sm:flex-row lg:flex">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Role' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.roles.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>

        {{-- Permissions --}}
        <div class="lg:col-span-2">
            <x-ui.card title="Hak Akses" subtitle="Centang permission yang boleh diakses role ini." padding="p-0">
                <x-slot name="actions">
                    <x-ui.button type="button" variant="ghost" size="sm"
                                 x-on:click="$root.querySelectorAll('.permission-check').forEach(el => el.checked = true)">
                        Pilih semua
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" size="sm"
                                 x-on:click="$root.querySelectorAll('.permission-check').forEach(el => el.checked = false)">
                        Kosongkan
                    </x-ui.button>
                </x-slot>

                <x-input-error :messages="$errors->get('permissions')" class="px-5 pt-4 sm:px-6" />

                <div class="divide-y divide-ink-50">
                    @foreach ($permissionGroups as $group => $permissions)
                        <div class="px-5 py-5 sm:px-6">
                            <div class="mb-3 flex items-center gap-2">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-ink-50 text-ink-950 ring-1 ring-ink-100">
                                    <x-icon name="key" class="h-4 w-4" />
                                </span>
                                <h3 class="text-sm font-semibold tracking-tight text-ink-950">{{ $group }}</h3>
                                <span class="text-xs text-ink-400">({{ $permissions->count() }})</span>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($permissions as $permission)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-100 p-3 transition hover:border-ink-200 hover:bg-ink-50/50">
                                        <x-ui.checkbox name="permissions[]" value="{{ $permission->id }}"
                                                       class="permission-check mt-0.5"
                                                       :checked="in_array($permission->id, $selected, true)" />
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-ink-800">{{ $permission->name }}</span>
                                            <span class="block truncate font-mono text-[11px] text-ink-400">{{ $permission->slug }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <div class="mt-6 flex flex-col gap-2 sm:flex-row lg:hidden">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Role' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.roles.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>
