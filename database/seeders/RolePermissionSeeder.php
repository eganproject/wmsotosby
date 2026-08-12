<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    /**
     * Every permission the application knows about, grouped by module.
     *
     * @var array<string, array<string, string>>
     */
    protected array $permissions = [
        'Dashboard' => [
            'dashboard.view' => 'Melihat dashboard',
        ],
        'Stok Barang' => [
            'products.view' => 'Melihat stok & data barang',
            'products.create' => 'Menambah barang',
            'products.update' => 'Mengubah barang',
            'products.delete' => 'Menghapus barang',
            'products.import' => 'Import barang & stok dari Excel',
        ],
        'Mutasi Stok' => [
            'movements.view' => 'Melihat mutasi stok',
            'movements.export' => 'Export mutasi stok ke Excel',
        ],
        'Laporan' => [
            'reports.view' => 'Melihat laporan stok & perputaran barang',
            'reports.export' => 'Export laporan stok ke Excel',
        ],
        'Barang Rusak' => [
            'disposals.view' => 'Melihat stok & penanganan barang rusak',
            'disposals.create' => 'Membuat penanganan barang rusak',
            'disposals.delete' => 'Menghapus penanganan barang rusak',
            'disposals.post' => 'Mengajukan penanganan barang rusak',
            'disposals.approve' => 'Menyetujui penanganan barang rusak',
        ],
        'Stok Opname' => [
            'opnames.view' => 'Melihat stok opname',
            'opnames.create' => 'Membuka sesi stok opname',
            'opnames.update' => 'Mengisi hasil hitung opname',
            'opnames.delete' => 'Menghapus sesi stok opname',
            'opnames.post' => 'Mengajukan hasil stok opname',
            'opnames.approve' => 'Menyetujui stok opname',
        ],
        'Penyesuaian Stok' => [
            'adjustments.view' => 'Melihat penyesuaian stok',
            'adjustments.create' => 'Membuat penyesuaian stok',
            'adjustments.update' => 'Mengubah penyesuaian stok',
            'adjustments.delete' => 'Menghapus penyesuaian stok',
            'adjustments.post' => 'Mengajukan penyesuaian stok',
            'adjustments.approve' => 'Menyetujui penyesuaian stok',
        ],
        'Pemasok' => [
            'suppliers.view' => 'Melihat daftar pemasok',
            'suppliers.create' => 'Menambah pemasok',
            'suppliers.update' => 'Mengubah pemasok',
            'suppliers.delete' => 'Menghapus pemasok',
        ],
        'Persetujuan' => [
            'approvals.view' => 'Melihat kotak masuk persetujuan',
        ],
        'Barang Masuk' => [
            'inbounds.view' => 'Melihat barang masuk',
            'inbounds.create' => 'Membuat barang masuk',
            'inbounds.update' => 'Mengubah barang masuk',
            'inbounds.delete' => 'Menghapus barang masuk',
            'inbounds.post' => 'Mengajukan & membatalkan barang masuk',
            'inbounds.approve' => 'Menyetujui barang masuk',
        ],
        'Barang Keluar' => [
            'outbounds.view' => 'Melihat barang keluar',
            'outbounds.create' => 'Membuat barang keluar',
            'outbounds.update' => 'Mengubah barang keluar',
            'outbounds.delete' => 'Menghapus barang keluar',
            'outbounds.scan' => 'Verifikasi scan resi & barang',
            'outbounds.post' => 'Mengajukan & membatalkan barang keluar',
            'outbounds.approve' => 'Menyetujui barang keluar',
        ],
        'Penerimaan Retur' => [
            'returns.view' => 'Melihat penerimaan retur',
            'returns.create' => 'Membuat penerimaan retur',
            'returns.update' => 'Mengubah penerimaan retur',
            'returns.delete' => 'Menghapus penerimaan retur',
            'returns.scan' => 'Verifikasi scan resi retur',
            'returns.post' => 'Mengajukan & membatalkan retur',
            'returns.approve' => 'Menyetujui penerimaan retur',
        ],
        'Import Resi' => [
            'imports.view' => 'Melihat data resi hasil import',
            'imports.create' => 'Mengimport berkas resi dari Ginee',
            'imports.cancel' => 'Menandai resi dibatalkan pembeli',
            'imports.delete' => 'Menghapus data import',
        ],
        'Pengguna' => [
            'users.view' => 'Melihat daftar pengguna',
            'users.create' => 'Menambah pengguna',
            'users.update' => 'Mengubah pengguna',
            'users.delete' => 'Menghapus pengguna',
        ],
        'Role' => [
            'roles.view' => 'Melihat daftar role',
            'roles.create' => 'Menambah role',
            'roles.update' => 'Mengubah role',
            'roles.delete' => 'Menghapus role',
        ],
        'Hak Akses' => [
            'permissions.view' => 'Melihat hak akses',
            'permissions.update' => 'Mengatur hak akses role',
        ],
        'API Stok' => [
            'stock-api-access.view' => 'Melihat daftar IP API stok',
            'stock-api-access.update' => 'Menambah dan mengubah IP API stok',
            'stock-api-access.delete' => 'Menghapus IP API stok',
        ],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $group => $items) {
            foreach ($items as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'group' => $group],
                );
            }
        }

        $superAdmin = Role::updateOrCreate(
            ['slug' => 'super-admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Akses penuh ke seluruh modul sistem.',
                'is_super_admin' => true,
            ],
        );
        $superAdmin->permissions()->sync(Permission::pluck('id'));

        $admin = Role::updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Admin',
                'description' => 'Mengelola operasional gudang dan pengguna.',
                'is_super_admin' => false,
            ],
        );
        // Admin mengelola seluruh operasional, tetapi tidak menghapus
        // pengguna maupun role.
        $admin->permissions()->sync(
            Permission::whereNotIn('slug', ['users.delete', 'roles.delete', 'permissions.update'])->pluck('id'),
        );

        $staff = Role::updateOrCreate(
            ['slug' => 'staff-gudang'],
            [
                'name' => 'Staff Gudang',
                'description' => 'Operasional harian gudang tanpa akses pengaturan.',
                'is_super_admin' => false,
            ],
        );
        $staff->permissions()->sync(
            Permission::whereIn('slug', [
                'dashboard.view',
                // Petugas gudang yang menemukan SKU baru harus bisa langsung
                // mendaftarkannya; menunggu admin berarti paket berhenti.
                'products.view', 'products.create', 'products.update',
                'movements.view', 'movements.export',
                'reports.view', 'reports.export',
                'inbounds.view', 'inbounds.create', 'inbounds.update', 'inbounds.post',
                'outbounds.view', 'outbounds.create', 'outbounds.update', 'outbounds.scan', 'outbounds.post',
                'returns.view', 'returns.create', 'returns.update', 'returns.scan', 'returns.post',
                'imports.view', 'imports.create', 'imports.cancel',
                'suppliers.view', 'suppliers.create',
                'adjustments.view', 'adjustments.create', 'adjustments.update', 'adjustments.post',
                'opnames.view', 'opnames.create', 'opnames.update', 'opnames.post',
                'disposals.view', 'disposals.create', 'disposals.post',
            ])->pluck('id'),
        );

        User::updateOrCreate(
            ['email' => 'admin@wmsotosby.test'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role_id' => $superAdmin->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
