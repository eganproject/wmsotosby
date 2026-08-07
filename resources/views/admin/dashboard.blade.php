<x-app-layout title="Dashboard">
    <x-ui.page-header title="Halo, {{ str(auth()->user()->name)->before(' ') }} 👋"
                      subtitle="Ringkasan kondisi gudang dan aktivitas terakhir.">
        <x-slot name="actions">
            @can('inbounds.create')
                <x-ui.button :href="route('admin.inbounds.create')" variant="secondary" icon="login">Barang Masuk</x-ui.button>
            @endcan
            @can('outbounds.create')
                <x-ui.button :href="route('admin.outbounds.create')" icon="logout">Barang Keluar</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    {{-- Statistik --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total Unit Stok" :value="number_format($stats['stock_units'], 0, ',', '.')" icon="box" accent
                        :hint="$stats['products'].' jenis barang terdaftar'" />
        @can('disposals.view')
            <x-ui.stat-card label="Barang Rusak" :value="number_format($stats['damaged_units'], 0, ',', '.')" icon="trash"
                            hint="Unit rusak menunggu ditangani" />
        @endcan
        <x-ui.stat-card label="Stok Menipis" :value="$stats['low_stock']" icon="warning"
                        hint="Perlu segera direstok" />
        <x-ui.stat-card label="Masuk Bulan Ini" :value="$stats['inbound_month']" icon="login"
                        hint="Dokumen sudah diposting" />
        <x-ui.stat-card label="Keluar Bulan Ini" :value="$stats['outbound_month']" icon="logout"
                        hint="Dokumen sudah dikirim" />
    </div>

    {{-- Pesanan menunggu --}}
    @if ($stats['outbound_pending'] > 0)
        @can('outbounds.view')
            <a href="{{ route('admin.outbounds.index', ['status' => 'draft']) }}"
               class="mt-6 flex items-center gap-4 rounded-2xl border border-ink-950 bg-ink-950 p-5 text-white shadow-card transition hover:bg-ink-800">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10">
                    <x-icon name="clock" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ $stats['outbound_pending'] }} pengiriman menunggu diproses</p>
                    <p class="mt-0.5 text-xs text-white/60">
                        @if ($stats['marketplace_pending'] > 0)
                            {{ $stats['marketplace_pending'] }} di antaranya pesanan marketplace yang butuh verifikasi scan.
                        @else
                            Buka daftar barang keluar untuk menindaklanjuti.
                        @endif
                    </p>
                </div>
                <x-icon name="arrow-right" class="h-5 w-5 shrink-0" />
            </a>
        @endcan
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Pergerakan stok terakhir --}}
        <x-ui.card class="lg:col-span-2" title="Pergerakan Stok Terakhir"
                   subtitle="Aktivitas masuk dan keluar terbaru" padding="p-0">
            <x-slot name="actions">
                @can('products.view')
                    <x-ui.button :href="route('admin.products.index')" variant="ghost" size="sm">
                        Lihat stok <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                    </x-ui.button>
                @endcan
            </x-slot>

            @forelse ($movements as $movement)
                <div class="flex items-center gap-3 border-b border-ink-50 px-5 py-3.5 last:border-0 sm:px-6">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $movement->isIncoming() ? 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100' : 'bg-red-50 text-red-600 ring-1 ring-red-100' }}">
                        <x-icon :name="$movement->isIncoming() ? 'login' : 'logout'" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <x-ui.sku :value="$movement->product->sku" :label="false" />
                            <p class="truncate text-sm font-medium text-ink-950">{{ $movement->product->name }}</p>
                        </div>
                        <p class="truncate text-xs text-ink-400">{{ $movement->description }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold {{ $movement->isIncoming() ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $movement->isIncoming() ? '+' : '−' }}{{ $movement->quantity }}
                        </p>
                        <p class="text-[11px] text-ink-400">saldo {{ $movement->balance_after }}</p>
                    </div>
                </div>
            @empty
                <x-ui.empty-state icon="refresh" title="Belum ada pergerakan stok"
                                  description="Buat dokumen barang masuk untuk mulai mengisi stok gudang." />
            @endforelse
        </x-ui.card>

        {{-- Status resi: tahapan alur gudang, dihitung dari dokumennya --}}
        <x-ui.card title="Status Resi" subtitle="Posisi resi hasil import di alur gudang">
            @php
                $stages = [
                    \App\Models\ShipmentOrder::STAGE_AWAITING_QC => ['label' => 'Belum QC', 'icon' => 'clock',
                        'hint' => 'Resi atau barangnya belum tuntas discan', 'tone' => 'text-amber-700 bg-amber-50'],
                    \App\Models\ShipmentOrder::STAGE_CHECKED => ['label' => 'Siap Dikirim', 'icon' => 'check-circle',
                        'hint' => 'QC selesai, menunggu diproses', 'tone' => 'text-ink-950 bg-ink-100'],
                    \App\Models\ShipmentOrder::STAGE_SHIPPED => ['label' => 'Dikirim', 'icon' => 'logout',
                        'hint' => 'Disetujui, stok sudah berkurang', 'tone' => 'text-emerald-700 bg-emerald-50'],
                    \App\Models\ShipmentOrder::STAGE_CANCELLED => ['label' => 'Dibatalkan', 'icon' => 'x-circle',
                        'hint' => 'Batal sebelum berangkat — jangan dikirim', 'tone' => 'text-red-700 bg-red-50'],
                ];
                $waybillTotal = max(array_sum($waybills), 1);
            @endphp

            <div class="space-y-4">
                @foreach ($stages as $key => $meta)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink-800">
                                <x-icon :name="$meta['icon']" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                <span class="truncate">{{ $meta['label'] }}</span>
                            </span>
                            <span class="shrink-0 rounded-lg px-2 py-0.5 text-xs font-semibold {{ $meta['tone'] }}">
                                {{ number_format($waybills[$key], 0, ',', '.') }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-ink-950"
                                 style="width: {{ round($waybills[$key] / $waybillTotal * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-400">{{ $meta['hint'] }}</p>
                    </div>
                @endforeach
            </div>

            @can('imports.view')
                <a href="{{ route('admin.imports.status') }}"
                   class="mt-6 flex items-center justify-between rounded-xl bg-ink-50 px-4 py-3 text-sm font-medium text-ink-950 transition hover:bg-ink-100">
                    Lihat status resi
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            @endcan
        </x-ui.card>

        {{-- Pesanan per ekspedisi --}}
        <x-ui.card title="Pesanan per Ekspedisi" subtitle="Dari data resi hasil import">
            @php $courierTotal = max($couriers->sum('total'), 1); @endphp

            <div class="space-y-4">
                @forelse ($couriers as $courier)
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-ink-800">
                                <x-icon name="logout" class="h-3.5 w-3.5 shrink-0 text-ink-400" />
                                <span class="truncate">{{ $courier->courier ?: 'Tanpa ekspedisi' }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold text-ink-950">{{ $courier->total }}</span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-ink-950"
                                 style="width: {{ round($courier->total / $courierTotal * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-400">
                            {{ $courier->shipped }} sudah dikirim &middot;
                            <span class="font-medium text-ink-600">{{ $courier->total - $courier->shipped }} menunggu</span>
                        </p>
                    </div>
                @empty
                    <div class="py-6 text-center">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-ink-50 text-ink-400 ring-1 ring-ink-100">
                            <x-icon name="document" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink-950">Belum ada data resi</p>
                        <p class="mt-0.5 text-xs text-ink-500">Import berkas Ginee untuk melihat sebaran ekspedisi.</p>
                    </div>
                @endforelse
            </div>

            @can('imports.view')
                <a href="{{ route('admin.imports.index') }}"
                   class="mt-6 flex items-center justify-between rounded-xl bg-ink-50 px-4 py-3 text-sm font-medium text-ink-950 transition hover:bg-ink-100">
                    Lihat data resi
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            @endcan
        </x-ui.card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Stok menipis --}}
        <x-ui.card title="Perlu Direstok" subtitle="Stok pada atau di bawah batas minimum">
            <div class="space-y-4">
                @forelse ($lowStockProducts as $product)
                    <a href="{{ route('admin.products.show', $product) }}" class="block">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <x-ui.sku :value="$product->sku" :label="false" />
                                <span class="truncate text-sm font-medium text-ink-800">{{ $product->name }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold {{ $product->isOutOfStock() ? 'text-red-600' : 'text-amber-600' }}">
                                {{ $product->stock }}/{{ $product->min_stock }}
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full {{ $product->isOutOfStock() ? 'bg-red-500' : 'bg-amber-500' }}"
                                 style="width: {{ $product->min_stock > 0 ? min(100, round($product->stock / $product->min_stock * 100)) : 100 }}%"></div>
                        </div>
                    </a>
                @empty
                    <div class="py-6 text-center">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <x-icon name="check-circle" class="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-ink-950">Semua stok aman</p>
                        <p class="mt-0.5 text-xs text-ink-500">Tidak ada barang di bawah batas minimum.</p>
                    </div>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    {{-- Pintasan --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @php
            $shortcuts = [
                ['label' => 'Barang & Stok', 'desc' => 'Tambah barang & kartu stok', 'icon' => 'box', 'route' => 'admin.products.index', 'can' => 'products.view'],
                ['label' => 'Barang Masuk', 'desc' => 'Penerimaan dari pemasok', 'icon' => 'login', 'route' => 'admin.inbounds.index', 'can' => 'inbounds.view'],
                ['label' => 'Barang Keluar', 'desc' => 'Pengiriman & marketplace', 'icon' => 'logout', 'route' => 'admin.outbounds.index', 'can' => 'outbounds.view'],
                ['label' => 'Pengguna', 'desc' => 'Akun & hak akses', 'icon' => 'users', 'route' => 'admin.users.index', 'can' => 'users.view'],
            ];
        @endphp

        @foreach ($shortcuts as $shortcut)
            @can($shortcut['can'])
                <a href="{{ route($shortcut['route']) }}"
                   class="group flex items-center gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-ink-200 hover:shadow-lift">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-ink-50 text-ink-950 ring-1 ring-ink-100 transition group-hover:bg-ink-950 group-hover:text-white">
                        <x-icon :name="$shortcut['icon']" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ $shortcut['label'] }}</p>
                        <p class="truncate text-xs text-ink-500">{{ $shortcut['desc'] }}</p>
                    </div>
                    <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-ink-300 transition group-hover:translate-x-0.5 group-hover:text-ink-950" />
                </a>
            @endcan
        @endforeach
    </div>
</x-app-layout>
