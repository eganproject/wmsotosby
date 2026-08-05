{{--
    Menu samping.

    Halaman yang sebenarnya melihat objek yang sama digabung menjadi satu
    entri, lalu dipisah dengan tab di dalam halamannya — lihat App\Support\
    SectionTabs. Menu ini menjawab "saya mau mengerjakan apa", bukan
    "ada halaman apa saja".

    Ditukar ulang setiap navigasi AJAX supaya status menu aktif tetap akurat.
--}}
@php
    use App\Support\SectionTabs;
@endphp

<nav data-sidebar-nav class="scrollbar-thin flex-1 space-y-6 overflow-y-auto px-3 py-5">
    <div class="space-y-1">
        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-400">Umum</p>

        <x-ui.nav-item :href="route('admin.dashboard')" icon="dashboard" :active="request()->routeIs('admin.dashboard')">
            Dashboard
        </x-ui.nav-item>

        @can('approvals.view')
            @php $pendingApprovals = \App\Support\ApprovalCounter::pending(); @endphp
            <x-ui.nav-item :href="route('admin.approvals.index')" icon="check-circle"
                           :active="request()->routeIs('admin.approvals.*')"
                           :badge="$pendingApprovals > 0 ? (string) $pendingApprovals : null">
                Menunggu Persetujuan
            </x-ui.nav-item>
        @endcan
    </div>

    @canany(['products.view', 'movements.view', 'suppliers.view', 'inbounds.view', 'outbounds.view', 'returns.view', 'adjustments.view', 'opnames.view', 'disposals.view', 'imports.view'])
        <div class="space-y-1">
            <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-400">Gudang</p>

            {{-- Barang, mutasi stoknya, dan pemasoknya — satu entri, tiga tab. --}}
            @if ($stockHref = SectionTabs::firstHref('stock'))
                <x-ui.nav-item :href="$stockHref" icon="box" :active="SectionTabs::isActive('stock')">
                    Barang &amp; Stok
                </x-ui.nav-item>
            @endif

            @can('inbounds.view')
                <x-ui.nav-item :href="route('admin.inbounds.index')" icon="login" :active="request()->routeIs('admin.inbounds.*')">
                    Barang Masuk
                </x-ui.nav-item>
            @endcan

            {{-- Dokumen keluar, antrean siap kirim, dan stasiun packing. --}}
            @if ($outboundHref = SectionTabs::firstHref('outbound'))
                <x-ui.nav-item :href="$outboundHref" icon="logout" :active="request()->routeIs('admin.outbounds.*')">
                    Barang Keluar
                </x-ui.nav-item>
            @endif

            @can('returns.view')
                <x-ui.nav-item :href="route('admin.returns.index')" icon="refresh" :active="request()->routeIs('admin.returns.*')">
                    Penerimaan Retur
                </x-ui.nav-item>
            @endcan

            {{-- Hitung fisik dan koreksi manual — dua cara menuju hasil yang sama. --}}
            @if ($adjustmentHref = SectionTabs::firstHref('adjustment'))
                <x-ui.nav-item :href="$adjustmentHref" icon="sliders" :active="SectionTabs::isActive('adjustment')">
                    Opname &amp; Penyesuaian
                </x-ui.nav-item>
            @endif

            {{-- Status resi dan berkas importnya. --}}
            @if ($waybillHref = SectionTabs::firstHref('waybill'))
                <x-ui.nav-item :href="$waybillHref" icon="search" :active="request()->routeIs('admin.imports.*')">
                    Resi
                </x-ui.nav-item>
            @endif
        </div>
    @endcanany

    @canany(['users.view', 'roles.view', 'permissions.view'])
        <div class="space-y-1">
            <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-400">Pengaturan</p>

            {{-- Pengguna, role, dan matriks hak aksesnya. --}}
            @if ($accessHref = SectionTabs::firstHref('access'))
                <x-ui.nav-item :href="$accessHref" icon="users" :active="SectionTabs::isActive('access')">
                    Pengguna &amp; Akses
                </x-ui.nav-item>
            @endif
        </div>
    @endcanany
</nav>
