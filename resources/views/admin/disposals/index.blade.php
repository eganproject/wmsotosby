{{--
    Barang rusak: saldonya dan riwayat penanganannya dalam satu halaman.

    Dua hal yang berbeda tetapi selalu ditanyakan bersamaan — "ada apa saja
    yang rusak" dan "sudah diapakan" — jadi tidak dipisah ke dua menu.
--}}
<x-app-layout title="Barang Rusak">
    <x-ui.page-header title="Barang Rusak" icon="trash"
                      subtitle="Saldo barang rusak beserta dokumen penanganannya.">
        <x-slot name="actions">
            @can('disposals.create')
                <x-ui.button :href="route('admin.disposals.create')" icon="plus">Tangani Barang Rusak</x-ui.button>
            @endcan
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs group="stock" />

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-3">
        <x-ui.stat-card label="Unit Rusak" :value="number_format($summary['units'], 0, ',', '.')" icon="warning" accent
                        hint="Masih tersimpan di gudang" />
        <x-ui.stat-card label="Jenis Barang" :value="$summary['skus']" icon="box" hint="SKU yang punya stok rusak" />
        <x-ui.stat-card label="Menunggu Persetujuan" :value="$summary['pending']" icon="clock"
                        hint="Dokumen penanganan" />
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
        {{-- Saldo --}}
        <x-ui.card title="Saldo Barang Rusak" subtitle="Unit rusak yang masih ada fisiknya di gudang." padding="p-0">
            @if ($damaged->isEmpty())
                <x-ui.empty-state icon="check-circle" title="Tidak ada barang rusak"
                                  description="Barang rusak masuk ke sini otomatis dari penerimaan retur." />
            @else
                <ul class="divide-y divide-ink-50">
                    @foreach ($damaged as $product)
                        <li class="flex items-center gap-3 px-5 py-3.5">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('admin.products.show', $product) }}"
                                   class="truncate text-sm font-medium text-ink-950 underline-offset-4 hover:underline">
                                    {{ $product->name }}
                                </a>
                                <div class="mt-1"><x-ui.sku :value="$product->sku" /></div>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums text-red-600">
                                    {{ number_format($product->damaged_stock, 0, ',', '.') }}
                                </p>
                                <p class="text-[11px] text-ink-400">{{ $product->unit }} rusak</p>
                            </div>

                            <div class="w-20 shrink-0 text-right">
                                <p class="text-xs tabular-nums text-ink-500">{{ number_format($product->stock, 0, ',', '.') }}</p>
                                <p class="text-[10px] uppercase tracking-wider text-ink-400">layak jual</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <x-ui.pagination :paginator="$damaged" />
            @endif
        </x-ui.card>

        {{-- Dokumen --}}
        <x-ui.card title="Riwayat Penanganan" subtitle="Dibuang, dikembalikan ke pemasok, atau diperbaiki." padding="p-0">
            @if ($disposals->isEmpty())
                <x-ui.empty-state icon="document" title="Belum ada penanganan"
                                  description="Buat dokumen untuk mengeluarkan barang rusak dari gudang." />
            @else
                <ul class="divide-y divide-ink-50">
                    @foreach ($disposals as $disposal)
                        <li>
                            <a href="{{ route('admin.disposals.show', $disposal) }}"
                               class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-ink-50/60">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-sm font-medium text-ink-950">{{ $disposal->code }}</p>
                                    <p class="truncate text-[11px] text-ink-400">
                                        {{ $disposal->date->translatedFormat('d M Y') }}
                                        &middot; {{ $disposal->actionLabel() }}
                                        &middot; {{ (int) $disposal->items_sum_quantity }} unit
                                    </p>
                                </div>

                                <x-ui.badge :variant="$disposal->statusVariant()" :icon="$disposal->statusIcon()">
                                    {{ $disposal->statusLabel() }}
                                </x-ui.badge>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <x-ui.pagination :paginator="$disposals" />
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
