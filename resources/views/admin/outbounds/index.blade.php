<x-app-layout title="Barang Keluar">
    <x-ui.page-header title="Barang Keluar" icon="logout"
                      subtitle="Dokumen pengiriman barang, termasuk pesanan marketplace.">
        <x-slot name="actions">
            @can('outbounds.create')
                <x-ui.button :href="route('admin.outbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="outbound" />

    <form method="GET" action="{{ route('admin.outbounds.index') }}" data-auto-submit
          class="mb-5 flex flex-col gap-3 rounded-2xl border border-ink-100 bg-white p-4 shadow-card">
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-ink-300">
                <x-icon name="search" class="h-4 w-4" />
            </span>
            <x-text-input type="search" name="search" :value="request('search')"
                          placeholder="Cari nomor dokumen, penerima, atau resi..." class="pl-10" />
        </div>

        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <x-ui.select name="type" class="sm:w-44">
                <option value="">Semua jenis</option>
                <option value="regular" @selected(request('type') === 'regular')>Reguler</option>
                <option value="marketplace" @selected(request('type') === 'marketplace')>Marketplace</option>
            </x-ui.select>

            <x-ui.select name="marketplace" class="sm:w-40">
                <option value="">Semua toko</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace }}" @selected(request('marketplace') === $marketplace)>{{ $marketplace }}</option>
                @endforeach
            </x-ui.select>

            {{-- Pilihannya persis sama dengan badge di kolom Status, satu lawan
                 satu. Sebelumnya hanya ada Draft dan Terkirim, sehingga paket
                 yang menunggu persetujuan atau ditolak tidak bisa dicari sama
                 sekali, dan "Draft" ikut memuat paket yang sebenarnya sudah
                 siap dikirim. --}}
            <x-ui.select name="status" class="col-span-2 sm:col-span-1 sm:w-48">
                <option value="">Semua status</option>
                @foreach (App\Models\Outbound::stages() as $value => $stage)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $stage['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>

        {{-- Rentang tanggal berbaris sendiri: kolomnya membawa pintasan periode
             di bawahnya, dan itu tidak muat disisipkan di antara dropdown. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.date-filter label="Tanggal dokumen" />

            <div class="flex items-center gap-2">
                <x-ui.button type="submit" variant="secondary" icon="filter" class="flex-1 sm:flex-none">Terapkan</x-ui.button>
                @if (request()->hasAny(['search', 'status', 'type', 'marketplace', 'from', 'to']))
                    <x-ui.button :href="route('admin.outbounds.index')" variant="ghost" size="icon" title="Reset filter">
                        <x-icon name="refresh" class="h-4 w-4" />
                    </x-ui.button>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-2xl border border-ink-100 bg-white shadow-card">
        @if ($outbounds->isEmpty())
            <x-ui.empty-state icon="logout" title="Belum ada dokumen"
                              description="Buat dokumen barang keluar untuk mencatat pengiriman.">
                @can('outbounds.create')
                    <x-slot name="action">
                        <x-ui.button :href="route('admin.outbounds.create')" icon="plus">Buat Dokumen</x-ui.button>
                    </x-slot>
                @endcan
            </x-ui.empty-state>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-ink-100 text-left">
                    <thead class="bg-ink-50/60">
                        <tr>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Dokumen</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Penerima</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Jenis</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Total Unit</th>
                            <th class="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-ink-500">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-ink-500">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-50">
                        @foreach ($outbounds as $outbound)
                            <tr class="transition hover:bg-ink-50/50">
                                <td class="px-6 py-4">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $outbound->code }}</p>
                                    <p class="text-xs text-ink-400">{{ $outbound->date->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="text-sm text-ink-800">{{ $outbound->recipient }}</p>
                                    @if ($outbound->tracking_number)
                                        <p class="font-mono text-[11px] text-ink-400">{{ $outbound->tracking_number }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if ($outbound->isMarketplace())
                                        <x-ui.badge variant="dark" icon="sparkles">{{ $outbound->marketplace }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="outline">Reguler</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-ink-950">
                                    {{ number_format((int) $outbound->items_sum_quantity, 0, ',', '.') }}
                                </td>
                                <td class="px-6 py-4">
                                    {{-- Tahap, bukan kolom status: lihat Outbound::stage(). --}}
                                    <x-ui.badge :variant="$outbound->stageVariant()" :icon="$outbound->stageIcon()">
                                        {{ $outbound->stageLabel() }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($outbound->isEditable() && $outbound->isMarketplace())
                                            @can('outbounds.scan')
                                                <a href="{{ route('admin.outbounds.scan', $outbound) }}" title="Verifikasi scan"
                                                   class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-ink-950 px-3 text-xs font-medium text-white transition hover:bg-ink-800">
                                                    <x-icon name="search" class="h-3.5 w-3.5" /> Scan
                                                </a>
                                            @endcan
                                        @endif
                                        <a href="{{ route('admin.outbounds.show', $outbound) }}" title="Detail"
                                           class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                            <x-icon name="eye" class="h-4 w-4" />
                                        </a>
                                        @can('outbounds.update')
                                            @if ($outbound->isEditable())
                                                <a href="{{ route('admin.outbounds.edit', $outbound) }}" title="Ubah"
                                                   class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-950">
                                                    <x-icon name="pencil" class="h-4 w-4" />
                                                </a>
                                            @endif
                                        @endcan
                                        @can('outbounds.delete')
                                            @if ($outbound->isEditable())
                                                <x-ui.confirm-delete :action="route('admin.outbounds.destroy', $outbound)"
                                                                     title="Hapus dokumen ini?"
                                                                     :description="'Draft '.$outbound->code.' akan dihapus permanen.'" />
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-ink-50 md:hidden">
                @foreach ($outbounds as $outbound)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-ink-950">{{ $outbound->code }}</p>
                                <p class="truncate text-xs text-ink-400">
                                    {{ $outbound->date->translatedFormat('d M Y') }} &middot; {{ $outbound->recipient }}
                                </p>
                            </div>
                            {{-- Badge yang sama persis dengan tabel: dokumen yang
                                 sama tidak boleh bercerita berbeda hanya karena
                                 layarnya lebih sempit. --}}
                            <x-ui.badge :variant="$outbound->stageVariant()" :icon="$outbound->stageIcon()">
                                {{ $outbound->stageLabel() }}
                            </x-ui.badge>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($outbound->isMarketplace())
                                <x-ui.badge variant="dark" icon="sparkles">{{ $outbound->marketplace }}</x-ui.badge>
                            @endif
                            <x-ui.badge variant="outline">{{ (int) $outbound->items_sum_quantity }} unit</x-ui.badge>
                        </div>

                        <div class="mt-3 flex items-center justify-end gap-1 border-t border-ink-50 pt-3">
                            @if ($outbound->isEditable() && $outbound->isMarketplace())
                                @can('outbounds.scan')
                                    <x-ui.button :href="route('admin.outbounds.scan', $outbound)" size="sm" icon="search">Scan</x-ui.button>
                                @endcan
                            @endif
                            <x-ui.button :href="route('admin.outbounds.show', $outbound)" variant="secondary" size="sm" icon="eye">Detail</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$outbounds" />
        @endif
    </div>
</x-app-layout>
