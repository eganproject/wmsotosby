@php
    $user = $user ?? null;
    $isEdit = (bool) $user;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Main --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Informasi Pengguna" subtitle="Data dasar akun pengguna.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Nama Lengkap" for="name" :error="$errors->get('name')" required class="sm:col-span-2">
                        <x-text-input id="name" name="name" type="text" :value="old('name', $user?->name)"
                                      required autofocus placeholder="Contoh: Budi Santoso" />
                    </x-ui.field>

                    <x-ui.field label="Email" for="email" :error="$errors->get('email')" required>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                                <x-icon name="mail" class="h-4 w-4" />
                            </span>
                            <x-text-input id="email" name="email" type="email" :value="old('email', $user?->email)"
                                          required placeholder="nama@perusahaan.com" class="pl-10" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Nomor Telepon" for="phone" :error="$errors->get('phone')">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                                <x-icon name="phone" class="h-4 w-4" />
                            </span>
                            <x-text-input id="phone" name="phone" type="text" :value="old('phone', $user?->phone)"
                                          placeholder="08xxxxxxxxxx" class="pl-10" />
                        </div>
                    </x-ui.field>
                </div>
            </x-ui.card>

            {{--
                Kata sandi bisa dibuatkan sistem. Menyerahkan sandi awal ke
                orang yang membuat akun jauh lebih cepat daripada meminta tiap
                petugas gudang mengarang sandi sendiri di hari pertama.
            --}}
            <x-ui.card title="Kata Sandi"
                       :subtitle="$isEdit ? 'Kosongkan jika tidak ingin mengubah kata sandi.' : 'Minimal 8 karakter, atau biar sistem yang membuatkan.'"
                       x-data="{
                           generated: '',
                           make() {
                               const pool = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                               this.generated = Array.from({ length: 10 },
                                   () => pool[Math.floor(Math.random() * pool.length)]).join('');

                               this.$refs.password.value = this.generated;
                               this.$refs.confirmation.value = this.generated;
                           },
                       }">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.field label="Kata Sandi" for="password" :error="$errors->get('password')" :required="! $isEdit">
                        <x-ui.password-input id="password" name="password" autocomplete="new-password"
                                             x-ref="password" :required="! $isEdit" />
                    </x-ui.field>

                    <x-ui.field label="Konfirmasi Kata Sandi" for="password_confirmation"
                                :error="$errors->get('password_confirmation')" :required="! $isEdit">
                        <x-ui.password-input id="password_confirmation" name="password_confirmation"
                                             x-ref="confirmation" autocomplete="new-password" :required="! $isEdit" />
                    </x-ui.field>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" x-on:click="make()"
                            class="inline-flex h-10 items-center gap-2 rounded-xl bg-ink-100 px-3.5 text-sm font-medium text-ink-950 transition hover:bg-ink-200">
                        <x-icon name="sparkles" class="h-4 w-4" /> Buatkan kata sandi
                    </button>

                    <p x-show="generated" x-cloak class="text-sm text-ink-600">
                        Catat dan berikan ke pemiliknya:
                        <span class="ml-1 rounded-lg bg-amber-50 px-2 py-1 font-mono font-semibold text-amber-800 ring-1 ring-inset ring-amber-200"
                              x-text="generated"></span>
                    </p>
                </div>
            </x-ui.card>
        </div>

        {{-- Side --}}
        <div class="space-y-6">
            <x-ui.card title="Role & Status" subtitle="Menentukan hak akses pengguna.">
                <div class="space-y-5">
                    {{--
                        Role dipilih lewat kartu, bukan dropdown: yang perlu
                        diputuskan adalah "orang ini boleh apa", dan itu tidak
                        terbaca dari sekadar nama role.
                    --}}
                    <div class="space-y-2.5">
                        <p class="text-sm font-medium text-ink-950">Role <span class="text-red-600">*</span></p>

                        @foreach ($roles as $role)
                            @php
                                $slugs = $role->permissions->pluck('slug');
                                $abilities = array_values(array_filter([
                                    $role->is_super_admin || $slugs->contains(fn ($slug) => str_ends_with($slug, '.create')) ? 'Input data' : null,
                                    $role->is_super_admin || $slugs->contains(fn ($slug) => str_ends_with($slug, '.approve')) ? 'Menyetujui' : null,
                                    $role->is_super_admin || $slugs->contains('users.create') ? 'Kelola pengguna' : null,
                                ]));
                            @endphp

                            <label class="flex cursor-pointer gap-3 rounded-xl border p-3.5 transition
                                          has-[:checked]:border-ink-950 has-[:checked]:bg-ink-50/70 border-ink-100 hover:bg-ink-50/50">
                                <input type="radio" name="role_id" value="{{ $role->id }}" required
                                       @checked(old('role_id', $user?->role_id) == $role->id)
                                       class="mt-0.5 h-4 w-4 shrink-0 border-ink-300 text-ink-950 focus:ring-ink-950">

                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-ink-950">{{ $role->name }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-ink-500">
                                        {{ $role->description ?: 'Tanpa keterangan.' }}
                                    </span>

                                    <span class="mt-2 flex flex-wrap gap-1">
                                        @forelse ($abilities as $ability)
                                            <span class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">{{ $ability }}</span>
                                        @empty
                                            <span class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-500">Hanya melihat</span>
                                        @endforelse
                                    </span>
                                </span>
                            </label>
                        @endforeach

                        <x-input-error :messages="$errors->get('role_id')" class="mt-1" />
                    </div>

                    <div class="rounded-xl border border-ink-100 bg-ink-50/50 p-4">
                        <x-ui.toggle name="is_active" :checked="(bool) old('is_active', $user?->is_active ?? true)"
                                     label="Akun aktif"
                                     description="Akun nonaktif tidak dapat masuk ke sistem." />
                    </div>
                </div>
            </x-ui.card>

            @if ($isEdit)
                <x-ui.card title="Informasi Sistem">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Dibuat</dt>
                            <dd class="text-ink-950">{{ $user->created_at->translatedFormat('d M Y') }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">Terakhir masuk</dt>
                            <dd class="text-ink-950">{{ $user->last_login_at?->diffForHumans() ?? 'Belum pernah' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row">
                <x-ui.button type="submit" icon="check" class="flex-1">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Pengguna' }}
                </x-ui.button>
                <x-ui.button :href="route('admin.users.index')" variant="secondary" class="flex-1">Batal</x-ui.button>
            </div>
        </div>
    </div>
</form>
