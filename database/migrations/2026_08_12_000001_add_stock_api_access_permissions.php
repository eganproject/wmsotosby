<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            'stock-api-access.view' => 'Melihat daftar IP API stok',
            'stock-api-access.update' => 'Menambah dan mengubah IP API stok',
            'stock-api-access.delete' => 'Menghapus IP API stok',
        ];

        foreach ($permissions as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => 'API Stok', 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys($permissions))->pluck('id');
        $roleIds = DB::table('roles')->where(function ($query): void {
            $query->where('is_super_admin', true)->orWhere('slug', 'admin');
        })->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', [
            'stock-api-access.view', 'stock-api-access.update', 'stock-api-access.delete',
        ])->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
