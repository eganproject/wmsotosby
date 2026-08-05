<x-app-layout title="Ubah Pengguna">
    <x-ui.page-header :title="$user->name" subtitle="Perbarui data, role, dan status akun pengguna."
                      :back="route('admin.users.index')">
        <x-slot name="actions">
            <x-ui.button :href="route('admin.users.show', $user)" variant="secondary" icon="eye">Lihat Detail</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('admin.users.partials.form', ['user' => $user, 'roles' => $roles])
</x-app-layout>
