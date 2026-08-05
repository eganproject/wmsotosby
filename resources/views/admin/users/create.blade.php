<x-app-layout title="Tambah Pengguna">
    <x-ui.page-header title="Tambah Pengguna" subtitle="Buat akun baru dan tentukan role aksesnya."
                      :back="route('admin.users.index')" />

    @include('admin.users.partials.form', ['user' => null, 'roles' => $roles])
</x-app-layout>
