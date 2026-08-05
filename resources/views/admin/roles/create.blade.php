<x-app-layout title="Tambah Role">
    <x-ui.page-header title="Tambah Role" subtitle="Buat kelompok hak akses baru untuk pengguna."
                      :back="route('admin.roles.index')" />

    @include('admin.roles.partials.form', ['role' => null, 'permissionGroups' => $permissionGroups, 'assigned' => $assigned])
</x-app-layout>
