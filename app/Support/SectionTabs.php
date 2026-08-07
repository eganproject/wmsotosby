<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Kelompok halaman yang sebenarnya melihat hal yang sama.
 *
 * Menu samping sempat memuat 14 tautan, padahal beberapa di antaranya cuma
 * sudut pandang berbeda atas objek yang sama — daftar barang dan mutasinya,
 * dokumen keluar dan antreannya, status resi dan berkas importnya. Yang
 * seperti itu digabung menjadi satu entri menu dengan tab.
 *
 * Tabnya berupa tautan biasa, bukan panel yang disembunyikan CSS: tiap tab
 * tetap route sendiri sehingga hanya data tab yang dibuka yang di-query.
 * Menyatukan tampilan tidak boleh berarti memuat semuanya sekaligus.
 */
class SectionTabs
{
    /**
     * Definisi kelompok. Urutan tab menentukan mana yang dibuka lebih dulu
     * bila pengguna tidak punya izin ke tab pertama.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected static function groups(): array
    {
        return [
            'stock' => [
                ['label' => 'Daftar Barang', 'icon' => 'box', 'route' => 'admin.products.index',
                    'match' => 'admin.products.*', 'can' => 'products.view'],
                ['label' => 'Mutasi Stok', 'icon' => 'chart', 'route' => 'admin.movements.index',
                    'match' => 'admin.movements.*', 'can' => 'movements.view'],
                ['label' => 'Laporan Stok', 'icon' => 'trending-up', 'route' => 'admin.reports.stock',
                    'match' => 'admin.reports.stock', 'can' => 'reports.view'],
                ['label' => 'Kebutuhan Restock', 'icon' => 'login', 'route' => 'admin.reports.restock',
                    'match' => 'admin.reports.restock', 'can' => 'reports.view'],
                ['label' => 'Barang Rusak', 'icon' => 'trash', 'route' => 'admin.disposals.index',
                    'match' => 'admin.disposals.*', 'can' => 'disposals.view'],
                ['label' => 'Pemasok', 'icon' => 'users', 'route' => 'admin.suppliers.index',
                    'match' => 'admin.suppliers.*', 'can' => 'suppliers.view'],
            ],

            'outbound' => [
                ['label' => 'Semua Dokumen', 'icon' => 'document', 'route' => 'admin.outbounds.index',
                    'match' => ['admin.outbounds.index', 'admin.outbounds.show', 'admin.outbounds.create', 'admin.outbounds.edit'],
                    'can' => 'outbounds.view'],
                ['label' => 'Siap Dikirim', 'icon' => 'check-circle', 'route' => 'admin.outbounds.ready',
                    'match' => 'admin.outbounds.ready', 'can' => 'outbounds.view',
                    'badge' => fn () => ReadyToShipCounter::count() ?: null],
                ['label' => 'Stasiun Packing', 'icon' => 'search', 'route' => 'admin.outbounds.marketplace',
                    'match' => 'admin.outbounds.marketplace', 'can' => 'outbounds.scan'],
            ],

            'adjustment' => [
                ['label' => 'Stok Opname', 'icon' => 'cube', 'route' => 'admin.opnames.index',
                    'match' => 'admin.opnames.*', 'can' => 'opnames.view'],
                ['label' => 'Penyesuaian Stok', 'icon' => 'sliders', 'route' => 'admin.adjustments.index',
                    'match' => 'admin.adjustments.*', 'can' => 'adjustments.view'],
            ],

            'waybill' => [
                ['label' => 'Status Resi', 'icon' => 'search', 'route' => 'admin.imports.status',
                    'match' => 'admin.imports.status', 'can' => 'imports.view'],
                ['label' => 'Data Import', 'icon' => 'document', 'route' => 'admin.imports.index',
                    'match' => ['admin.imports.index', 'admin.imports.show', 'admin.imports.create'],
                    'can' => 'imports.view'],
            ],

            'access' => [
                ['label' => 'Pengguna', 'icon' => 'users', 'route' => 'admin.users.index',
                    'match' => 'admin.users.*', 'can' => 'users.view'],
                ['label' => 'Role', 'icon' => 'shield', 'route' => 'admin.roles.index',
                    'match' => 'admin.roles.*', 'can' => 'roles.view'],
                ['label' => 'Hak Akses', 'icon' => 'key', 'route' => 'admin.permissions.index',
                    'match' => 'admin.permissions.*', 'can' => 'permissions.view'],
            ],
        ];
    }

    /**
     * Tab yang boleh dilihat pengguna saat ini, lengkap dengan status aktifnya.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(string $group): array
    {
        return static::allowed($group)
            ->map(fn (array $tab) => [
                'label' => $tab['label'],
                'icon' => $tab['icon'],
                'href' => route($tab['route']),
                'active' => request()->routeIs($tab['match']),
                'badge' => isset($tab['badge']) ? ($tab['badge'])() : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Alamat entri menu samping: tab pertama yang boleh dibuka pengguna.
     */
    public static function firstHref(string $group): ?string
    {
        $first = static::allowed($group)->first();

        return $first ? route($first['route']) : null;
    }

    /**
     * Apakah halaman yang sedang dibuka termasuk kelompok ini — dipakai untuk
     * menandai entri menu samping tetap aktif di seluruh tabnya.
     */
    public static function isActive(string $group): bool
    {
        return static::allowed($group)->contains(fn (array $tab) => request()->routeIs($tab['match']));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected static function allowed(string $group): Collection
    {
        $user = auth()->user();

        return collect(static::groups()[$group] ?? [])
            ->filter(fn (array $tab) => ! isset($tab['can']) || $user?->can($tab['can']));
    }
}
