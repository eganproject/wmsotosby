<x-app-layout title="Ubah Role">
    <x-ui.page-header :title="$role->name" subtitle="Perbarui informasi role dan hak akses yang dimilikinya."
                      :back="route('admin.roles.index')">
        <x-slot name="actions">
            <x-ui.badge variant="outline" icon="users">{{ $role->users()->count() }} pengguna</x-ui.badge>
        </x-slot>
    </x-ui.page-header>

    @include('admin.roles.partials.form', ['role' => $role, 'permissionGroups' => $permissionGroups, 'assigned' => $assigned])
</x-app-layout>
