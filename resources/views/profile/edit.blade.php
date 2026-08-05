<x-app-layout title="Profil Saya">
    <x-ui.page-header title="Profil Saya" icon="user"
                      subtitle="Perbarui informasi akun dan kata sandi Anda." />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Summary --}}
        <x-ui.card class="lg:col-span-1">
            <div class="flex flex-col items-center text-center">
                <x-ui.avatar :name="$user->name" size="xl" />
                <h2 class="mt-4 text-lg font-semibold tracking-tight text-ink-950">{{ $user->name }}</h2>
                <p class="text-sm text-ink-500">{{ $user->email }}</p>

                @if ($user->role)
                    <div class="mt-3">
                        <x-ui.badge :variant="$user->role->is_super_admin ? 'dark' : 'neutral'" icon="shield">
                            {{ $user->role->name }}
                        </x-ui.badge>
                    </div>
                @endif
            </div>

            <dl class="mt-6 space-y-4 border-t border-ink-100 pt-6 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="phone" class="h-4 w-4 text-ink-300" /> Telepon</dt>
                    <dd class="font-medium text-ink-950">{{ $user->phone ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="calendar" class="h-4 w-4 text-ink-300" /> Bergabung</dt>
                    <dd class="font-medium text-ink-950">{{ $user->created_at->translatedFormat('d M Y') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="flex items-center gap-2 text-ink-500"><x-icon name="clock" class="h-4 w-4 text-ink-300" /> Terakhir masuk</dt>
                    <dd class="font-medium text-ink-950">{{ $user->last_login_at?->diffForHumans() ?? 'Baru saja' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-2">
            {{-- Profile information --}}
            <x-ui.card title="Informasi Profil" subtitle="Perbarui nama, email, dan nomor telepon Anda.">
                <form method="POST" action="{{ route('profile.update') }}" class="space-y-5">
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.field label="Nama Lengkap" for="name" :error="$errors->get('name')" required class="sm:col-span-2">
                            <x-text-input id="name" name="name" type="text" :value="old('name', $user->name)"
                                          required autocomplete="name" />
                        </x-ui.field>

                        <x-ui.field label="Email" for="email" :error="$errors->get('email')" required>
                            <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)"
                                          required autocomplete="username" />
                        </x-ui.field>

                        <x-ui.field label="Nomor Telepon" for="phone" :error="$errors->get('phone')">
                            <x-text-input id="phone" name="phone" type="text" :value="old('phone', $user->phone)"
                                          placeholder="08xxxxxxxxxx" />
                        </x-ui.field>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-ui.button type="submit" icon="check">Simpan</x-ui.button>

                        @if (session('status') === 'profile-updated')
                            <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                               class="text-sm text-emerald-600">Tersimpan.</p>
                        @endif
                    </div>
                </form>
            </x-ui.card>

            {{-- Password --}}
            <x-ui.card title="Ubah Kata Sandi" subtitle="Gunakan kata sandi yang panjang dan sulit ditebak.">
                <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <x-ui.field label="Kata Sandi Saat Ini" for="current_password"
                                :error="$errors->updatePassword->get('current_password')" required>
                        <x-ui.password-input id="current_password" name="current_password" autocomplete="current-password" required />
                    </x-ui.field>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.field label="Kata Sandi Baru" for="update_password"
                                    :error="$errors->updatePassword->get('password')" hint="Minimal 8 karakter." required>
                            <x-ui.password-input id="update_password" name="password" autocomplete="new-password" required />
                        </x-ui.field>

                        <x-ui.field label="Konfirmasi Kata Sandi" for="update_password_confirmation"
                                    :error="$errors->updatePassword->get('password_confirmation')" required>
                            <x-ui.password-input id="update_password_confirmation" name="password_confirmation"
                                                 autocomplete="new-password" required />
                        </x-ui.field>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-ui.button type="submit" icon="lock">Perbarui Kata Sandi</x-ui.button>

                        @if (session('status') === 'password-updated')
                            <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                               class="text-sm text-emerald-600">Kata sandi diperbarui.</p>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
